<?php
declare(strict_types=1);

// Removes every cached page. They are rebuilt on the next visit (or by bin/rebuild-cache.php --all).
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

$removed = AgeCMS\Cache::clearAll();
AgeCMS\Audit::record(AgeCMS\Audit::SYSTEM, "Очищен кэш страниц (файлов: {$removed})");
echo "Removed {$removed} cached files.\n";
