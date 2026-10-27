<?php
declare(strict_types=1);

// php bin/rebuild-cache.php          renders pages queued by earlier invalidations
// php bin/rebuild-cache.php --all    renders every published page
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Cache, Pages};

if (in_array('--all', $argv, true)) {
    $done = 0;
    foreach (Pages::listing(true) as $page) {
        $done += Cache::warm($page['slug']) ? 1 : 0;
    }
} else {
    $done = Cache::processRebuildQueue();
}
echo "Rebuilt {$done} pages.\n";
