<?php
declare(strict_types=1);

// php bin/cron.php            run everything that is due: tasks of modules, background.tick, the queue, pings, the page cache
// php bin/cron.php --line     print the lines for crontab and for /etc/cron.d
// php bin/cron.php --status   print when the scheduler ran last and what waits (exit code 1: cron is silent)
//
// For the system cron, once a minute:  * * * * * php /path/to/site/bin/cron.php >/dev/null 2>&1
// Safe to run at the same time several times: tasks are locked one by one, the rest has its own locks.
// Sites without cron work as before, from visits; cron is for work that has an exact time.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Cron, Database, Installer, Scheduler};

$opts = getopt('', ['line', 'status']);
if (isset($opts['line'])) {
    echo "crontab -e (пользователь " . Cron::owner() . "):\n" . Cron::crontabLine() . "\n\n/etc/cron.d:\n" . Cron::cronDLine() . "\n";
    exit(0);
}
if (!Installer::installed() || !Database::exists()) {
    fwrite(STDERR, "AgeCMS is not installed. Run: php bin/install.php\n");
    exit(1);
}

// Files made by root (the database journal, cached pages) would lock the site out of its own data: become the owner of data/.
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    $uid = (int)fileowner(AGECMS_DATA);
    $gid = (int)filegroup(AGECMS_DATA);
    if ($uid !== 0) {
        $name = posix_getpwuid($uid)['name'] ?? null;
        if (!posix_setgid($gid) || ($name !== null && !posix_initgroups($name, $gid)) || !posix_setuid($uid)) {
            fwrite(STDERR, 'Run it as the owner of the site: sudo -u ' . Cron::owner() . " php bin/cron.php\n");
            exit(1);
        }
    }
}

if (isset($opts['status'])) {
    $status = Cron::status();
    $counts = Scheduler::counts();
    $when = static fn(?int $t): string => $t === null ? '-' : (new DateTimeImmutable('@' . $t))->setTimezone(Scheduler::zone())->format('Y-m-d H:i:s');
    echo 'scheduler: ' . ['ok' => 'ok', 'warn' => 'silent', 'none' => 'never'][$status['level']] . "\n";
    foreach (Cron::state() as $source => $s) {
        echo "last {$source}: " . $when($s['at']) . ($s['user'] !== '' ? " ({$s['user']}, PHP {$s['php']})" : '') . "\n";
    }
    echo "waiting={$counts['waiting']} overdue={$counts['overdue']} running={$counts['running']} failed={$counts['failed']}\n";
    exit($status['level'] === 'ok' ? 0 : 1);
}

// 50 seconds: the next minute starts a process of its own, and a longer task does not hold it up.
$result = Cron::run('cron', 50.0);
if ($result === null) {
    echo "update in progress\n";
    exit(0);
}
$t = $result['tasks'] ?? ['done' => 0, 'failed' => 0, 'expired' => 0];
$b = $result['background'];
echo "tasks={$t['done']} failed={$t['failed']} expired={$t['expired']} jobs=" . ($b['jobs'] ?? 0) . ' pings=' . ($b['pings'] ?? 0) . ' rebuilt=' . ($b['rebuilt'] ?? 0) . ' ticked=' . (($b['ticked'] ?? false) ? 1 : 0) . "\n";
