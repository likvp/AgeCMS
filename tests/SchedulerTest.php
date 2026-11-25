<?php
declare(strict_types=1);

use AgeCMS\{Audit, Background, Config, Cron, Database, EventBus, GiveUpException, NotFoundException, RetryLaterException, ScheduledTask, Scheduler, Schema, SkillRegistry, ValidationException};

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

final class SchedulerTest extends HttpCase
{
    /** @var list<array<string,mixed>> */
    private array $ran = [];

    private function site(): void
    {
        $this->freshSite();
        $this->ran = [];
        Scheduler::on('bus', 'remind', function (ScheduledTask $t): void {
            $this->ran[] = ['key' => $t->key, 'payload' => $t->payload, 'attempt' => $t->attempt, 'late' => $t->late(), 'due' => $t->dueAt];
        });
    }

    /** Pretends that $seconds have passed for a task. */
    private function age(string $key, int $seconds, string $module = 'bus'): void
    {
        Database::pdo()->prepare('UPDATE scheduled_tasks SET due_at = due_at - ?, run_at = run_at - ?, expires_at = expires_at - ?, lease_until = MAX(0, lease_until - ?), last_run_at = last_run_at - ?, finished_at = finished_at - ? WHERE module = ? AND task_key = ?')
            ->execute([$seconds, $seconds, $seconds, $seconds, $seconds, $seconds, $module, $key]);
    }

    private function row(string $key, string $module = 'bus'): ?array
    {
        return Database::row('SELECT * FROM scheduled_tasks WHERE module = ? AND task_key = ?', [$module, $key]);
    }

    /** A handler that always fails. */
    private function failing(Throwable $e): void
    {
        Scheduler::on('bus', 'fail', function (ScheduledTask $t) use ($e): void {
            $this->ran[] = ['key' => $t->key, 'attempt' => $t->attempt];
            throw $e;
        });
    }

    // ---- time, replacing, cancelling --------------------------------------------------------------------------

    public function testATaskRunsOnceAndNotBeforeItsTime(): void
    {
        $this->site();
        $this->assertTrue(Scheduler::at('bus', 'trip:1', 'remind', time() + 3600, ['trip' => 1, 'text' => 'Завтра рейс']));
        $this->assertSame(['done' => 0, 'failed' => 0, 'expired' => 0], Scheduler::run());
        $this->assertSame([], $this->ran, 'an hour is left');
        $this->assertSame(false, Scheduler::due());

        $this->age('trip:1', 3660);
        $this->assertSame(true, Scheduler::due());
        $this->assertSame(1, Scheduler::run()['done']);
        $this->assertSame(['trip' => 1, 'text' => 'Завтра рейс'], $this->ran[0]['payload']);
        $this->assertTrue($this->ran[0]['late'] >= 60 && $this->ran[0]['late'] < 70);
        $this->assertSame(0, Scheduler::run()['done'], 'done once');
        $this->assertSame('done', Scheduler::find('bus', 'trip:1')['status']);
        $this->assertSame(1, count($this->ran));
    }

    public function testTheSameKeyReplacesTheTaskAndCancelRemovesIt(): void
    {
        $this->site();
        Scheduler::at('bus', 'trip:1', 'remind', time() - 10, ['v' => 1]);
        Scheduler::at('bus', 'trip:1', 'remind', new DateTimeImmutable('-5 seconds'), ['v' => 2]);
        Scheduler::at('other', 'trip:1', 'remind', time() - 10);
        Scheduler::at('bus', 'trip:2', 'remind', time() - 10);
        Scheduler::at('bus', 'trip:20', 'remind', time() - 10);
        $this->assertSame(4, (int)Database::value('SELECT COUNT(*) FROM scheduled_tasks'));
        $this->assertTrue(Scheduler::cancel('bus', 'trip:2'));
        $this->assertSame(false, Scheduler::cancel('bus', 'trip:2'));
        $this->assertSame(1, Scheduler::cancelPrefix('bus', 'trip:20'));
        $this->assertSame(1, Scheduler::run()['done']);
        $this->assertSame([['v' => 2]], array_column($this->ran, 'payload'));
        $this->assertSame('pending', Scheduler::find('other', 'trip:1')['status'], 'the key of another module is another task, and nobody runs it');

        Scheduler::at('bus', 'trip:1', 'remind', time() - 1, ['v' => 3]);
        Scheduler::run();
        $this->assertSame(['v' => 3], $this->ran[1]['payload'], 'a finished task may be set again');
        $this->assertSame(0, Scheduler::cancelPrefix('bus', 'trip'), 'finished tasks are not counted');
        $this->assertTrue(Scheduler::find('bus', 'trip:1') !== null);
    }

    public function testBadArgumentsAreRefused(): void
    {
        $this->site();
        foreach ([
            fn() => Scheduler::at('Bus', 'k', 'remind', time()),
            fn() => Scheduler::at('bus', 'ключ', 'remind', time()),
            fn() => Scheduler::at('bus', 'k', 'bad name', time()),
            fn() => Scheduler::at('bus', 'k', 'remind', time(), [], ['attempts' => 0]),
            fn() => Scheduler::at('bus', 'k', 'remind', time(), [], ['retries' => 3]),
            fn() => Scheduler::at('bus', 'k', 'remind', time(), [], ['retry' => [0]]),
            fn() => Scheduler::at('bus', 'k', 'remind', time(), [], ['expires_at' => 'tomorrow']),
            fn() => Scheduler::at('bus', 'k', 'remind', time(), ['x' => str_repeat('a', 20000)]),
            fn() => Scheduler::every('bus', 'k', 'remind', 10),
            fn() => Scheduler::daily('bus', 'k', 'remind', '9:00'),
            fn() => Scheduler::daily('bus', 'k', 'remind', '09:00', [], ['days' => [0]]),
            fn() => Scheduler::cancelPrefix('bus', ''),
            fn() => Scheduler::on('bus', 'bad name', static fn() => null),
        ] as $i => $call) {
            $this->assertThrows(ValidationException::class, $call, '');
        }
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM scheduled_tasks'));
    }

    // ---- expiry -----------------------------------------------------------------------------------------------

    public function testATaskPastItsExpiryIsNeverRun(): void
    {
        $this->site();
        $events = [];
        EventBus::listen('task.expired', function (array $p) use (&$events): void {
            $events[] = $p['key'];
        });
        $trip = time() + 3600;
        Scheduler::at('bus', 'ok', 'remind', time() - 7200, [], ['expires_at' => $trip]);
        Scheduler::at('bus', 'late', 'remind', time() + 60, [], ['expires_at' => $trip]);
        Scheduler::at('bus', 'default', 'remind', time() + 60);
        Scheduler::at('bus', 'never', 'remind', time() - 30 * 86400, [], ['expires_in' => null]);
        $this->age('late', 3700);
        $this->age('default', 86500);
        $report = Scheduler::run();
        $this->assertSame(2, $report['done'], 'the server came back before the trip: the late reminder still goes, and the one without expiry');
        $this->assertSame(2, $report['expired']);
        $keys = array_column($this->ran, 'key');
        sort($keys);
        $this->assertSame(['never', 'ok'], $keys);
        $this->assertSame('expired', $this->row('late')['status']);
        sort($events);
        $this->assertSame(['default', 'late'], $events);
        $this->assertThrows(AgeCMS\AgeCMSException::class, fn() => Scheduler::retry((int)$this->row('late')['id']));
    }

    public function testATaskThatIsAlreadyTooLateIsNotStoredAndCancelsTheOldOne(): void
    {
        $this->site();
        Scheduler::at('bus', 'trip', 'remind', time() + 60);
        $this->assertSame(false, Scheduler::at('bus', 'trip', 'remind', time() - 7200, [], ['expires_at' => time() - 3600]));
        $this->assertSame(null, $this->row('trip'));
        $this->assertSame(false, Scheduler::at('bus', 'trip', 'remind', time() + 100, [], ['expires_at' => time() + 50]), 'expires before its own time');
    }

    public function testNoAttemptIsMadeAfterTheExpiry(): void
    {
        $this->site();
        $this->failing(new RuntimeException('network'));
        Scheduler::at('bus', 'x', 'fail', time(), [], ['expires_in' => 50]);
        $this->assertSame(1, Scheduler::run()['failed']);
        $this->assertSame('failed', $this->row('x')['status'], 'the next attempt in a minute would be past the expiry');
    }

    // ---- failures ---------------------------------------------------------------------------------------------

    public function testAFailureIsTriedAgainAfterPausesUpToTheLimit(): void
    {
        $this->site();
        $failed = [];
        EventBus::listen('task.failed', function (array $p) use (&$failed): void {
            $failed[] = $p;
        });
        $this->failing(new RuntimeException("cURL: https://api.telegram.org/bot123456789:AAsecret_token-x/sendMessage?token=abc\nfailed"));
        Scheduler::at('bus', 'x', 'fail', time(), ['id' => 5], ['attempts' => 3, 'retry' => [60, 300], 'expires_in' => null]);
        Scheduler::run();
        $row = $this->row('x');
        $this->assertSame('pending', $row['status']);
        $pause = (int)$row['run_at'] - time();
        $this->assertTrue($pause >= 58 && $pause <= 60, "pause {$pause}");
        $this->assertNotContains('AAsecret', $row['last_error']);
        $this->assertNotContains('abc', $row['last_error']);
        $this->assertSame(0, Scheduler::run()['failed'], 'the pause is kept');

        $this->age('x', 61);
        Scheduler::run();
        $pause = (int)$this->row('x')['run_at'] - time();
        $this->assertTrue($pause >= 298 && $pause <= 300, "second pause {$pause}");
        $this->assertSame([], $failed);
        $this->age('x', 301);
        Scheduler::run();
        $this->assertSame('failed', $this->row('x')['status']);
        $this->assertSame([1, 2, 3], array_column($this->ran, 'attempt'));
        $this->assertSame(1, count($failed));
        $this->assertSame('x', $failed[0]['key']);
        $this->assertSame(false, array_key_exists('payload', $failed[0]));
        $this->assertSame(1, count(Scheduler::troubled()));
        $this->assertSame(1, Scheduler::counts()['failed']);
        $this->assertNotContains('AAsecret', (string)file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json'));

        Scheduler::on('bus', 'fail', function (ScheduledTask $t): void {
            $this->ran[] = ['attempt' => $t->attempt];
        });
        Scheduler::retry((int)$this->row('x')['id']);
        $this->assertSame(1, Scheduler::run()['done'], 'the owner pressed "again"');
        $this->assertSame('', $this->row('x')['last_error']);
    }

    public function testTheHandlerChoosesThePauseOrGivesUp(): void
    {
        $this->site();
        $this->failing(new RetryLaterException('429', 17));
        Scheduler::at('bus', 'later', 'fail', time());
        Scheduler::run();
        $pause = (int)$this->row('later')['run_at'] - time();
        $this->assertTrue($pause >= 15 && $pause <= 17, "pause {$pause}");

        $this->failing(new GiveUpException('bot blocked'));
        Scheduler::at('bus', 'never', 'fail', time());
        Scheduler::run();
        $this->assertSame('failed', $this->row('never')['status']);
        $this->assertSame('bot blocked', $this->row('never')['last_error']);
    }

    public function testOneBrokenTaskDoesNotStopTheOthers(): void
    {
        $this->site();
        $this->failing(new Error('broken module'));
        Scheduler::at('bus', 'a', 'fail', time() - 5);
        Scheduler::at('bus', 'b', 'remind', time() - 4);
        $this->assertSame(['done' => 1, 'failed' => 1, 'expired' => 0], Scheduler::run());
    }

    public function testJournalEntriesOfATaskBelongToTheScheduler(): void
    {
        $this->site();
        Scheduler::on('bus', 'log', static fn(ScheduledTask $t) => Audit::record(Audit::MODULES, 'Отправлено'));
        Audit::as(AgeCMS\Actor::human('owner'));
        Scheduler::at('bus', 'x', 'log', time());
        Scheduler::run();
        $this->assertSame('планировщик', Database::value("SELECT actor_name FROM revisions WHERE action_desc = 'Отправлено'"));
        $this->assertSame('owner', Audit::actor()->name);
        Audit::as(null);
    }

    // ---- repeating --------------------------------------------------------------------------------------------

    public function testEveryKeepsItsStepAndCatchesUpOnlyOnce(): void
    {
        $this->site();
        Scheduler::every('bus', 'sync', 'remind', 3600);
        Scheduler::run();
        $first = $this->row('sync');
        $this->assertSame('pending', $first['status']);
        $this->assertSame($this->ran[0]['due'] + 3600, (int)$first['due_at']);
        Scheduler::every('bus', 'sync', 'remind', 3600);
        $this->assertSame($first, $this->row('sync'), 'register() on the next request changes nothing');

        $this->age('sync', 5 * 3600 + 120);
        Scheduler::run();
        $this->assertSame(2, count($this->ran), 'four missed times are one run');
        $next = (int)$this->row('sync')['due_at'];
        $this->assertTrue($next > time() && $next <= time() + 3600);
        $this->assertSame(0, ($next - (int)$first['due_at'] + 5 * 3600 + 120) % 3600, 'the step does not drift');

        Scheduler::every('bus', 'sync', 'remind', 1800);
        $this->assertTrue((int)$this->row('sync')['due_at'] <= time(), 'another interval: set anew');
    }

    public function testARepeatingTaskPastItsExpirySkipsToTheNextTime(): void
    {
        $this->site();
        Scheduler::every('bus', 'sync', 'remind', 3600, [], ['expires_in' => 600]);
        Scheduler::run();
        $this->age('sync', 3600 * 3 + 1800);
        $this->assertSame(['done' => 0, 'failed' => 0, 'expired' => 1], Scheduler::run(), 'half an hour late, allowed ten minutes');
        $this->assertTrue((int)$this->row('sync')['due_at'] > time());
        $this->assertContains('Пропущено', $this->row('sync')['last_error']);
        // Long break, but the latest time is fresh: it runs.
        $this->age('sync', (int)$this->row('sync')['due_at'] - time() + 3600 * 5 + 60);
        $this->assertSame(['done' => 1, 'failed' => 0, 'expired' => 0], Scheduler::run());
        $this->assertSame('', $this->row('sync')['last_error']);
    }

    public function testARepeatingTaskThatFailsGoesOnWithItsNextTime(): void
    {
        $this->site();
        $this->failing(new RuntimeException('down'));
        Scheduler::every('bus', 'sync', 'fail', 3600, [], ['attempts' => 2, 'retry' => 60]);
        Scheduler::run();
        $this->assertSame(1, (int)$this->row('sync')['attempts']);
        $this->age('sync', 61);
        Scheduler::run();
        $row = $this->row('sync');
        $this->assertSame('pending', $row['status'], 'a repeating task does not die');
        $this->assertSame(0, (int)$row['attempts']);
        $this->assertTrue((int)$row['run_at'] > time() + 3000);
        $this->assertSame('down', $row['last_error']);
        $this->assertSame(1, count(Scheduler::troubled()));

        // A retry never reaches into the next time.
        Scheduler::every('bus', 'fast', 'fail', 60, [], ['retry' => 300]);
        Scheduler::run();
        $this->assertTrue((int)$this->row('fast')['run_at'] - time() <= 60);
    }

    public function testDailyFollowsTheTimeZoneAndTheClockChange(): void
    {
        $this->site();
        Config::set('timezone', 'Europe/Moscow');
        Scheduler::daily('bus', 'digest', 'remind', '09:00');
        $row = $this->row('digest');
        $this->assertSame('09:00', (new DateTimeImmutable('@' . $row['due_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('H:i'));
        $this->assertTrue((int)$row['due_at'] > time() && (int)$row['due_at'] <= time() + 86400);
        Scheduler::daily('bus', 'digest', 'remind', '09:00');
        $this->assertSame($row, $this->row('digest'));

        Config::set('timezone', 'Asia/Vladivostok');
        Scheduler::run();
        $moved = $this->row('digest');
        $this->assertSame('09:00', (new DateTimeImmutable('@' . $moved['due_at']))->setTimezone(new DateTimeZone('Asia/Vladivostok'))->format('H:i'));
        $this->assertSame('Asia/Vladivostok', $moved['zone']);
        Scheduler::daily('bus', 'digest', 'remind', '09:00');
        $this->assertSame($moved, $this->row('digest'));

        // 2026-03-29 the clock in Berlin goes from 02:00 to 03:00, 2026-10-25 back.
        $slot = new ReflectionMethod(Scheduler::class, 'daySlot');
        $berlin = new DateTimeZone('Europe/Berlin');
        $at = static fn(string $local): int => (new DateTimeImmutable($local, $berlin))->getTimestamp();
        $spec = ['daily' => '09:00'];
        $this->assertSame($at('2026-03-29 09:00'), $slot->invoke(null, $spec, 'Europe/Berlin', $at('2026-03-28 09:00'), 1));
        $this->assertSame(23 * 3600, $at('2026-03-29 09:00') - $at('2026-03-28 09:00'), 'that day is an hour shorter');
        $this->assertSame($at('2026-10-25 09:00'), $slot->invoke(null, $spec, 'Europe/Berlin', $at('2026-10-24 09:00'), 1));
        $this->assertSame($at('2026-10-24 09:00'), $slot->invoke(null, $spec, 'Europe/Berlin', $at('2026-10-25 08:59'), -1));
        // Weekdays: from Friday evening to Monday.
        $this->assertSame($at('2026-11-30 09:00'), $slot->invoke(null, $spec + ['days' => [1, 3]], 'Europe/Berlin', $at('2026-11-27 18:00'), 1));
        $this->assertSame($at('2026-11-25 09:00'), $slot->invoke(null, $spec + ['days' => [1, 3]], 'Europe/Berlin', $at('2026-11-27 18:00'), -1));
        $this->assertSame('пн, ср в 09:00', Scheduler::repeatText($spec + ['days' => [1, 3]]));
    }

    public function testDailyMissedWhileTheServerWasDown(): void
    {
        $this->site();
        Scheduler::daily('bus', 'late', 'remind', '09:00');
        Scheduler::daily('bus', 'strict', 'remind', '09:00', [], ['expires_in' => 1]);
        foreach (['late', 'strict'] as $key) {
            Database::pdo()->prepare('UPDATE scheduled_tasks SET due_at = due_at - 259200, run_at = run_at - 259200 WHERE task_key = ?')->execute([$key]);
        }
        $report = Scheduler::run();
        $this->assertSame(['late'], array_column($this->ran, 'key'), 'one late run, the strict one waits for its next time');
        $this->assertSame(1, $report['expired']);
        $this->assertTrue((int)$this->row('late')['due_at'] > time() && (int)$this->row('strict')['due_at'] > time());
    }

    // ---- crash, replacement during a run, locks ---------------------------------------------------------------

    /** Puts a task into the state of a process that has taken it. */
    private function taken(string $key, string $claim, int $lease): void
    {
        Database::pdo()->prepare("UPDATE scheduled_tasks SET status = 'running', claim = ?, lease_until = ?, attempts = 1, last_run_at = ? WHERE task_key = ?")->execute([$claim, time() + $lease, time() - 400, $key]);
    }

    public function testATaskOfADeadProcessIsPickedUpAgain(): void
    {
        $this->site();
        Scheduler::at('bus', 'x', 'remind', time() - 400, [], ['retry' => 60]);
        $this->taken('x', 'deadbeef', 100);
        $this->assertSame(0, Scheduler::run()['done'], 'its time is not over');
        $this->taken('x', 'deadbeef', -1);
        $this->assertSame(0, Scheduler::run()['done']);
        $this->assertSame('pending', $this->row('x')['status']);
        $this->assertContains('прервался', $this->row('x')['last_error']);
        $this->age('x', 61);
        $this->assertSame(1, Scheduler::run()['done']);
        $this->assertSame(2, $this->ran[0]['attempt'], 'the crash counts as an attempt');
    }

    public function testOnceIsNeverRunAgainAfterACrash(): void
    {
        $this->site();
        Scheduler::at('bus', 'x', 'remind', time() - 400, [], ['once' => true]);
        $this->taken('x', 'deadbeef', -1);
        $this->assertSame(1, Scheduler::run()['failed']);
        $this->assertSame('failed', $this->row('x')['status']);
        $this->assertSame([], $this->ran);
    }

    public function testALongTaskKeepsItsLockWhileItsProcessLives(): void
    {
        $this->site();
        Scheduler::at('bus', 'x', 'remind', time() - 400);
        $held = fopen(Scheduler::dir() . '/aliveclaim.lock', 'c');
        flock($held, LOCK_EX);
        $this->taken('x', 'aliveclaim', -1);
        Scheduler::run();
        $row = $this->row('x');
        $this->assertSame('running', $row['status']);
        $this->assertTrue((int)$row['lease_until'] > time());
        $this->assertSame([], $this->ran);
        flock($held, LOCK_UN);
        fclose($held);
        $this->taken('x', 'aliveclaim', -1);
        Scheduler::run();
        $this->assertSame('pending', $this->row('x')['status']);
    }

    public function testReplacingOrCancellingDuringTheRun(): void
    {
        $this->site();
        $states = [];
        Scheduler::on('bus', 'self', function (ScheduledTask $t) use (&$states): void {
            $states[] = [$t->current(), $t->extend(600)];
            if ($t->payload['do'] === 'again') {
                Scheduler::at('bus', $t->key, 'self', time() - 1, ['do' => 'cancel']);
            } elseif ($t->payload['do'] === 'cancel') {
                Scheduler::cancel('bus', $t->key);
            }
            $states[] = [$t->current(), $t->extend(600)];
            if ($t->payload['do'] === 'cancel') {
                throw new RuntimeException('must not be tried again');
            }
        });
        Scheduler::at('bus', 'x', 'self', time(), ['do' => 'again']);
        Scheduler::run();
        $this->assertSame([[true, true], [false, false], [true, true], [false, false]], $states, 'the replacement ran right after, then cancelled itself');
        $this->assertSame(null, $this->row('x'), 'cancelled: no retry, no row');
        $this->assertSame([], glob(Scheduler::dir() . '/[0-9a-f]*.lock') ?: [], 'no lock files are left');
    }

    public function testTasksOfAMissingModuleWaitAndStillExpire(): void
    {
        $this->site();
        Scheduler::at('gone', 'keep', 'x', time() - 10);
        Scheduler::at('gone', 'old', 'x', time(), [], ['expires_in' => 60]);
        $this->age('old', 120, 'gone');
        $this->assertSame(['done' => 0, 'failed' => 0, 'expired' => 1], Scheduler::run());
        $this->assertSame('pending', $this->row('keep', 'gone')['status']);
        $this->assertSame(false, Scheduler::upcoming()[0]['handled']);
        $id = (int)$this->row('keep', 'gone')['id'];
        $this->assertSame('gone / keep', Scheduler::remove($id));
        $this->assertThrows(NotFoundException::class, fn() => Scheduler::remove($id));
    }

    public function testOldRowsAreRemoved(): void
    {
        $this->site();
        Scheduler::at('bus', 'done', 'remind', time());
        Scheduler::at('bus', 'fresh', 'remind', time());
        Scheduler::run();
        $this->failing(new GiveUpException('x'));
        Scheduler::at('bus', 'failed', 'fail', time());
        Scheduler::run();
        $this->age('done', 8 * 86400);
        $this->age('failed', 8 * 86400);
        touch(Scheduler::dir() . '/.cleanup', time() - 3700);
        Scheduler::run();
        $this->assertSame(null, $this->row('done'));
        $this->assertTrue($this->row('fresh') !== null && $this->row('failed') !== null);
        $this->age('failed', 30 * 86400);
        touch(Scheduler::dir() . '/.cleanup', time() - 3700);
        Scheduler::run();
        $this->assertSame(null, $this->row('failed'));
    }

    public function testTheSwitchStopsEverything(): void
    {
        $this->site();
        Config::set('scheduler.enabled', false);
        Scheduler::at('bus', 'x', 'remind', time());
        $this->assertSame(null, Scheduler::run());
        Config::set('scheduler.enabled', true);
        $this->assertSame(1, Scheduler::run()['done']);
    }

    // ---- visits and cron --------------------------------------------------------------------------------------

    public function testWithoutCronVisitsRunTheTasks(): void
    {
        $this->site();
        Scheduler::at('bus', 'x', 'remind', time());
        Background::run();
        $this->assertSame(1, count($this->ran));
        $this->assertSame('visit', Cron::status()['last']['source']);
        $this->assertSame('none', Cron::status()['level'], 'a visit is not a cron');
        Scheduler::at('bus', 'y', 'remind', time());
        Background::run(true);
        $this->assertSame(1, count($this->ran), 'at most every thirty seconds');
        touch(Scheduler::dir() . '/.visit', time() - 31);
        Background::run();
        $this->assertSame(2, count($this->ran));
    }

    public function testVisitsLeaveTheTasksToALivingCron(): void
    {
        $this->site();
        $ticks = 0;
        EventBus::listen(Background::TICK_EVENT, function () use (&$ticks): void {
            $ticks++;
        });
        $result = Cron::run('cron', 10);
        $this->assertSame(true, $result['background']['ticked'], 'cron raises background.tick');
        $this->assertSame(1, $ticks);
        Scheduler::at('bus', 'x', 'remind', time());
        Background::run(true);
        $this->assertSame([], $this->ran, 'cron was here a moment ago');
        $this->assertSame(1, $ticks, 'and the tick is not doubled');
        Cron::run('cron', 10);
        $this->assertSame(1, count($this->ran));
    }

    public function testTheStatusKnowsASilentCron(): void
    {
        $this->site();
        $this->assertSame(['level' => 'none', 'source' => null, 'at' => null, 'last' => null], Cron::status());
        Cron::run('url', 5);
        $now = time();
        $this->assertSame('ok', Cron::status($now)['level']);
        $this->assertSame('url', Cron::status($now)['source']);
        $this->assertSame('ok', Cron::status($now + 590)['level']);
        $this->assertSame('warn', Cron::status($now + 700)['level']);
        file_put_contents(AGECMS_DATA . '/scheduler/state.json', json_encode(['url' => ['at' => $now, 'gap' => 3600]]));
        $this->assertSame('ok', Cron::status($now + 3 * 3600 - 10)['level'], 'a hosting that comes once an hour is not silent after ten minutes');
        $this->assertSame('warn', Cron::status($now + 3 * 3600 + 10)['level']);
        file_put_contents(AGECMS_DATA . '/scheduler/state.json', '{broken');
        $this->assertSame('none', Cron::status()['level']);
    }

    public function testCronStepsAsideWhileTheCoreIsBeingReplaced(): void
    {
        $this->site();
        Scheduler::at('bus', 'x', 'remind', time());
        $lock = fopen(AGECMS_DATA . '/update.lock', 'c');
        flock($lock, LOCK_EX);
        $this->assertSame(null, Cron::run('cron', 5));
        $this->assertSame([], $this->ran);
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame(1, Cron::run('cron', 5)['tasks']['done']);
    }

    public function testTheCommandForCronEscapesWhatCronWouldMisread(): void
    {
        $this->site();
        $this->assertContains('/bin/cron.php', Cron::crontabLine());
        $this->assertTrue(str_starts_with(Cron::crontabLine(), '* * * * * '));
        $quote = new ReflectionMethod(Cron::class, 'quote');
        $this->assertSame("'/var/www/my site/bin/cron.php'", $quote->invoke(null, '/var/www/my site/bin/cron.php'));
        $this->assertSame("'/a'\\''b'", $quote->invoke(null, "/a'b"));
        $this->assertTrue(preg_match('/^\* \* \* \* \* \S+ /', Cron::cronDLine()) === 1);
        $this->assertContains("| sudo tee /etc/cron.d/agecms-example-test-scheduler", Cron::installCommand(), 'cron ignores files with a dot in the name');
    }

    // ---- real processes ---------------------------------------------------------------------------------------

    /** A module whose handler writes a line into a file, like the bot of a carrier would send a message. */
    private function reminderModule(int $sleepMs = 0): string
    {
        TestEnv::remove(AGECMS_MODULES . '/reminder');
        $dir = ModuleFixture::make('reminder', [], [], [], false);
        $out = TEST_TMP . '/sent.txt';
        $code = (string)file_get_contents($dir . '/Module.php');
        $code = str_replace("public function register(): void\n    {\n    }", "public function register(): void\n    {\n        \\AgeCMS\\Scheduler::on('reminder', 'send', static function (\\AgeCMS\\ScheduledTask \$t): void {\n            usleep({$sleepMs}000);\n            file_put_contents('{$out}', \$t->key . \"\\n\", FILE_APPEND | LOCK_EX);\n        });\n    }", $code);
        file_put_contents($dir . '/Module.php', $code);
        @unlink($dir . '/manifest.json.sig');
        @unlink($out);
        SkillRegistry::reset();
        return $out;
    }

    /** @return array{0:resource,1:array<int,resource>} */
    private function cron(string ...$args): array
    {
        $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/cron.php', ...$args], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, [
            'AGECMS_DATA_DIR' => AGECMS_DATA, 'AGECMS_CACHE_DIR' => AGECMS_CACHE, 'AGECMS_MODULES_DIR' => AGECMS_MODULES, 'AGECMS_UPLOADS_DIR' => AGECMS_UPLOADS, 'PATH' => (string)getenv('PATH'),
        ]);
        return [$proc, $pipes];
    }

    /** @param array{0:resource,1:array<int,resource>} $run */
    private function wait(array $run): string
    {
        $out = stream_get_contents($run[1][1]) . stream_get_contents($run[1][2]);
        proc_close($run[0]);
        return $out;
    }

    public function testCronRunsTheTaskOfAModuleWithoutAnyVisit(): void
    {
        $this->freshSite();
        $out = $this->reminderModule();
        Scheduler::at('reminder', 'trip:57', 'send', time() - 1, [], ['expires_in' => 3600]);
        Scheduler::at('reminder', 'trip:58', 'send', time() + 3600);
        $text = $this->wait($this->cron());
        $this->assertContains('tasks=1 failed=0 expired=0', $text);
        $this->assertContains('ticked=1', $text);
        $this->assertSame("trip:57\n", (string)file_get_contents($out));
        $this->assertSame('cron', Cron::status()['last']['source']);
        $this->assertContains('scheduler: ok', $this->wait($this->cron('--status')));
        $this->assertContains('* * * * * ', $this->wait($this->cron('--line')));
    }

    public function testParallelRunsDoEveryTaskExactlyOnce(): void
    {
        $this->freshSite();
        $out = $this->reminderModule(5);
        $want = [];
        for ($i = 1; $i <= 60; $i++) {
            Scheduler::at('reminder', "trip:{$i}", 'send', time() - 1);
            $want[] = "trip:{$i}";
        }
        Database::reset(); // the children must not share the parent's connection
        $runs = [];
        for ($i = 0; $i < 6; $i++) {
            $runs[] = $this->cron();
        }
        $done = 0;
        $workers = 0;
        foreach ($runs as $run) {
            $text = $this->wait($run);
            $this->assertNotContains('rror', str_replace('errors', '', $text));
            preg_match('/tasks=(\d+) failed=0/', $text, $m);
            $done += (int)($m[1] ?? 0);
            $workers += (int)($m[1] ?? 0) > 0 ? 1 : 0;
        }
        $this->assertSame(60, $done);
        $this->assertTrue($workers >= 1 && $workers <= 3, "{$workers} processes worked, the places for them are three");
        $sent = array_filter(explode("\n", (string)file_get_contents($out)));
        sort($sent);
        sort($want);
        $this->assertSame($want, array_values($sent), 'every message exactly once');
    }

    public function testAKilledProcessLeavesATaskThatIsFinishedLater(): void
    {
        $this->freshSite();
        $out = $this->reminderModule(8000);
        Scheduler::at('reminder', 'trip:1', 'send', time() - 1, [], ['lease' => 30, 'retry' => 1]);
        $run = $this->cron();
        for ($i = 0; $i < 100 && $this->row('trip:1', 'reminder')['status'] !== 'running'; $i++) {
            usleep(50000);
        }
        $this->assertSame('running', $this->row('trip:1', 'reminder')['status']);
        proc_terminate($run[0], 9);
        proc_close($run[0]);
        $this->reminderModule(0);
        $this->age('trip:1', 60, 'reminder');
        $this->wait($this->cron());
        $this->assertSame('pending', $this->row('trip:1', 'reminder')['status']);
        $this->age('trip:1', 2, 'reminder');
        $this->assertContains('tasks=1', $this->wait($this->cron()));
        $this->assertSame("trip:1\n", (string)file_get_contents($out));
    }

    // ---- the run address --------------------------------------------------------------------------------------

    public function testTheRunAddressNeedsItsSecretAndSaysNothing(): void
    {
        $this->boot();
        $out = $this->reminderModule();
        Scheduler::at('reminder', 'trip:1', 'send', time() - 1);
        $this->assertSame(404, $this->http('GET', '/_/cron')['status'], 'off until the owner switches it on');
        $this->assertSame(404, $this->http('GET', '/_/cron?key=')['status']);
        Cron::renewKey();
        $key = (string)Config::get('secrets.cron_key');
        $this->assertTrue(strlen($key) >= 40);
        $this->assertContains('/_/cron?key=' . $key, Cron::url());

        $bad = $this->http('GET', '/_/cron?key=' . $key . 'x');
        $this->assertSame([404, ''], [$bad['status'], $bad['body']]);
        $this->assertSame(404, $this->http('GET', '/_/cron?key[]=1')['status']);
        $this->assertSame(405, $this->http('HEAD', '/_/cron?key=' . $key)['status']);
        $this->assertSame(false, is_file($out));

        $ok = $this->http('GET', '/_/cron?key=' . $key);
        $this->assertSame([204, ''], [$ok['status'], $ok['body']]);
        $this->assertSame('no-store', $ok['headers']['cache-control']);
        $this->assertContains('noindex', $ok['headers']['x-robots-tag']);
        $this->assertSame("trip:1\n", (string)file_get_contents($out));
        $this->assertSame('url', Cron::status()['source']);

        Scheduler::at('reminder', 'trip:2', 'send', time() - 1);
        $this->assertSame(204, $this->http('POST', '/_/cron', '', ['X-AgeCMS-Cron' => $key])['status'], 'the header keeps the secret out of the address');
        $this->assertSame("trip:1\ntrip:2\n", (string)file_get_contents($out));

        $logs = '';
        foreach (glob(AGECMS_DATA . '/logs/*') ?: [] as $file) {
            $logs .= (string)file_get_contents($file);
        }
        $this->assertNotContains($key, $logs);
        foreach (Database::rows("SELECT name FROM sqlite_master WHERE type = 'table'") as $t) {
            $this->assertNotContains($key, (string)json_encode(@Database::rows('SELECT * FROM "' . $t['name'] . '"'), JSON_PARTIAL_OUTPUT_ON_ERROR), 'table ' . $t['name']);
        }

        Cron::renewKey();
        $this->assertSame(404, $this->http('GET', '/_/cron?key=' . $key)['status'], 'the old address is dead');
        $new = (string)Config::get('secrets.cron_key');
        Cron::disableUrl();
        $this->assertSame(404, $this->http('GET', '/_/cron?key=' . $new)['status']);
    }

    public function testTheRunAddressIsLimited(): void
    {
        $this->boot();
        Cron::renewKey();
        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $codes[] = $this->http('GET', '/_/cron?key=guess' . $i)['status'];
        }
        $this->assertSame(array_merge(array_fill(0, 10, 404), [429, 429]), $codes);
        Database::pdo()->exec('DELETE FROM rate_limits');
        $key = (string)Config::get('secrets.cron_key');
        $codes = [];
        for ($i = 0; $i < 11; $i++) {
            $codes[] = $this->http('GET', '/_/cron?key=' . $key)['status'];
        }
        $this->assertSame(array_merge(array_fill(0, 10, 204), [429]), $codes);
    }

    public function testAnErrorOfTheRunAddressDoesNotPutTheSecretIntoTheLog(): void
    {
        $this->boot();
        Cron::renewKey();
        $key = (string)Config::get('secrets.cron_key');
        Database::pdo()->exec('DROP TABLE rate_limits');
        $this->assertSame(500, $this->http('GET', '/_/cron?key=' . $key)['status']);
        $log = (string)file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json');
        $this->assertContains('request.failed', $log);
        $this->assertNotContains($key, $log);
    }

    // ---- the admin --------------------------------------------------------------------------------------------

    public function testTheAdminShowsTheSchedulerAndItsActions(): void
    {
        $this->boot();
        $this->reminderModule();
        $form = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), $form, true);
        $page = $this->http('GET', '/admin/?s=system', null, [], true)['body'];
        $this->assertContains('Планировщик', $page);
        $this->assertContains('cron не настроен', $page);
        $this->assertContains('/bin/cron.php', $page);
        $this->assertNotContains('key=', $page);
        preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
        $post = fn(string $action, array $more = []): array => $this->http('POST', '/admin/index.php', http_build_query(['action' => $action, 'csrf' => $m[1]] + $more), $form, true);

        $on = $post('cron_url_on');
        Config::reset();
        $this->assertContains('/_/cron?key=' . Config::get('secrets.cron_key'), $on['body']);
        Scheduler::at('reminder', 'trip:1', 'send', time() + 900);
        Database::pdo()->exec("INSERT INTO scheduled_tasks (module, task_key, handler, status, due_at, run_at, last_error, finished_at, created_at, updated_at) VALUES ('reminder', 'bad', 'send', 'failed', " . (time() - 60) . ', 0, \'<b>boom</b>\', ' . time() . ', 0, 0)');
        $page = $this->http('GET', '/admin/?s=system', null, [], true)['body'];
        $this->assertContains('Ближайшие задачи', $page);
        $this->assertContains('trip:1', $page);
        $this->assertContains('&lt;b&gt;boom', $page);
        $id = (int)$this->row('bad', 'reminder')['id'];
        $this->assertSame(200, $post('task_retry', ['id' => $id])['status']);
        $this->assertContains('убрана', $post('task_remove', ['id' => (int)$this->row('trip:1', 'reminder')['id']])['body']);
        $this->assertSame(null, $this->row('trip:1', 'reminder'));
        $post('cron_url_off');
        Config::reset();
        $this->assertSame(false, Cron::urlEnabled());
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM revisions WHERE action_desc = 'Планировщик: адрес запуска включён'"));

        file_put_contents(AGECMS_DATA . '/scheduler/state.json', json_encode(['cron' => ['at' => time() - 7200, 'gap' => 60]]));
        $page = $this->http('GET', '/admin/?s=system', null, [], true)['body'];
        $this->assertContains('cron молчит', $page);
        $this->assertNotContains("\u{2014}", substr($page, (int)strpos($page, 'id="scheduler"'), 6000));

        AgeCMS\Auth::createUser('edith', 'Editor-pass-12', 'editor');
        $this->jar = TEST_TMP . '/cookies2.txt';
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'edith', 'password' => 'Editor-pass-12']), $form, true);
        $page = $this->http('GET', '/admin/?s=system', null, [], true)['body'];
        $this->assertNotContains('id="scheduler"', $page, 'administrators only');
        preg_match('/name="csrf" value="([^"]+)"/', $this->http('GET', '/admin/?s=users', null, [], true)['body'], $m);
        $post('cron_url_on');
        Config::reset();
        $this->assertSame(false, Cron::urlEnabled());
    }

    // ---- the database of an older core ------------------------------------------------------------------------

    public function testADatabaseOfSchemaNineIsUpgraded(): void
    {
        $this->freshSite();
        $this->createPage('kept');
        Database::pdo()->exec('DROP TABLE scheduled_tasks');
        Database::pdo()->exec('PRAGMA user_version = 9');
        Database::reset();
        $this->assertSame(Schema::latest(), (int)Database::value('PRAGMA user_version'));
        $this->assertSame(0, Scheduler::counts()['waiting']);
        Scheduler::at('bus', 'x', 'remind', time() + 60);
        Database::pdo()->exec('PRAGMA user_version = 9');
        Database::reset();
        $this->assertSame(Schema::latest(), (int)Database::value('PRAGMA user_version'));
        $this->assertSame('pending', Scheduler::find('bus', 'x')['status'], 'the step again keeps what is there');
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM pages WHERE slug = 'kept'"));
    }
}
