<?php
declare(strict_types=1);

// php bin/backup.php [--telegram]   snapshot data.sqlite into data/backups/, optionally send it to Telegram
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Backup, Config};

try {
    $file = Backup::create();
    AgeCMS\Audit::record(AgeCMS\Audit::BACKUP, 'Создан бэкап ' . basename($file));
    echo "Backup: {$file}\n";
    if (in_array('--telegram', $argv, true) || Config::get('backup.telegram', false)) {
        echo Backup::sendTelegram($file) ? "Sent to Telegram.\n" : "Telegram not configured or the send failed.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
