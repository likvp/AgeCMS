<?php
declare(strict_types=1);

use AgeCMS\{Actor, CircuitBreaker, CircuitBreakerException, Content, Database, ForbiddenException, FrozenException, Pages, Schema};

/**
 * Undoing what one session created (CircuitBreaker::claimSession, allowRollback, Content::rollbackSession): a big import can
 * be removed again after its circuit breaker session has expired, by a person with system.manage or by the agent token
 * that started it, never by another token. Pages changed by others after the import stay.
 */
final class SessionRollbackTest extends TestCase
{
    /** A site of 10 pages and an import session that created $count more (flat addresses imp-1..). */
    private function importedSite(Actor $importer, int $count): void
    {
        $this->freshSite();
        $setup = Actor::cli('setup')->withSession('setup-session');
        for ($i = 1; $i <= 10; $i++) {
            $this->createPage('old-' . $i, [], $setup);
        }
        if ($importer->type !== 'agent') {
            CircuitBreaker::allowBulkCreate($importer, $count, 'тестовый импорт');
        }
        for ($i = 1; $i <= $count; $i++) {
            $this->createPage('imp-' . $i, [], $importer);
        }
    }

    /** The session row is a day and an hour old: the circuit breaker forgets it on the next write. */
    private function age(string $session): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE agent_sessions SET created_at = ? WHERE id = ?')->execute([gmdate('Y-m-d\\TH:i:s\\Z', time() - 90000), $session]));
    }

    /** @return list<string> */
    private static function slugs(int $from, int $to): array
    {
        return array_map(static fn(int $i): string => 'imp-' . $i, range($from, $to));
    }

    public function testAPersonUndoesABigImportADayLaterWithoutTrippingTheBreaker(): void
    {
        $import = Actor::human('owner', 'admin')->withSession('imp-1');
        $this->importedSite($import, 40);
        $this->age('imp-1');

        // Somebody edits one imported page, somebody else puts a child under another one, and a third page has nothing to do with the import.
        $editor = Actor::human('editor', 'editor')->withSession('h-editor');
        Content::updatePage($editor, 'imp-2', ['title' => 'Правка редактора']);
        $this->createPage('imp-4/child', ['parent_slug' => 'imp-4'], $editor);

        $allowed = CircuitBreaker::allowRollback(Actor::human('owner', 'admin')->withSession('h-owner'), 'imp-1', 'отмена тестового импорта');
        $this->assertSame(['imp-1', 40], [$allowed['session'], $allowed['pages']]);
        $this->assertContains('Разрешена отмена сессии imp-1', (string)Database::value("SELECT action_desc FROM revisions WHERE page_slug = '@system' ORDER BY id DESC LIMIT 1"));

        // The job goes on under its own session (later requests, the background tick).
        $r = Content::rollbackSession($import, 'imp-1', array_merge(self::slugs(1, 40), ['old-1', 'imp-4/child', 'gone']));
        $this->assertSame(38, count($r['deleted']), json_encode($r['kept'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(['gone'], $r['missing']);
        $this->assertSame('страницу меняли после создания', $r['kept']['imp-2']);
        $this->assertContains('дочерние', $r['kept']['imp-4']);
        $this->assertSame('страницу по этому адресу создала не эта сессия', $r['kept']['old-1']);
        $this->assertSame('страницу по этому адресу создала не эта сессия', $r['kept']['imp-4/child']);
        $this->assertSame(false, CircuitBreaker::isFrozen(), '38 deletions on a 50 page site do not count towards the 15%');
        $this->assertSame(null, Pages::row('imp-1'));
        $this->assertTrue(Pages::row('imp-2') !== null && Pages::row('old-1') !== null && Pages::row('imp-4/child') !== null);
        $this->assertTrue(in_array('imp-1', array_column(Pages::deleted(), 'slug'), true), 'deleted pages are in the trash');
        $this->assertSame(['owner', 'imp-1', Content::DELETE_DESC], array_values(Database::row("SELECT actor_name, session_id, action_desc FROM revisions WHERE page_slug = 'imp-1' ORDER BY id DESC LIMIT 1")));
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM session_pages WHERE page_slug = 'imp-1'"), 'nothing was counted');

        Content::restorePage(Actor::cli('owner'), 'imp-1');
        $this->assertTrue(Pages::row('imp-1') !== null, 'a page undone this way comes back from the trash');
    }

    public function testWithoutThePermissionTheUsualLimitsStay(): void
    {
        $import = Actor::human('owner', 'admin')->withSession('imp-1');
        $this->importedSite($import, 40);
        $this->age('imp-1');
        $this->assertThrows(ForbiddenException::class, fn() => Content::rollbackSession($import, 'imp-1', ['imp-1']), 'allowRollback');
        $this->assertTrue(Pages::row('imp-1') !== null);

        // The old way: a day later the deletions count, and the breaker trips on the share of a 50 page site.
        $e = $this->assertThrows(CircuitBreakerException::class, function () use ($import): void {
            foreach (self::slugs(1, 40) as $slug) {
                Content::deletePage($import, $slug);
            }
        });
        $this->assertContains('session changed 8 of 50', $e->getMessage());
        CircuitBreaker::unfreeze();

        $this->assertThrows(ForbiddenException::class, fn() => CircuitBreaker::allowRollback(Actor::human('ed', 'editor'), 'imp-1', 'нет права'), 'system.manage');
        $this->assertThrows(\AgeCMS\ValidationException::class, fn() => CircuitBreaker::allowRollback(Actor::cli('owner'), 'imp-1', '  '), 'reason');
        $this->assertThrows(\AgeCMS\ValidationException::class, fn() => CircuitBreaker::allowRollback(Actor::cli('owner'), "imp 1'", 'x'), 'session id');

        // Somebody else, outside the session and not the one who asked, cannot use a permission given to another.
        CircuitBreaker::allowRollback(Actor::cli('owner'), 'imp-1', 'отмена');
        $this->assertThrows(ForbiddenException::class, fn() => Content::rollbackSession(Actor::agent('bot', 'admin', 'tok-3-2026103110'), 'imp-1', ['imp-20']));
        $this->assertSame(['imp-20'], Content::rollbackSession(Actor::cli('owner'), 'imp-1', ['imp-20'])['deleted'], 'the one who asked may');

        // The permission runs out after a week.
        Database::write(static fn(PDO $pdo) => $pdo->exec("UPDATE session_owners SET rollback_at = '2020-01-01T00:00:00Z'"));
        $this->assertThrows(ForbiddenException::class, fn() => Content::rollbackSession($import, 'imp-1', ['imp-21']));

        // Frozen writes stay frozen.
        CircuitBreaker::allowRollback(Actor::cli('owner'), 'imp-1', 'отмена');
        CircuitBreaker::freeze('проверка');
        $this->assertThrows(FrozenException::class, fn() => Content::rollbackSession($import, 'imp-1', ['imp-21']));
        $this->assertTrue(Pages::row('imp-21') !== null);
    }

    public function testAnAgentUndoesOnlyWhatItsOwnTokenStarted(): void
    {
        // The import was started by the agent of token 7; its pages are written under the job session imp-9.
        $token7 = Actor::agent('bot', 'admin', 'tok-7-2026103110');
        $job = Actor::agent('bot', 'admin', 'imp-9');
        $this->freshSite();
        for ($i = 1; $i <= 10; $i++) {
            $this->createPage('old-' . $i, [], Actor::cli('setup')->withSession('setup'));
        }
        CircuitBreaker::claimSession($token7, 'imp-9');
        CircuitBreaker::claimSession($token7, 'imp-9');
        for ($i = 1; $i <= 20; $i++) {
            $this->createPage('imp-' . $i, [], $job);
        }
        $this->age('imp-9');

        $later = Actor::agent('bot', 'admin', 'tok-7-2026103118');
        $this->assertTrue(CircuitBreaker::ownsSession($later, 'imp-9'), 'the same token, another hour');
        $this->assertTrue(CircuitBreaker::ownsSession($later, 'tok-7-2026103101'), 'its own hourly session');
        $other = Actor::agent('bot', 'admin', 'tok-8-2026103118');
        $this->assertSame(false, CircuitBreaker::ownsSession($other, 'imp-9'), 'another token with the same name');
        $this->assertSame(false, CircuitBreaker::ownsSession($other, 'tok-7-2026103118'));
        $this->assertSame(false, CircuitBreaker::ownsSession(Actor::agent('bot', 'admin'), 'imp-9'), 'no token session, no ownership');

        $e = $this->assertThrows(ForbiddenException::class, fn() => CircuitBreaker::allowRollback($other, 'imp-9', 'чужой импорт'));
        $this->assertContains('not started by this agent token', $e->getMessage());
        $this->assertThrows(ForbiddenException::class, fn() => CircuitBreaker::claimSession($other, 'imp-9'), 'someone else');
        $this->assertThrows(ForbiddenException::class, fn() => Content::rollbackSession($job, 'imp-9', ['imp-1']), 'allowRollback');

        CircuitBreaker::allowRollback($later, 'imp-9', 'отмена своего импорта');
        $r = Content::rollbackSession($job, 'imp-9', self::slugs(1, 20));
        $this->assertSame(20, count($r['deleted']));
        $this->assertSame(false, CircuitBreaker::isFrozen(), '20 of 30 pages, no trip');

        // A session of a person, or a session nobody claimed, is not the agent's.
        $person = Actor::human('owner', 'admin')->withSession('imp-10');
        CircuitBreaker::claimSession(Actor::human('owner', 'admin'), 'imp-10');
        $this->createPage('person-1', [], $person);
        $this->assertThrows(ForbiddenException::class, fn() => CircuitBreaker::allowRollback($later, 'imp-10', 'чужое'));
        $this->assertThrows(ForbiddenException::class, fn() => CircuitBreaker::allowRollback($later, 'imp-11', 'ничьё'));
        // A session that already has changes of somebody else cannot be claimed afterwards.
        $this->assertThrows(ForbiddenException::class, fn() => CircuitBreaker::claimSession($token7, 'setup'));
    }

    public function testTheTableComesWithAnUpgrade(): void
    {
        $this->freshSite();
        Database::write(static fn(PDO $pdo) => $pdo->exec('DROP TABLE session_owners'));
        Database::pdo()->exec('PRAGMA user_version = 6');
        Database::reset();
        $this->assertSame(Schema::latest(), (int)Database::value('PRAGMA user_version'));
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM session_owners'));
    }
}
