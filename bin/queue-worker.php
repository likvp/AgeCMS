<?php
declare(strict_types=1);

// Optional: the site does this by itself after saves and on visits (core/Background.php).
// Drains the write queue, sends IndexNow pings and rebuilds invalidated pages. It does not run the tasks of modules or
// background.tick: for the system cron use bin/cron.php, which does all of it.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Cache, Queue, Seo};

$jobs = Queue::drain(2000);
$pings = Seo::flushPings();
$pages = Cache::processRebuildQueue();
echo "jobs={$jobs} pings={$pings} rebuilt={$pages}\n";
