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

    /**
     * @param string $kind 'manual' (the button, bin/backup.php) or 'auto' (the safety copy made before an agent edits)
     */
    public static function create(string $kind = 'manual'): string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AgeCMSException("Cannot create {$dir}");
        }
        $stamp = ($kind === 'auto' ? 'auto-' : '') . gmdate('Ymd-His');
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
        self::prune($kind === 'auto' ? (int)Config::get('backup.keep_auto', 5) : (int)Config::get('backup.keep', 7), $kind);
        return $target;
    }

    /** @return list<array{file:string,size:int,created:string,auto:bool}> newest first */
    public static function list(?string $kind = null): array
    {
        $files = array_merge(glob(self::dir() . '/agecms-*.zip') ?: [], glob(self::dir() . '/agecms-*.sqlite.gz') ?: []);
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
        $rows = array_map(static fn(string $f): array => ['file' => basename($f), 'size' => (int)filesize($f), 'created' => gmdate('Y-m-d H:i:s', (int)filemtime($f)), 'auto' => str_starts_with(basename($f), 'agecms-auto-')], $files);
        return $kind === null ? $rows : array_values(array_filter($rows, static fn(array $r): bool => $r['auto'] === ($kind === 'auto')));
    }

    public static function prune(int $keep, string $kind = 'manual'): void
    {
        foreach (array_slice(self::list($kind), max(1, $keep)) as $old) {
            @unlink(self::dir() . '/' . $old['file']);
        }
    }

    /**
     * A copy of the database before an agent changes the site, once per agent session (token and hour) and at most
     * every five minutes. Switched off with backup.before_agent. A failure never blocks the edit.
     */
    public static function beforeAgentEdit(Actor $actor): void
    {
        if ($actor->type !== 'agent' || Config::get('backup.before_agent', true) !== true) {
            return;
        }
        $stamp = self::dir() . '/.auto';
        $last = is_file($stamp) ? (json_decode((string)@file_get_contents($stamp), true) ?: []) : [];
        if (($last['session'] ?? null) === $actor->session || time() - (int)($last['at'] ?? 0) < 300) {
            return;
        }
        try {
            if (!is_dir(self::dir())) {
                @mkdir(self::dir(), 0700, true);
            }
            @file_put_contents($stamp, json_encode(['session' => $actor->session, 'at' => time()]), LOCK_EX);
            self::create('auto');
        } catch (\Throwable $e) {
            Logger::log('warning', 'backup.auto_failed', ['error' => $e->getMessage()]);
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
