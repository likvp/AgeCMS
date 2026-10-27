<?php
declare(strict_types=1);

use AgeCMS\{Actor, CircuitBreaker, CircuitBreakerException, Config, Database, EventBus, FrozenException, Pages, Queue};

final class CircuitBreakerTest extends TestCase
{
    private function siteWithPages(int $count): void
    {
        $this->freshSite();
        $setup = Actor::cli('setup')->withSession('setup-session');
        for ($i = 1; $i <= $count; $i++) {
            $this->createPage('p' . $i, [], $setup);
        }
    }

    private function edit(Actor $actor, int $i): void
    {
        Queue::submit($actor, 'update_page', ['slug' => 'p' . $i, 'fields' => ['meta_title' => 'edited ' . $i]]);
    }

    public function testFifteenPercentIsAllowedSixteenTrips(): void
    {
        $this->siteWithPages(100);
        $agent = Actor::agent('seo', 'editor', 'agent-1');
        for ($i = 1; $i <= 15; $i++) {
            $this->edit($agent, $i);
        }
        $this->assertSame(false, CircuitBreaker::isFrozen(), '15 of 100 is exactly 15%, allowed');

        $e = $this->assertThrows(CircuitBreakerException::class, fn() => $this->edit($agent, 16));
        $this->assertContains('16 of 100', $e->getMessage());
        $this->assertSame('', Pages::doc('p16')['meta_title'], 'the tripping change was rolled back');
        $this->assertSame('edited 15', Pages::doc('p15')['meta_title'], 'earlier changes stay (one step back only)');
        $this->assertTrue(CircuitBreaker::isFrozen());
    }

    public function testFrozenBlocksEveryoneUntilUnfrozen(): void
    {
        $this->siteWithPages(10);
        $agent = Actor::agent('bot', 'editor', 'agent-2');
        $this->assertThrows(CircuitBreakerException::class, function () use ($agent) {
            for ($i = 1; $i <= 10; $i++) {
                $this->edit($agent, $i);
            }
        });
        $this->assertThrows(FrozenException::class, fn() => $this->edit(Actor::agent('other', 'editor', 'agent-3'), 1), 'since');
        $this->assertThrows(FrozenException::class, fn() => $this->edit($this->admin(), 1));
        CircuitBreaker::unfreeze();
        $this->edit(Actor::agent('other', 'editor', 'agent-4'), 1);
        $this->assertSame('edited 1', Pages::doc('p1')['meta_title']);
    }

    public function testEditingTheSamePageRepeatedlyCountsOnce(): void
    {
        $this->siteWithPages(20);
        $agent = Actor::agent('bot', 'editor', 'agent-5');
        for ($i = 0; $i < 30; $i++) {
            Queue::submit($agent, 'update_page', ['slug' => 'p1', 'fields' => ['meta_title' => "round {$i}"]]);
        }
        $this->assertSame(false, CircuitBreaker::isFrozen());
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM session_pages WHERE session_id = 'agent-5'"));
    }

    public function testSmallSitesKeepAFloorOfThreePages(): void
    {
        $this->siteWithPages(5);
        $agent = Actor::agent('bot', 'editor', 'agent-6');
        $this->edit($agent, 1);
        $this->edit($agent, 2);
        $this->assertSame(false, CircuitBreaker::isFrozen(), '2 of 5 is 40% but below the floor of 3');
        $this->assertThrows(CircuitBreakerException::class, fn() => $this->edit($agent, 3));
    }

    public function testNewPagesDoNotCountAsModifiedPagesButHaveTheirOwnCap(): void
    {
        $this->siteWithPages(4);
        Config::set('circuit_breaker.max_creates', 25);
        $migrator = Actor::agent('migrator', 'editor', 'agent-7');
        for ($i = 1; $i <= 25; $i++) {
            $this->createPage('new' . $i, [], $migrator);
        }
        $this->assertSame(false, CircuitBreaker::isFrozen(), 'importing 25 pages into a 4 page site is a migration, not an accident');
        $this->assertThrows(CircuitBreakerException::class, fn() => $this->createPage('new26', [], $migrator), 'created more than 25');
        $this->assertSame(null, Pages::row('new26'));
        $this->assertTrue(CircuitBreaker::isFrozen());
    }

    public function testSessionsAreIndependent(): void
    {
        $this->siteWithPages(100);
        for ($s = 0; $s < 3; $s++) {
            $agent = Actor::agent('bot', 'editor', 'session-' . $s);
            for ($i = 1; $i <= 10; $i++) {
                $this->edit($agent, $s * 10 + $i);
            }
        }
        $this->assertSame(false, CircuitBreaker::isFrozen());
    }

    public function testTripRaisesAnAlertEvent(): void
    {
        $this->siteWithPages(10);
        $events = [];
        EventBus::listen('circuit_breaker.tripped', function (array $payload) use (&$events) { $events[] = $payload; });
        $agent = Actor::agent('hallucinator', 'editor', 'agent-8');
        try {
            for ($i = 1; $i <= 10; $i++) {
                $this->edit($agent, $i);
            }
        } catch (CircuitBreakerException) {
        }
        $this->assertSame(1, count($events));
        $this->assertSame('hallucinator', $events[0]['actor']);
        $log = (string)file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json');
        $this->assertContains('circuit_breaker.tripped', $log);
    }
}
