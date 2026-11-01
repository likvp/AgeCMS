<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Copy;

use AgeCMS\Modules\Importer\ImportException;
use AgeCMS\Modules\Importer\Runner;

/**
 * The capture of a site as a background process of its own. The web request that starts it only launches the tool with --detach
 * (the tool separates itself from the request and from the web server's process group, so the end of the request does not
 * end it) and returns; the process writes its progress into a status file, the job page reads that file.
 *
 * Files of a job (all under data/importer/job-N/):
 *   scout/status.json, capture/status.json   the status file of the process, written by the tool
 *   scout/run.log, capture/run.log           what the tool printed
 *   capture/pkg/                             the package the capture makes
 *   tmp/                                     HOME and TMPDIR of the process (profile of the browser); goes away with the job
 *
 * A process counts as alive while its heartbeat in the status file is fresh. A job whose process is gone without a final state
 * (the server was restarted, the process was killed) is marked as interrupted, never left to hang.
 */
final class CaptureJob
{
    /** A status file not updated for so long (seconds) belongs to a dead process. */
    public const STALE = 90;
    /** The tool must write its first status within this many seconds of the start. */
    public const START_WAIT = 45;

    public static function dir(int $jobId, string $kind): string
    {
        return Runner::dir($jobId) . '/' . ($kind === 'scout' ? 'scout' : 'capture');
    }

    public static function pkg(int $jobId): string
    {
        return self::dir($jobId, 'capture') . '/pkg';
    }

    public static function statusFile(int $jobId, string $kind): string
    {
        return self::dir($jobId, $kind) . '/status.json';
    }

    /**
     * Limits of a capture from the options of the job.
     *
     * @param array<string,mixed> $o
     * @return array{pages:int,mb:int,minutes:int,memory_mb:int,delay_ms:int}
     */
    public static function limits(array $o): array
    {
        return [
            'pages' => max(1, min(2000, (int)($o['limit'] ?? 50))),
            'mb' => max(10, min(20000, (int)($o['capture_mb'] ?? 500))),
            'minutes' => max(1, min(720, (int)($o['capture_minutes'] ?? 30))),
            'memory_mb' => max(512, min(32768, (int)($o['capture_memory_mb'] ?? 2048))),
            'delay_ms' => max(500, min(10000, (int)($o['delay_ms'] ?? 800))),
        ];
    }

    /**
     * Starts the tool in the background.
     *
     * @param array<string,mixed> $options options of the job
     * @return array{pid:int}
     */
    public static function start(int $jobId, string $url, array $options, string $kind, string $token): array
    {
        $problem = CaptureRunner::problem();
        if ($problem !== null) {
            throw new ImportException('Захват на сервере недоступен: ' . $problem . '.');
        }
        $dir = self::dir($jobId, $kind);
        $home = Runner::dir($jobId) . '/tmp';
        foreach ([$dir, $home] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
                throw new ImportException('Не получилось создать папку задания: ' . $d);
            }
        }
        @unlink(self::statusFile($jobId, $kind));
        $lim = self::limits($options);
        $args = [(string)CaptureRunner::node(), CaptureRunner::script(), $url, '--detach', '--log=' . $dir . '/run.log', '--out=' . $dir . '/' . ($kind === 'scout' ? 'out' : 'pkg'),
            '--status=' . self::statusFile($jobId, $kind), '--token=' . $token, '--url-only', '--no-cache', '--quiet',
            '--limit=' . $lim['pages'], '--delay=' . $lim['delay_ms'], '--max-total-mb=' . $lim['mb'], '--max-seconds=' . ($lim['minutes'] * 60),
            '--max-mem-mb=' . $lim['memory_mb'], '--min-free-mb=200', '--sandbox=' . (($options['capture_sandbox_fallback'] ?? true) ? 'auto' : 'on')];
        if ($kind === 'scout') {
            $args[] = '--scout';
            // A look at the home page must be quick.
            $args = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--max-seconds=')));
            $args[] = '--max-seconds=240';
        } else {
            if (($options['mobile'] ?? true) === false) {
                $args[] = '--no-mobile';
            }
            if (($options['capture_verify'] ?? true) === false) {
                $args[] = '--no-verify';
            }
        }
        if (($options['robots'] ?? true) === false) {
            $args[] = '--no-robots';
        }
        if (CaptureRunner::chrome() !== null) {
            $args[] = '--chrome=' . CaptureRunner::chrome();
        }
        if (CaptureRunner::allowPrivate()) {
            $args[] = '--allow-private';
        }
        $proc = @proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $dir . '/launch.log', 'a']], $pipes, dirname(CaptureRunner::script()), CaptureRunner::env($home, $home));
        if (!is_resource($proc)) {
            throw new ImportException('Не получилось запустить инструмент захвата.');
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $end = microtime(true) + 15;
        while (microtime(true) < $end) {
            $out .= (string)stream_get_contents($pipes[1]);
            if (str_contains($out, "\n") || !proc_get_status($proc)['running']) {
                break;
            }
            usleep(30000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);
        $pid = (int)(json_decode(trim($out), true)['pid'] ?? 0);
        if ($pid <= 0) {
            throw new ImportException('Инструмент захвата не запустился: ' . mb_substr(trim((string)@file_get_contents($dir . '/launch.log')) ?: trim($out), 0, 300));
        }
        return ['pid' => $pid];
    }

    /**
     * The status the tool wrote, with what the site adds: "alive" (the heartbeat is fresh), "age" (seconds since it).
     *
     * @return array<string,mixed>|null null while there is no status file yet
     */
    public static function status(int $jobId, string $kind): ?array
    {
        $file = self::statusFile($jobId, $kind);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        $st = $raw === false ? null : json_decode($raw, true);
        if (!is_array($st)) {
            return null;
        }
        $age = max(0, time() - (int)floor(((int)($st['updated'] ?? 0)) / 1000));
        $st['age'] = $age;
        $st['alive'] = ($st['state'] ?? '') === 'running' && $age < self::STALE;
        return $st;
    }

    /** Whether the process with this pid is the tool of this run (the pid may have been given to another program since). */
    public static function isOurs(int $pid, string $token): bool
    {
        if ($pid <= 1) {
            return false;
        }
        $r = CaptureRunner::exec(['ps', '-p', (string)$pid, '-o', 'command='], ['PATH' => '/usr/bin:/bin'], 5);
        $cmd = trim($r['out']);
        return $cmd !== '' && str_contains($cmd, 'capture') && ($token === '' || str_contains($cmd, '--token=' . $token));
    }

    private static function signal(int $pid, int $sig): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $sig);
            return;
        }
        CaptureRunner::exec(['kill', '-' . $sig, (string)$pid], ['PATH' => '/usr/bin:/bin'], 5);
    }

    /**
     * Stops the process: asks it to finish (it closes Chrome and writes the status "cancelled"), after a few seconds kills it and
     * its browser. $pidHint is the pid noted at the start. Safe to call for a process that is already gone.
     */
    public static function stop(int $jobId, string $kind, string $token, int $pidHint = 0): void
    {
        $st = self::status($jobId, $kind);
        // A process that was started a moment ago has not written its status yet: the pid noted at the start is then all there is.
        $pid = (int)($st['pid'] ?? 0) ?: $pidHint;
        if ($pid <= 0 || !self::isOurs($pid, $token)) {
            return;
        }
        self::signal($pid, 15);
        $end = microtime(true) + 6;
        while (microtime(true) < $end) {
            usleep(150000);
            if (!self::isOurs($pid, $token)) {
                return;
            }
        }
        // It did not leave: the browser under it first (its parent is the tool), then the tool.
        CaptureRunner::exec(['pkill', '-KILL', '-P', (string)$pid], ['PATH' => '/usr/bin:/bin'], 5);
        self::signal($pid, 9);
    }

    /** Removes the status of a stopped run so a new one starts clean. */
    public static function clear(int $jobId, string $kind): void
    {
        foreach (['status.json', 'status.json.tmp'] as $f) {
            @unlink(self::dir($jobId, $kind) . '/' . $f);
        }
    }
}
