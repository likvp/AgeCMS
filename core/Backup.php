<?php
declare(strict_types=1);

namespace AgeCMS;

use ZipArchive;

/**
 * Consistent database snapshots (VACUUM INTO, safe while the site is live), zipped into data/backups/.
 * data/config.php is not included: it holds the secrets and stays on the server.
 */
final class Backup
{
    public static function dir(): string
    {
        return AGECMS_DATA . '/backups';
    }

    public static function create(): string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AgeCMSException("Cannot create {$dir}");
        }
        $stamp = gmdate('Ymd-His');
        $snapshot = "{$dir}/agecms-{$stamp}.sqlite";
        Database::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $snapshot) . "'");

        if (class_exists(ZipArchive::class)) {
            $target = "{$dir}/agecms-{$stamp}.zip";
            $zip = new ZipArchive();
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                @unlink($snapshot);
                throw new AgeCMSException('Cannot create zip archive');
            }
            $zip->addFile($snapshot, 'data.sqlite');
            $zip->close();
        } else {
            $target = "{$dir}/agecms-{$stamp}.sqlite.gz";
            file_put_contents($target, gzencode((string)file_get_contents($snapshot), 9));
        }
        @unlink($snapshot);
        @chmod($target, 0600);
        self::prune((int)Config::get('backup.keep', 7));
        return $target;
    }

    /** @return list<array{file:string,size:int,created:string}> newest first */
    public static function list(): array
    {
        $files = array_merge(glob(self::dir() . '/agecms-*.zip') ?: [], glob(self::dir() . '/agecms-*.sqlite.gz') ?: []);
        rsort($files, SORT_STRING);
        return array_map(static fn(string $f): array => ['file' => basename($f), 'size' => (int)filesize($f), 'created' => gmdate('Y-m-d H:i:s', (int)filemtime($f))], $files);
    }

    public static function prune(int $keep): void
    {
        foreach (array_slice(self::list(), max(1, $keep)) as $old) {
            @unlink(self::dir() . '/' . $old['file']);
        }
    }

    /** Sends an archive to the configured Telegram chat. The archive contains user hashes and leads: use a private chat. */
    public static function sendTelegram(string $path): bool
    {
        $token = (string)Config::get('secrets.telegram_bot_token', '');
        $chat = (string)Config::get('secrets.telegram_chat_id', '');
        if ($token === '' || $chat === '' || !is_file($path) || filesize($path) > 50 * 1024 * 1024) {
            return false;
        }
        $result = Http::postForm("https://api.telegram.org/bot{$token}/sendDocument", [
            'chat_id' => $chat,
            'caption' => 'Бэкап AgeCMS ' . gmdate('Y-m-d H:i') . ' UTC',
            'document' => new \CURLFile($path),
        ], 60);
        return $result !== null && $result['status'] === 200;
    }
}
