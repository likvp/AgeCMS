<?php
declare(strict_types=1);

use AgeCMS\{Actor, Api, Audit, Auth, CircuitBreaker, Database, Revisions};

final class AuditTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function entries(string $scope): array
    {
        return Database::rows('SELECT actor_type, actor_name, action_desc FROM revisions WHERE page_slug = ? ORDER BY id', [$scope]);
    }

    private function allText(): string
    {
        return implode("\n", array_column(Database::rows('SELECT action_desc FROM revisions'), 'action_desc'));
    }

    public function testUserAndTokenChangesReachTheHistoryWithoutSecrets(): void
    {
        $this->freshSite();
        Audit::as(Actor::human('owner'));
        $id = Auth::createUser('anna', 'Secret-Passw0rd-1', 'editor', 'anna@example.com');
        Auth::changeRole($id, 'viewer', 0);
        Auth::resetPassword($id, 'Another-Passw0rd-2');
        Auth::setBlocked($id, true, 0);
        Auth::setBlocked($id, false, 0);
        $plain = Auth::createToken('bot', 'editor', 30, $id);
        Auth::revokeToken('bot');
        Auth::deleteUser($id, 0);

        $users = array_column($this->entries(Audit::USERS), 'action_desc');
        $this->assertSame(6, count($users));
        $this->assertContains('Создан пользователь anna', $users[0]);
        $this->assertContains('viewer', $users[1]);
        $this->assertContains('новый пароль', $users[2]);
        $this->assertContains('Заблокирован', $users[3]);
        $this->assertContains('Разблокирован', $users[4]);
        $this->assertContains('Удалён пользователь anna', $users[5]);
        $tokens = array_column($this->entries(Audit::TOKENS), 'action_desc');
        $this->assertSame(2, count($tokens));
        $this->assertContains('Выдан токен «bot»', $tokens[0]);
        $this->assertContains('Отозван токен «bot»', $tokens[1]);
        $this->assertSame('owner', $this->entries(Audit::USERS)[0]['actor_name']);

        $all = $this->allText();
        foreach (['Secret-Passw0rd-1', 'Another-Passw0rd-2', $plain, 'anna@example.com'] as $secret) {
            $this->assertNotContains($secret, $all, 'no secret in the history');
        }
    }

    public function testAgentActionsAreSignedByTheAgent(): void
    {
        $this->freshSite();
        $agent = Actor::agent('writer-bot', 'admin');
        Api::call($agent, 'clear_cache', []);
        $row = $this->entries(Audit::SYSTEM)[0];
        $this->assertSame('agent', $row['actor_type']);
        $this->assertSame('writer-bot', $row['actor_name']);
        $this->assertContains('Очищен кэш', $row['action_desc']);
    }

    public function testFreezeAndUnfreezeAreWritten(): void
    {
        $this->freshSite();
        CircuitBreaker::freeze('test reason');
        CircuitBreaker::unfreeze();
        $rows = $this->entries(Audit::SYSTEM);
        $this->assertSame(2, count($rows));
        $this->assertContains('test reason', $rows[0]['action_desc']);
        $this->assertContains('Снята заморозка', $rows[1]['action_desc']);
    }

    public function testCoreUpdateDoneByAnOlderCoreIsAddedLater(): void
    {
        $this->freshSite();
        file_put_contents(AGECMS_DATA . '/update-last.json', json_encode(['ok' => true, 'from' => '1.1.1', 'to' => '1.2.0', 'by' => 'lerk', 'error' => '', 'at' => 1793000000]));
        Audit::catchUp();
        Audit::catchUp();
        $rows = $this->entries(Audit::CORE);
        $this->assertSame(1, count($rows), 'once only');
        $this->assertContains('1.1.1 -> 1.2.0', $rows[0]['action_desc']);
        $this->assertSame('lerk', $rows[0]['actor_name']);
    }

    public function testManyEntriesDoNotTripTheCircuitBreakerAndTheChainHolds(): void
    {
        $this->freshSite();
        $this->createPage('about');
        for ($i = 0; $i < 30; $i++) {
            Audit::record(Audit::SYSTEM, "Запись {$i}");
        }
        $this->assertTrue(!CircuitBreaker::isFrozen());
        $this->assertTrue(Revisions::verifyChain()['ok'] ?? true);
        $this->assertSame('Ядро', Audit::label(Audit::CORE));
        $this->assertSame(null, Audit::label('@settings'));
    }
}
