<?php
declare(strict_types=1);

// Cron worker, e.g. every minute: * * * * * php /path/bin/queue-worker.php
// Drains the write queue, sends IndexNow pings and rebuilds invalidated pages.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Cache, Queue, Seo};

$jobs = Queue::drain(2000);
$pings = Seo::flushPings();
$pages = Cache::processRebuildQueue();
echo "jobs={$jobs} pings={$pings} rebuilt={$pages}\n";
