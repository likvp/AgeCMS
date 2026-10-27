<?php
declare(strict_types=1);

use AgeCMS\{Background, Database, EventBus, Queue};

final class BackgroundTest extends TestCase
{
    private function stamp(): string
    {
        return Queue::dir() . '/.tick-modules';
    }

    private function log(): string
    {
        return (string)@file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json');
    }

    public function testTickRaisesTheEventOnceInThirtySeconds(): void
    {
        $this->freshSite();
        $seen = [];
        EventBus::listen('background.tick', function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        });
        $this->assertTrue(Background::tick(), 'first tick fires');
        $this->assertSame(false, Background::tick(), 'throttled');
        $this->assertSame(1, count($seen));
        $this->assertTrue(is_int($seen[0]['time']) && $seen[0]['deadline'] > microtime(true), 'payload carries the time and a deadline');

        file_put_contents($this->stamp(), (string)(time() - 31));
        $this->assertTrue(Background::tick(), 'fires again after thirty seconds');
        $this->assertSame(2, count($seen));
    }

    public function testTickNeverOverlapsWithARunningTick(): void
    {
        $this->freshSite();
        $fired = 0;
        EventBus::listen('background.tick', function () use (&$fired): void {
            $fired++;
        });
        $held = fopen($this->stamp(), 'c+');
        $this->assertTrue(flock($held, LOCK_EX), 'another request is inside its tick');
        $this->assertSame(false, Background::tick());
        flock($held, LOCK_UN);
        fclose($held);
        $this->assertSame(0, $fired);
        $this->assertTrue(Background::tick(), 'free again once the other tick has finished');
    }

    public function testListenerFailureIsLoggedAndDoesNotStopTheTick(): void
    {
        $this->freshSite();
        $later = false;
        EventBus::listen('background.tick', function (): void {
            throw new RuntimeException('mailing broke');
        });
        EventBus::listen('background.tick', function () use (&$later): void {
            $later = true;
        });
        $this->assertTrue(Background::tick());
        $this->assertTrue($later, 'the next listener still runs');
        $this->assertContains('mailing broke', $this->log());
    }

    public function testRunReportsTheTickEvenWithAnEmptyQueue(): void
    {
        $this->freshSite();
        $result = Background::run(true);
        $this->assertSame(true, $result['ticked'] ?? null);
        $this->assertSame(0, $result['jobs']);
        $this->assertSame(null, Background::run(true), 'throttled and nothing queued: nothing due');
    }

    public function testDeclarativeWebhooksAreNotCalledForTheTick(): void
    {
        $this->freshSite();
        $config = json_encode(['event' => 'background.tick', 'webhook_url' => 'https://hooks.example.test/x'], JSON_THROW_ON_ERROR);
        Database::write(static fn(PDO $pdo) => $pdo->prepare("INSERT INTO settings (key, value) VALUES ('integration_ticker', ?)")->execute([$config]));
        $this->assertTrue(Background::tick());
        $this->assertSame(false, str_contains($this->log(), 'event.webhook_failed'), 'no HTTP call every thirty seconds');
    }
}
