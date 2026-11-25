<?php
declare(strict_types=1);

namespace AgeCMS;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Work of modules at a set time: once (a reminder the day before a trip), every N seconds, or every day at a time of day in the
 * time zone of the site. Tasks live in the table scheduled_tasks, one row per module and key: setting a task with the same key
 * replaces it. They are run by bin/cron.php, by the run address (Cron) and, when neither has been seen lately, by visits.
 *
 *   Scheduler::on('bus', 'remind', fn(ScheduledTask $t) => ...);                       // in register()
 *   Scheduler::at('bus', 'remind:57', 'remind', $dayBefore, ['trip' => 57], ['expires_at' => $departure]);
 *   Scheduler::cancel('bus', 'remind:57');
 *
 * Guarantees: a task is taken by one process at a time (claim in a write transaction plus a lock file that lives as long as the
 * process), a failure is tried again after a pause up to a limit, a task whose process died is picked up by the next run, a task
 * past its expiry is not run at all. A process that dies between doing the work and saying so runs the task again ("at least
 * once"); the option once forbids that and leaves such a task failed. Finished rows are removed after a while.
 */
final class Scheduler
{
    /** A task without its own expiry is dropped this long after its time (option expires_in => null: never). */
    public const DEFAULT_EXPIRES = 86400;
    public const DEFAULT_ATTEMPTS = 5;
    /** Pauses before the second, third ... attempt, seconds; the last one repeats. */
    public const DEFAULT_RETRY = [60, 300, 900, 3600];
    /** Seconds after which a task whose process is gone is taken for abandoned. */
    public const DEFAULT_LEASE = 300;
    public const MIN_EVERY = 60;
    private const MAX_PAYLOAD = 16384;
    /** Processes that run tasks at the same time: a stuck one does not stop the others. */
    private const SLOTS = 3;
    private const KEEP_DONE = 7 * 86400;
    private const KEEP_FAILED = 30 * 86400;

    /** @var array<string,callable> "module.handler" => callable */
    private static array $handlers = [];

    /** Registers the code of a task. Call it in register() of the module, the name is the one at(), every() and daily() get. */
    public static function on(string $module, string $handler, callable $fn): void
    {
        self::checkNames($module, $handler);
        self::$handlers[$module . '.' . $handler] = $fn;
    }

    public static function reset(): void
    {
        self::$handlers = [];
    }

    /**
     * Once at $when. A task of the module with the same key is replaced, whatever its state. Returns false, with nothing stored
     * and the old task cancelled, when the task would already be past its expiry.
     *
     * Options: expires_at (unix time or DateTimeInterface) or expires_in (seconds after $when, null: never, default a day);
     * attempts (1-20, default 5); retry (pause in seconds or a list of pauses); lease (30-3600 seconds, default 300); once (true:
     * never run again after a crash in the middle).
     *
     * @param array<string,mixed> $payload up to 16 KB of JSON. Ids, not personal data: the table goes into backups
     * @param array<string,mixed> $options
     */
    public static function at(string $module, string $key, string $handler, int|DateTimeInterface $when, array $payload = [], array $options = []): bool
    {
        $due = self::time($when);
        $spec = self::spec($options, ['expires_at', 'expires_in', 'attempts', 'retry', 'lease', 'once']);
        if (isset($options['expires_at'])) {
            if (!is_int($options['expires_at']) && !$options['expires_at'] instanceof DateTimeInterface) {
                throw new ValidationException(['expires_at is a unix time or a DateTimeInterface']);
            }
            $expires = self::time($options['expires_at']);
        } else {
            $in = array_key_exists('expires_in', $options) ? $spec['expires'] : self::DEFAULT_EXPIRES;
            $expires = $in === null ? null : $due + $in;
        }
        unset($spec['expires']);
        if ($expires !== null && $expires <= max(time(), $due)) {
            self::checkNames($module, $handler, $key);
            self::cancel($module, $key);
            return false;
        }
        self::store($module, $key, $handler, $due, $expires, $payload, $spec, '', false);
        return true;
    }

    /**
     * Every $seconds (60 or more), the first time at once or at the option first (unix time). Safe to call in register() on every
     * request: a task that is already there with the same settings keeps its time. Missed times are not caught up one by one: after
     * a break the task runs once. Options: first, expires_in (a run later than this after its time is skipped), attempts, retry, lease, once.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public static function every(string $module, string $key, string $handler, int $seconds, array $payload = [], array $options = []): void
    {
        if ($seconds < self::MIN_EVERY || $seconds > 366 * 86400) {
            throw new ValidationException(['every: from ' . self::MIN_EVERY . ' seconds to a year']);
        }
        $spec = self::spec($options, ['first', 'expires_in', 'attempts', 'retry', 'lease', 'once']);
        if (isset($options['first']) && !is_int($options['first']) && !$options['first'] instanceof DateTimeInterface) {
            throw new ValidationException(['first is a unix time or a DateTimeInterface']);
        }
        $spec['every'] = $seconds;
        $due = isset($options['first']) ? self::time($options['first']) : time();
        self::store($module, $key, $handler, $due, isset($spec['expires']) ? $due + $spec['expires'] : null, $payload, $spec, '', true);
    }

    /**
     * Every day at $time ("09:00") in the time zone of the site (config timezone); option days: list of weekdays, 1 Monday to 7
     * Sunday. Otherwise like every(). When the owner changes the time zone, the next run moves to the new one.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public static function daily(string $module, string $key, string $handler, string $time, array $payload = [], array $options = []): void
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new ValidationException(['daily: the time is HH:MM']);
        }
        $spec = self::spec($options, ['days', 'expires_in', 'attempts', 'retry', 'lease', 'once']);
        $spec['daily'] = $time;
        if (isset($options['days'])) {
            $days = $options['days'];
            if (!is_array($days) || $days === [] || array_filter($days, static fn(mixed $d): bool => !is_int($d) || $d < 1 || $d > 7) !== []) {
                throw new ValidationException(['days is a list of 1 (Monday) .. 7 (Sunday)']);
            }
            $days = array_values(array_unique($days));
            sort($days);
            $spec['days'] = $days;
        }
        $zone = self::zone()->getName();
        $due = self::next($spec, $zone, 0, time());
        self::store($module, $key, $handler, $due, isset($spec['expires']) ? $due + $spec['expires'] : null, $payload, $spec, $zone, true);
    }

    /**
     * Removes a task. True when it was waiting. A task that is running right now cannot be stopped: it is not repeated or tried
     * again, and its handler sees current() === false.
     */
    public static function cancel(string $module, string $key): bool
    {
        return self::cancelWhere('module = ? AND task_key = ?', [$module, $key]) > 0;
    }

    /** Removes every task of the module whose key starts with $prefix ("trip:57:"). Returns how many were waiting. */
    public static function cancelPrefix(string $module, string $prefix): int
    {
        if ($prefix === '') {
            throw new ValidationException(['cancelPrefix: the prefix is empty']);
        }
        return self::cancelWhere('module = ? AND substr(task_key, 1, ?) = ?', [$module, strlen($prefix), $prefix]);
    }

    /**
     * @return array{id:int,module:string,key:string,handler:string,payload:array<string,mixed>,status:string,due_at:int,run_at:int,expires_at:?int,attempts:int,last_error:string}|null
     */
    public static function find(string $module, string $key): ?array
    {
        $row = Database::row('SELECT * FROM scheduled_tasks WHERE module = ? AND task_key = ?', [$module, $key]);
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int)$row['id'], 'module' => (string)$row['module'], 'key' => (string)$row['task_key'], 'handler' => (string)$row['handler'],
            'payload' => (array)json_decode((string)$row['payload'], true), 'status' => (string)$row['status'],
            'due_at' => (int)$row['due_at'], 'run_at' => (int)$row['run_at'], 'expires_at' => $row['expires_at'] === null ? null : (int)$row['expires_at'],
            'attempts' => (int)$row['attempts'], 'last_error' => (string)$row['last_error'],
        ];
    }

    /**
     * Runs what is due until $budgetSeconds are over; a task that has begun is always finished. Any number of processes may call
     * it at once. Null when the scheduler is switched off or enough processes are already at work.
     *
     * @return array{done:int,failed:int,expired:int}|null
     */
    public static function run(float $budgetSeconds = 8.0, ?int $limit = null): ?array
    {
        if (Config::get('scheduler.enabled', true) !== true) {
            return null;
        }
        $slot = self::slot();
        if ($slot === null) {
            return null;
        }
        $report = ['done' => 0, 'failed' => 0, 'expired' => 0];
        try {
            $deadline = microtime(true) + $budgetSeconds;
            self::cleanup();
            SkillRegistry::boot();
            self::followZone();
            $report['failed'] += self::recover();
            $report['expired'] += self::expire();
            // Never waits for a task to become due: the next run takes it.
            while (($limit === null || array_sum($report) < $limit) && microtime(true) < $deadline) {
                $claimed = self::claim();
                if ($claimed === null) {
                    break;
                }
                if ($claimed === false) {
                    $report['expired']++; // past its expiry: ended, or moved to its next time
                    continue;
                }
                $report[self::execute($claimed, $deadline) ? 'done' : 'failed']++;
            }
        } finally {
            flock($slot, LOCK_UN);
            fclose($slot);
        }
        return $report;
    }

    /** True when something could be run or put in order now, without loading modules: the cheap question of a visit. */
    public static function due(): bool
    {
        $now = time();
        return Database::row("SELECT 1 FROM scheduled_tasks WHERE status = 'pending' AND run_at <= ? LIMIT 1", [$now]) !== null
            || Database::row("SELECT 1 FROM scheduled_tasks WHERE claim <> '' AND lease_until <= ? LIMIT 1", [$now]) !== null;
    }

    /** @internal ScheduledTask::current() */
    public static function owns(int $id, string $claim, int $version): bool
    {
        return Database::row("SELECT 1 FROM scheduled_tasks WHERE id = ? AND claim = ? AND version = ? AND status = 'running'", [$id, $claim, $version]) !== null;
    }

    /** @internal ScheduledTask::extend() */
    public static function extend(int $id, string $claim, int $version, int $seconds): bool
    {
        return Database::write(static function (PDO $pdo) use ($id, $claim, $version, $seconds): bool {
            $stmt = $pdo->prepare('UPDATE scheduled_tasks SET lease_until = ? WHERE id = ? AND claim = ?');
            $stmt->execute([time() + max(30, min(3600, $seconds)), $id, $claim]);
            $own = $pdo->prepare("SELECT 1 FROM scheduled_tasks WHERE id = ? AND claim = ? AND version = ? AND status = 'running'");
            $own->execute([$id, $claim, $version]);
            return $own->fetchColumn() !== false;
        });
    }

    // ---- for the admin ----------------------------------------------------------------------------------------

    /** @return array{waiting:int,overdue:int,running:int,failed:int} */
    public static function counts(): array
    {
        $now = time();
        $row = Database::row("SELECT
            SUM(status = 'pending') AS waiting,
            SUM(status = 'pending' AND run_at <= ?) AS overdue,
            SUM(status = 'running') AS running,
            SUM(status = 'failed' OR (status IN ('pending', 'running') AND last_error <> '')) AS failed
            FROM scheduled_tasks", [$now - 120]) ?? [];
        return ['waiting' => (int)($row['waiting'] ?? 0), 'overdue' => (int)($row['overdue'] ?? 0), 'running' => (int)($row['running'] ?? 0), 'failed' => (int)($row['failed'] ?? 0)];
    }

    /** The nearest tasks, running ones first. @return list<array<string,mixed>> */
    public static function upcoming(int $limit = 10): array
    {
        return self::decorate(Database::rows("SELECT * FROM scheduled_tasks WHERE status IN ('pending', 'running') ORDER BY status = 'running' DESC, run_at, id LIMIT ?", [$limit]));
    }

    /** Tasks that failed, were missed, or repeat with an error. @return list<array<string,mixed>> */
    public static function troubled(int $limit = 10): array
    {
        return self::decorate(Database::rows("SELECT * FROM scheduled_tasks WHERE status IN ('failed', 'expired') OR (status IN ('pending', 'running') AND last_error <> '') ORDER BY COALESCE(finished_at, last_run_at, updated_at) DESC, id DESC LIMIT ?", [$limit]));
    }

    /** A failed task runs again with the next run. */
    public static function retry(int $id): void
    {
        Database::write(static function (PDO $pdo) use ($id): void {
            $stmt = $pdo->prepare('SELECT * FROM scheduled_tasks WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new NotFoundException('Такой задачи уже нет');
            }
            if (!in_array($row['status'], ['failed', 'pending'], true)) {
                throw new AgeCMSException('Эту задачу повторить нельзя: она уже выполняется или её срок прошёл');
            }
            if ($row['expires_at'] !== null && (int)$row['expires_at'] <= time()) {
                throw new AgeCMSException('Срок годности этой задачи прошёл, повторять её нельзя');
            }
            $pdo->prepare("UPDATE scheduled_tasks SET status = 'pending', attempts = 0, run_at = ?, finished_at = NULL, updated_at = ?, version = version + 1 WHERE id = ?")
                ->execute([time(), time(), $id]);
        });
    }

    /** The admin removes a task. Returns "module / key" for the journal. */
    public static function remove(int $id): string
    {
        $row = Database::row('SELECT module, task_key FROM scheduled_tasks WHERE id = ?', [$id]);
        if ($row === null) {
            throw new NotFoundException('Такой задачи уже нет');
        }
        self::cancelWhere('id = ?', [$id], true);
        return $row['module'] . ' / ' . $row['task_key'];
    }

    // ---- storing ----------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $spec
     */
    private static function store(string $module, string $key, string $handler, int $due, ?int $expires, array $payload, array $spec, string $zone, bool $keep): void
    {
        self::checkNames($module, $handler, $key);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        try {
            $json = json_encode($payload, $flags);
        } catch (Throwable $e) {
            throw new ValidationException(['the payload of a task must be plain data: ' . $e->getMessage()]);
        }
        if (strlen($json) > self::MAX_PAYLOAD) {
            throw new ValidationException(['the payload of a task is over ' . self::MAX_PAYLOAD . ' bytes']);
        }
        ksort($spec);
        $specJson = json_encode($spec, $flags);
        $same = static fn(array $row): bool => $row['handler'] === $handler && $row['payload'] === $json && $row['spec'] === $specJson && in_array($row['status'], ['pending', 'running'], true);
        if ($keep) {
            // register() of a module runs on every request: the usual case is one read and no write.
            $row = Database::row('SELECT handler, payload, spec, status FROM scheduled_tasks WHERE module = ? AND task_key = ?', [$module, $key]);
            if ($row !== null && $same($row)) {
                return;
            }
        }
        Database::write(static function (PDO $pdo) use ($module, $key, $handler, $due, $expires, $json, $specJson, $zone, $keep, $same): void {
            $now = time();
            $stmt = $pdo->prepare('SELECT id, handler, payload, spec, status FROM scheduled_tasks WHERE module = ? AND task_key = ?');
            $stmt->execute([$module, $key]);
            $row = $stmt->fetch();
            if ($row === false) {
                $pdo->prepare('INSERT INTO scheduled_tasks (module, task_key, handler, payload, status, due_at, run_at, expires_at, spec, zone, created_at, updated_at) VALUES (?, ?, ?, ?, \'pending\', ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$module, $key, $handler, $json, $due, $due, $expires, $specJson, $zone, $now, $now]);
                return;
            }
            if ($keep && $same($row)) {
                return;
            }
            // claim and lease stay: while a process is still inside the old task, the new one waits for it to finish.
            $pdo->prepare("UPDATE scheduled_tasks SET handler = ?, payload = ?, status = 'pending', due_at = ?, run_at = ?, expires_at = ?, spec = ?, zone = ?, attempts = 0, version = version + 1, last_error = '', finished_at = NULL, updated_at = ? WHERE id = ?")
                ->execute([$handler, $json, $due, $due, $expires, $specJson, $zone, $now, $row['id']]);
        });
    }

    /** @param list<mixed> $params */
    private static function cancelWhere(string $where, array $params, bool $finished = false): int
    {
        return Database::write(static function (PDO $pdo) use ($where, $params, $finished): int {
            $count = $pdo->prepare("SELECT COUNT(*) FROM scheduled_tasks WHERE {$where} AND status = 'pending'");
            $count->execute($params);
            $waiting = (int)$count->fetchColumn();
            // A row a process is still inside stays until it has finished; its version no longer matches, so nothing it reports counts.
            $pdo->prepare("UPDATE scheduled_tasks SET status = 'cancelled', version = version + 1, finished_at = " . time() . ", updated_at = " . time() . " WHERE {$where} AND claim <> '' AND status IN ('pending', 'running')")->execute($params);
            $pdo->prepare("DELETE FROM scheduled_tasks WHERE {$where} AND claim = ''" . ($finished ? '' : " AND status = 'pending'"))->execute($params);
            return $waiting;
        });
    }

    // ---- running ----------------------------------------------------------------------------------------------

    /**
     * Takes the next due task for this process. Null: nothing is due. False: the task was past its expiry and has been put in order,
     * ask again.
     *
     * @return array<string,mixed>|false|null
     */
    private static function claim(): array|false|null
    {
        if (self::$handlers === []) {
            return null;
        }
        $claim = bin2hex(random_bytes(12));
        // The lock file exists before anyone can see the claim, so "no file" always means "nobody is at work".
        $handle = self::lockFile($claim);
        $taken = null;
        $missed = null;
        try {
            $taken = Database::write(static function (PDO $pdo) use ($claim, &$missed): array|false|null {
                $now = time();
                $names = array_keys(self::$handlers);
                $stmt = $pdo->prepare("SELECT * FROM scheduled_tasks WHERE status = 'pending' AND run_at <= ? AND claim = '' AND module || '.' || handler IN (" . implode(', ', array_fill(0, count($names), '?')) . ') ORDER BY run_at, id LIMIT 1');
                $stmt->execute([$now, ...$names]);
                $row = $stmt->fetch();
                if ($row === false) {
                    return null;
                }
                $spec = (array)json_decode((string)$row['spec'], true);
                if (self::repeats($spec)) {
                    // After a break only the latest missed time counts, and only while it is still fresh enough.
                    $last = self::latest($spec, (string)$row['zone'], (int)$row['due_at'], $now);
                    if ($last > (int)$row['due_at']) {
                        $row['due_at'] = $last;
                        $row['attempts'] = 0;
                    }
                    $row['expires_at'] = isset($spec['expires']) ? (int)$row['due_at'] + (int)$spec['expires'] : null;
                    if ($row['expires_at'] !== null && $row['expires_at'] <= $now) {
                        $missed = [$row, 'Пропущено: срок выполнения ' . self::format((int)$row['due_at'], (string)$row['zone']) . ' прошёл'];
                        self::moveOn($pdo, $row, $spec, $now, $missed[1]);
                        return false;
                    }
                } elseif ($row['expires_at'] !== null && (int)$row['expires_at'] <= $now) {
                    $missed = [$row, 'Срок годности прошёл до выполнения'];
                    self::finish($pdo, (int)$row['id'], 'expired', $missed[1], $now);
                    return false;
                }
                $lease = (int)($spec['lease'] ?? self::DEFAULT_LEASE);
                $pdo->prepare("UPDATE scheduled_tasks SET status = 'running', claim = ?, lease_until = ?, attempts = ?, due_at = ?, expires_at = ?, last_run_at = ?, updated_at = ? WHERE id = ?")
                    ->execute([$claim, $now + $lease, (int)$row['attempts'] + 1, $row['due_at'], $row['expires_at'], $now, $now, $row['id']]);
                return ['row' => ['attempts' => (int)$row['attempts'] + 1] + $row, 'spec' => $spec, 'claim' => $claim];
            });
        } finally {
            if (!is_array($taken)) {
                self::unlock($claim, $handle);
            }
        }
        if ($missed !== null) {
            self::missed(...$missed);
        }
        return is_array($taken) ? $taken + ['lock' => $handle] : $taken;
    }

    /** @param array{row:array<string,mixed>,spec:array<string,mixed>,claim:string,lock:mixed} $job True when the handler succeeded. */
    private static function execute(array $job, float $deadline): bool
    {
        ['row' => $row, 'spec' => $spec, 'claim' => $claim] = $job;
        $id = (int)$row['id'];
        $max = (int)($spec['attempts'] ?? self::DEFAULT_ATTEMPTS);
        $error = null;
        $pause = null;
        $giveUp = false;
        try {
            $lease = (int)($spec['lease'] ?? self::DEFAULT_LEASE);
            $task = new ScheduledTask(
                $id, (string)$row['module'], (string)$row['task_key'], (string)$row['handler'], (array)json_decode((string)$row['payload'], true),
                (int)$row['attempts'], $max, (int)$row['due_at'], $row['expires_at'] === null ? null : (int)$row['expires_at'],
                max(microtime(true) + 2.0, min($deadline, microtime(true) + $lease)), $claim, (int)$row['version'],
            );
            $handler = self::$handlers[$row['module'] . '.' . $row['handler']];
            Audit::during(Actor::system('планировщик'), static fn(): mixed => $handler($task));
        } catch (RetryLaterException $e) {
            $error = $e;
            $pause = max(1, min(86400, $e->seconds));
        } catch (GiveUpException $e) {
            $error = $e;
            $giveUp = true;
        } catch (Throwable $e) {
            $error = $e;
        }
        try {
            if ($error === null) {
                self::succeeded($row, $spec, $claim);
            } else {
                self::failed($row, $spec, $claim, self::clean($error->getMessage()), $giveUp, $pause);
            }
        } finally {
            self::unlock($claim, $job['lock']);
        }
        return $error === null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $spec
     */
    private static function succeeded(array $row, array $spec, string $claim): void
    {
        Database::write(static function (PDO $pdo) use ($row, $spec, $claim): void {
            $now = time();
            if (!self::release($pdo, $row, $claim)) {
                return;
            }
            if (self::repeats($spec)) {
                self::moveOn($pdo, $row, $spec, $now, '');
            } else {
                self::finish($pdo, (int)$row['id'], 'done', '', $now);
            }
        });
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $spec
     */
    private static function failed(array $row, array $spec, string $claim, string $error, bool $giveUp, ?int $pause): void
    {
        $final = Database::write(static function (PDO $pdo) use ($row, $spec, $claim, $error, $giveUp, $pause): bool {
            return self::release($pdo, $row, $claim) && self::afterFailure($pdo, $row, $spec, $error, $giveUp, $pause);
        });
        Logger::log($final ? 'error' : 'warning', 'scheduler.task_failed', ['module' => $row['module'], 'key' => $row['task_key'], 'attempt' => (int)$row['attempts'], 'final' => $final, 'error' => $error]);
        if ($final) {
            self::announce('task.failed', $row, $error);
        }
    }

    /**
     * Frees the row after its run. False when the module replaced or cancelled the task while it ran: the outcome of the old one
     * is of no interest then.
     *
     * @param array<string,mixed> $row
     */
    private static function release(PDO $pdo, array $row, string $claim): bool
    {
        $stmt = $pdo->prepare("UPDATE scheduled_tasks SET claim = '', lease_until = 0 WHERE id = ? AND claim = ?");
        $stmt->execute([$row['id'], $claim]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $pdo->prepare("DELETE FROM scheduled_tasks WHERE id = ? AND status = 'cancelled'")->execute([$row['id']]);
        $own = $pdo->prepare("SELECT 1 FROM scheduled_tasks WHERE id = ? AND version = ? AND status = 'running'");
        $own->execute([$row['id'], $row['version']]);
        return $own->fetchColumn() !== false;
    }

    /**
     * Decides what follows a failed attempt. True when no attempt is left for this time.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $spec
     */
    private static function afterFailure(PDO $pdo, array $row, array $spec, string $error, bool $giveUp, ?int $pause): bool
    {
        $now = time();
        $attempt = (int)$row['attempts'];
        $retry = (array)($spec['retry'] ?? self::DEFAULT_RETRY);
        $again = $now + ($pause ?? (int)$retry[min($attempt, count($retry)) - 1]);
        $expires = $row['expires_at'] === null ? null : (int)$row['expires_at'];
        $repeats = self::repeats($spec);
        $over = $giveUp || $attempt >= (int)($spec['attempts'] ?? self::DEFAULT_ATTEMPTS)
            || ($expires !== null && $again >= $expires)
            || ($repeats && $again >= self::next($spec, (string)$row['zone'], (int)$row['due_at'], (int)$row['due_at']));
        if (!$over) {
            $pdo->prepare("UPDATE scheduled_tasks SET status = 'pending', run_at = ?, last_error = ?, updated_at = ? WHERE id = ?")->execute([$again, $error, $now, $row['id']]);
            return false;
        }
        if ($repeats) {
            self::moveOn($pdo, $row, $spec, $now, $error);
        } else {
            self::finish($pdo, (int)$row['id'], 'failed', $error, $now);
        }
        return true;
    }

    private static function finish(PDO $pdo, int $id, string $status, string $error, int $now): void
    {
        $pdo->prepare('UPDATE scheduled_tasks SET status = ?, last_error = ?, finished_at = ?, updated_at = ? WHERE id = ?')->execute([$status, $error, $now, $now, $id]);
    }

    /**
     * A repeating task goes to its next time after now.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $spec
     */
    private static function moveOn(PDO $pdo, array $row, array $spec, int $now, string $error): void
    {
        $due = self::next($spec, (string)$row['zone'], (int)$row['due_at'], $now);
        $pdo->prepare("UPDATE scheduled_tasks SET status = 'pending', due_at = ?, run_at = ?, expires_at = ?, attempts = 0, last_error = ?, updated_at = ? WHERE id = ?")
            ->execute([$due, $due, isset($spec['expires']) ? $due + (int)$spec['expires'] : null, $error, $now, $row['id']]);
    }

    /**
     * Tasks whose lock time is over: their process died (the next attempt follows) or is still busy (it keeps the task).
     *
     * @return int how many ended as failed
     */
    private static function recover(): int
    {
        $failed = 0;
        foreach (Database::rows("SELECT * FROM scheduled_tasks WHERE claim <> '' AND lease_until <= ? ORDER BY lease_until LIMIT 100", [time()]) as $row) {
            $claim = (string)$row['claim'];
            if (self::alive($claim)) {
                Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE scheduled_tasks SET lease_until = ? WHERE id = ? AND claim = ?')->execute([time() + 60, $row['id'], $claim]));
                continue;
            }
            $spec = (array)json_decode((string)$row['spec'], true);
            $once = ($spec['once'] ?? false) === true;
            $error = 'Процесс прервался во время выполнения';
            $final = Database::write(static function (PDO $pdo) use ($row, $spec, $claim, $error, $once): ?bool {
                return self::release($pdo, $row, $claim) ? self::afterFailure($pdo, $row, $spec, $error, $once, null) : null;
            });
            @unlink(self::lockPath($claim));
            if ($final !== null) {
                Logger::log($final ? 'error' : 'warning', 'scheduler.task_abandoned', ['module' => $row['module'], 'key' => $row['task_key'], 'attempt' => (int)$row['attempts'], 'final' => $final]);
            }
            if ($final === true) {
                $failed++;
                self::announce('task.failed', $row, $error);
            }
        }
        return $failed;
    }

    /** One-time tasks past their expiry, also those of modules that are switched off. @return int how many */
    private static function expire(): int
    {
        $now = time();
        $rows = Database::rows("SELECT * FROM scheduled_tasks WHERE status = 'pending' AND run_at <= ? AND claim = '' AND expires_at IS NOT NULL AND expires_at <= ? LIMIT 500", [$now, $now]);
        $count = 0;
        foreach ($rows as $row) {
            if (self::repeats((array)json_decode((string)$row['spec'], true))) {
                continue; // claim() moves it to its next time
            }
            $error = (string)$row['last_error'] !== '' ? 'Срок годности прошёл. Последняя ошибка: ' . $row['last_error'] : 'Срок годности прошёл до выполнения';
            $done = Database::write(static function (PDO $pdo) use ($row, $error, $now): bool {
                $stmt = $pdo->prepare("UPDATE scheduled_tasks SET status = 'expired', last_error = ?, finished_at = ?, updated_at = ? WHERE id = ? AND version = ? AND status = 'pending' AND claim = ''");
                $stmt->execute([$error, $now, $now, $row['id'], $row['version']]);
                return $stmt->rowCount() > 0;
            });
            if ($done) {
                $count++;
                self::missed($row, $error);
            }
        }
        return $count;
    }

    /** Daily tasks follow a changed time zone of the site. */
    private static function followZone(): void
    {
        $zone = self::zone()->getName();
        foreach (Database::rows("SELECT * FROM scheduled_tasks WHERE zone <> '' AND zone <> ? AND status = 'pending' AND claim = '' LIMIT 500", [$zone]) as $row) {
            $spec = (array)json_decode((string)$row['spec'], true);
            if (!self::repeats($spec)) {
                continue;
            }
            $now = time();
            // A time that has already come is served as it is, only its next ones are in the new zone.
            $due = (int)$row['due_at'] <= $now ? (int)$row['due_at'] : self::next($spec, $zone, 0, $now);
            Database::write(static fn(PDO $pdo) => $pdo->prepare("UPDATE scheduled_tasks SET zone = ?, due_at = ?, run_at = MAX(?, CASE WHEN attempts > 0 THEN run_at ELSE 0 END), expires_at = ?, updated_at = ? WHERE id = ? AND version = ? AND status = 'pending' AND claim = ''")
                ->execute([$zone, $due, $due, isset($spec['expires']) ? $due + (int)$spec['expires'] : null, $now, $row['id'], $row['version']]));
        }
    }

    /** Once an hour: rows that finished long ago and lock files of processes that are gone. */
    private static function cleanup(): void
    {
        $stamp = self::dir() . '/.cleanup';
        $last = @filemtime($stamp);
        if ($last !== false && time() - $last < 3600) {
            return;
        }
        @touch($stamp);
        $now = time();
        Database::write(static function (PDO $pdo) use ($now): void {
            $pdo->prepare("DELETE FROM scheduled_tasks WHERE status = 'done' AND finished_at < ?")->execute([$now - self::KEEP_DONE]);
            $pdo->prepare("DELETE FROM scheduled_tasks WHERE status IN ('failed', 'expired') AND finished_at < ?")->execute([$now - self::KEEP_FAILED]);
            $pdo->prepare("DELETE FROM scheduled_tasks WHERE status = 'cancelled' AND claim = '' AND finished_at < ?")->execute([$now - 86400]);
        });
        foreach (glob(self::dir() . '/*.lock') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $now - $mtime > 86400 && !str_starts_with(basename($file), 'slot-') && !self::alive(basename($file, '.lock'))) {
                @unlink($file);
            }
        }
    }

    /** @param array<string,mixed> $row */
    private static function missed(array $row, string $error): void
    {
        Logger::log('warning', 'scheduler.expired', ['module' => $row['module'], 'key' => $row['task_key'], 'due_at' => (int)$row['due_at']]);
        self::announce('task.expired', $row, $error);
    }

    /** @param array<string,mixed> $row */
    private static function announce(string $event, array $row, string $error): void
    {
        EventBus::trigger($event, ['module' => (string)$row['module'], 'key' => (string)$row['task_key'], 'handler' => (string)$row['handler'], 'due_at' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['due_at']), 'attempts' => (int)$row['attempts'], 'error' => $error]);
    }

    // ---- locks ------------------------------------------------------------------------------------------------

    public static function dir(): string
    {
        $dir = AGECMS_DATA . '/scheduler';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AgeCMSException("Cannot create {$dir}");
        }
        return $dir;
    }

    /** @return resource|null one of the places for a working process */
    private static function slot()
    {
        for ($i = 1; $i <= self::SLOTS; $i++) {
            $handle = @fopen(self::dir() . "/slot-{$i}.lock", 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            fclose($handle);
        }
        return null;
    }

    private static function lockPath(string $claim): string
    {
        return self::dir() . '/' . $claim . '.lock';
    }

    /** @return resource held by this process while it works on a task; the system lets it go when the process dies */
    private static function lockFile(string $claim)
    {
        $handle = @fopen(self::lockPath($claim), 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new AgeCMSException('Cannot create a lock file in ' . self::dir());
        }
        return $handle;
    }

    /** @param resource $handle */
    private static function unlock(string $claim, $handle): void
    {
        @unlink(self::lockPath($claim));
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private static function alive(string $claim): bool
    {
        $handle = @fopen(self::lockPath($claim), 'r');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    // ---- times ------------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $spec */
    private static function repeats(array $spec): bool
    {
        return isset($spec['every']) || isset($spec['daily']);
    }

    /**
     * The first time of a repeating task strictly after $now.
     *
     * @param array<string,mixed> $spec
     */
    private static function next(array $spec, string $zone, int $due, int $now): int
    {
        if (isset($spec['every'])) {
            $step = (int)$spec['every'];
            return $due > $now ? $due : $due + (intdiv($now - $due, $step) + 1) * $step;
        }
        return self::daySlot($spec, $zone, $now, 1);
    }

    /**
     * The latest time of a repeating task at or before $now, never before $due.
     *
     * @param array<string,mixed> $spec
     */
    private static function latest(array $spec, string $zone, int $due, int $now): int
    {
        if ($due >= $now) {
            return $due;
        }
        if (isset($spec['every'])) {
            return $due + intdiv($now - $due, (int)$spec['every']) * (int)$spec['every'];
        }
        return max($due, self::daySlot($spec, $zone, $now, -1));
    }

    /**
     * Local calendar days are stepped through, not 86400 seconds: across a clock change the task stays at its time of day.
     *
     * @param array<string,mixed> $spec
     */
    private static function daySlot(array $spec, string $zone, int $now, int $step): int
    {
        try {
            $tz = new DateTimeZone($zone !== '' ? $zone : 'UTC');
        } catch (Throwable) {
            $tz = self::zone();
        }
        [$hour, $minute] = array_map('intval', explode(':', (string)$spec['daily']));
        $days = (array)($spec['days'] ?? [1, 2, 3, 4, 5, 6, 7]);
        $day = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        for ($i = 0; $i <= 8; $i++) {
            $at = $day->modify(($step * $i) . ' days')->setTime($hour, $minute);
            $ts = $at->getTimestamp();
            if (in_array((int)$at->format('N'), $days, true) && ($step > 0 ? $ts > $now : $ts <= $now)) {
                return $ts;
            }
        }
        return $now + $step * 86400; // unreachable: a week always has one of the days
    }

    public static function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string)Config::get('timezone', 'Europe/Moscow'));
        } catch (Throwable) {
            return new DateTimeZone('Europe/Moscow');
        }
    }

    /** How a repeating task repeats, in words for the admin; '' for a one-time task. @param array<string,mixed> $spec */
    public static function repeatText(array $spec): string
    {
        if (isset($spec['daily'])) {
            $names = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
            $days = isset($spec['days']) ? implode(', ', array_map(static fn(int $d): string => $names[$d] ?? '', (array)$spec['days'])) : 'каждый день';
            return "{$days} в {$spec['daily']}";
        }
        if (!isset($spec['every'])) {
            return '';
        }
        $s = (int)$spec['every'];
        return 'каждые ' . match (true) {
            $s % 86400 === 0 => intdiv($s, 86400) . ' дн.',
            $s % 3600 === 0 => intdiv($s, 3600) . ' ч',
            $s % 60 === 0 => intdiv($s, 60) . ' мин',
            default => $s . ' с',
        };
    }

    private static function format(int $time, string $zone): string
    {
        return (new DateTimeImmutable('@' . $time))->setTimezone($zone !== '' ? new DateTimeZone($zone) : self::zone())->format('d.m.Y H:i');
    }

    private static function time(int|DateTimeInterface $when): int
    {
        return is_int($when) ? $when : $when->getTimestamp();
    }

    // ---- checks -----------------------------------------------------------------------------------------------

    private static function checkNames(string $module, string $handler, ?string $key = null): void
    {
        $errors = [];
        if (preg_match('/^[a-z0-9_]{1,40}$/', $module) !== 1) {
            $errors[] = 'module is the name of the module folder';
        }
        if (preg_match('/^[a-z0-9_]{1,40}$/', $handler) !== 1) {
            $errors[] = 'handler is 1-40 of a-z 0-9 _';
        }
        if ($key !== null && preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $key) !== 1) {
            $errors[] = 'key is 1-120 of A-Z a-z 0-9 _ . : -';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Only settings that differ from the defaults are stored.
     *
     * @param array<string,mixed> $options
     * @param list<string> $allowed
     * @return array<string,mixed>
     */
    private static function spec(array $options, array $allowed): array
    {
        $errors = [];
        $spec = [];
        if (($unknown = array_diff(array_keys($options), $allowed)) !== []) {
            $errors[] = 'unknown options: ' . implode(', ', $unknown);
        }
        $int = static function (string $name, int $min, int $max) use ($options, &$spec, &$errors): void {
            if (!isset($options[$name])) {
                return;
            }
            if (!is_int($options[$name]) || $options[$name] < $min || $options[$name] > $max) {
                $errors[] = "{$name} is a number from {$min} to {$max}";
                return;
            }
            $spec[$name === 'expires_in' ? 'expires' : $name] = $options[$name];
        };
        $int('expires_in', 1, 10 * 366 * 86400);
        $int('attempts', 1, 20);
        $int('lease', 30, 3600);
        if (array_key_exists('expires_in', $options)) {
            $spec += ['expires' => null];
        }
        if (isset($options['retry'])) {
            $retry = array_values((array)$options['retry']);
            if ($retry === [] || count($retry) > 20 || array_filter($retry, static fn(mixed $s): bool => !is_int($s) || $s < 1 || $s > 86400) !== []) {
                $errors[] = 'retry is a pause of 1-86400 seconds or a list of them';
            }
            $spec['retry'] = $retry;
        }
        if (isset($options['once'])) {
            if (!is_bool($options['once'])) {
                $errors[] = 'once is true or false';
            } elseif ($options['once']) {
                $spec['once'] = true;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $spec;
    }

    /** An error text fit for the database and the admin: no bot tokens or keys from request addresses, one line, not too long. */
    private static function clean(string $error): string
    {
        $error = (string)preg_replace(['~bot\d{6,}:[A-Za-z0-9_-]+~', '~([?&](?:key|token|access_token|api_key|secret|password|pass)=)[^&\s"\']+~i', '~\b(Bearer)\s+\S+~i', '/\s+/u'], ['bot***', '$1***', '$1 ***', ' '], mb_scrub($error));
        return mb_substr(trim($error) !== '' ? trim($error) : 'Ошибка без текста', 0, 300);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>> with the keys spec (decoded), repeat (words) and handled (the module is there to run it)
     */
    private static function decorate(array $rows): array
    {
        SkillRegistry::boot();
        return array_map(static function (array $row): array {
            $row['spec'] = (array)json_decode((string)$row['spec'], true);
            $row['repeat'] = self::repeatText($row['spec']);
            $row['handled'] = isset(self::$handlers[$row['module'] . '.' . $row['handler']]);
            return $row;
        }, $rows);
    }
}
