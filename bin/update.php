<?php
declare(strict_types=1);

// Core updates from the command line, the same as «Обновить» in «Система».
//   php bin/update.php              check the source (updates.url) and say whether a newer core is out
//   php bin/update.php --install    download, verify the signature and install it
//   php bin/update.php --rollback   put back the files saved before the last update (the database stays as it is)
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Updater, Updates};

$args = array_slice($argv, 1);
try {
    if (in_array('--rollback', $args, true)) {
        $done = Updater::rollback();
        echo "Files of AgeCMS {$done['version']} are back ({$done['files']}).\n";
        exit(0);
    }
    if (Updates::sourceUrl() === '') {
        throw new RuntimeException('updates.url is empty: php bin/config.php set updates.url https://.../release.json');
    }
    $state = Updates::check();
    if ($state['error'] !== '') {
        throw new RuntimeException($state['error']);
    }
    $newer = Updates::available();
    echo 'Installed: ' . Updates::version() . ', latest: ' . ($state['latest']['version'] ?? '?') . "\n";
    if ($newer === null || !in_array('--install', $args, true)) {
        echo $newer === null ? "Up to date.\n" : "Run with --install to update.\n";
        exit(0);
    }
    $done = Updater::install(null, 'cli');
    echo "Updated {$done['from']} -> {$done['to']}, {$done['files']} files. Saved: {$done['rollback']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
