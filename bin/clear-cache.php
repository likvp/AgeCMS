<?php
declare(strict_types=1);

// Removes every cached page. They are rebuilt on the next visit (or by bin/rebuild-cache.php --all).
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

echo 'Removed ' . AgeCMS\Cache::clearAll() . " cached files.\n";
