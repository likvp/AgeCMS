<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\Actor;
use AgeCMS\Database;
use PDO;

/**
 * Storage of import jobs and their pages (tables mod_importer_jobs, mod_importer_items and mod_importer_media, created on the first import).
 * Statuses of a job: analyzing, ready, applying, done, rolling_back, rolled_back, failed.
 * Statuses of a page: new (found), pending (to create), done, skipped, failed, rolled_back, kept (left on rollback).
 */
final class Jobs
{
    public const ACTIVE = ['analyzing', 'applying', 'rolling_back'];

    /**
     * The tables are created on the first import, not by a module migration: a migration runs on every site that has
     * the module and leaves an entry in the history even where nobody ever imports anything.
     */
    private const SCHEMA = <<<'SQL'
-- Import jobs: one row per import (a site address or an export file), its state between steps and the result.
CREATE TABLE mod_importer_jobs (
    id INTEGER PRIMARY KEY,
    source TEXT NOT NULL,
    source_type TEXT NOT NULL,
    platform TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    options_json TEXT NOT NULL DEFAULT '{}',
    detect_json TEXT NOT NULL DEFAULT '{}',
    state_json TEXT NOT NULL DEFAULT '{}',
    site_json TEXT NOT NULL DEFAULT '{}',
    plan_json TEXT NOT NULL DEFAULT '{}',
    result_json TEXT NOT NULL DEFAULT '{}',
    actor_json TEXT NOT NULL DEFAULT '{}',
    token TEXT NOT NULL,
    error TEXT NOT NULL DEFAULT '',
    lock_until INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

-- Pages of a job: the page in the neutral format, its new address, the document to create and what was lost.
CREATE TABLE mod_importer_items (
    id INTEGER PRIMARY KEY,
    job_id INTEGER NOT NULL,
    seq INTEGER NOT NULL DEFAULT 0,
    path_key TEXT NOT NULL,
    old_path TEXT NOT NULL,
    page_json TEXT NOT NULL,
    selected INTEGER NOT NULL DEFAULT 1,
    action TEXT NOT NULL DEFAULT 'create',
    new_slug TEXT NOT NULL DEFAULT '',
    plan_json TEXT NOT NULL DEFAULT '{}',
    doc_json TEXT NOT NULL DEFAULT '{}',
    losses_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'new',
    revision_id INTEGER,
    before_json TEXT NOT NULL DEFAULT '',
    error TEXT NOT NULL DEFAULT '',
    UNIQUE (job_id, path_key)
);
CREATE INDEX mod_importer_items_job ON mod_importer_items (job_id, status, seq);

-- Pictures already brought into the media library: the same address is not downloaded twice.
CREATE TABLE mod_importer_media (
    url TEXT PRIMARY KEY,
    path TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL;

    /** Creates the tables when they are not there yet. */
    public static function ensure(): void
    {
        if (self::exists()) {
            return;
        }
        Database::write(static function (PDO $pdo): void {
            foreach (array_filter(array_map('trim', explode(';', (string)preg_replace('~^--.*$~m', '', self::SCHEMA)))) as $statement) {
                $pdo->exec(str_replace(['CREATE TABLE ', 'CREATE INDEX '], ['CREATE TABLE IF NOT EXISTS ', 'CREATE INDEX IF NOT EXISTS '], $statement));
            }
        });
    }

    /** Whether an import ever ran here (the tables exist). Reads on a site without them return nothing. */
    public static function exists(): bool
    {
        try {
            return Database::value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'mod_importer_jobs'") !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private const JSON = ['options', 'detect', 'state', 'site', 'plan', 'result', 'actor'];
    private const ITEM_JSON = ['page', 'plan', 'doc', 'losses'];

    /** @param array<string,mixed> $options */
    public static function create(string $source, string $type, array $options, Actor $actor, string $platform = ''): int
    {
        self::ensure();
        $now = ag_now();
        return (int)Database::write(static function (PDO $pdo) use ($source, $type, $options, $actor, $platform, $now): int {
            $pdo->prepare('INSERT INTO mod_importer_jobs (source, source_type, platform, status, options_json, actor_json, token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$source, $type, $platform, 'analyzing', self::enc($options), self::enc(['type' => $actor->type, 'name' => $actor->name, 'role' => $actor->role]), bin2hex(random_bytes(16)), $now, $now]);
            return (int)$pdo->lastInsertId();
        });
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        if (!self::exists()) {
            return null;
        }
        $row = Database::row('SELECT * FROM mod_importer_jobs WHERE id = ?', [$id]);
        return $row === null ? null : self::decode($row);
    }

    /** @param array<string,mixed> $fields plain columns or the json ones by short name (state, plan...) */
    public static function update(int $id, array $fields): void
    {
        $set = [];
        $values = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, self::JSON, true)) {
                $set[] = "{$k}_json = ?";
                $values[] = self::enc($v);
            } else {
                $set[] = "{$k} = ?";
                $values[] = $v;
            }
        }
        $set[] = 'updated_at = ?';
        $values[] = ag_now();
        $values[] = $id;
        Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE mod_importer_jobs SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values));
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        if (!self::exists()) {
            return [];
        }
        return array_map([self::class, 'decode'], Database::rows('SELECT * FROM mod_importer_jobs ORDER BY id DESC LIMIT ?', [$limit]));
    }

    /** The oldest job that has work to do, for the background tick. */
    public static function active(): ?array
    {
        if (!self::exists()) {
            return null;
        }
        $row = Database::row("SELECT * FROM mod_importer_jobs WHERE status IN ('analyzing', 'applying', 'rolling_back') AND lock_until < ? ORDER BY id LIMIT 1", [time()]);
        return $row === null ? null : self::decode($row);
    }

    /** Takes the job for $seconds; false when another request works on it. */
    public static function lock(int $id, int $seconds): bool
    {
        return (bool)Database::write(static function (PDO $pdo) use ($id, $seconds): bool {
            $stmt = $pdo->prepare('UPDATE mod_importer_jobs SET lock_until = ? WHERE id = ? AND lock_until < ?');
            $stmt->execute([time() + $seconds, $id, time()]);
            return $stmt->rowCount() === 1;
        });
    }

    public static function unlock(int $id): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE mod_importer_jobs SET lock_until = 0 WHERE id = ?')->execute([$id]));
    }

    /** Adds a found page; a second page with the same old address is ignored. @param array<string,mixed> $page */
    public static function addItem(int $jobId, array $page): bool
    {
        $key = isset($page['section']) && $page['section'] === true ? (string)$page['id'] : Url::key((string)$page['old_path']);
        return (bool)Database::write(static function (PDO $pdo) use ($jobId, $page, $key): bool {
            $seq = (int)$pdo->query('SELECT COALESCE(MAX(seq), 0) + 1 FROM mod_importer_items WHERE job_id = ' . (int)$jobId)->fetchColumn();
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO mod_importer_items (job_id, seq, path_key, old_path, page_json) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$jobId, $seq, $key, (string)$page['old_path'], self::enc($page)]);
            return $stmt->rowCount() === 1;
        });
    }

    /** @return list<array<string,mixed>> */
    public static function items(int $jobId, ?array $statuses = null, string $order = 'seq'): array
    {
        $sql = 'SELECT * FROM mod_importer_items WHERE job_id = ?';
        $params = [$jobId];
        if ($statuses !== null) {
            $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            $params = array_merge($params, $statuses);
        }
        $sql .= $order === 'seq_desc' ? ' ORDER BY seq DESC' : ' ORDER BY seq';
        return array_map([self::class, 'decodeItem'], Database::rows($sql, $params));
    }

    public static function countItems(int $jobId, ?array $statuses = null): int
    {
        $sql = 'SELECT COUNT(*) FROM mod_importer_items WHERE job_id = ?';
        $params = [$jobId];
        if ($statuses !== null) {
            $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            $params = array_merge($params, $statuses);
        }
        return (int)Database::value($sql, $params);
    }

    /** @param array<string,mixed> $fields */
    public static function updateItem(int $id, array $fields): void
    {
        $set = [];
        $values = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, self::ITEM_JSON, true)) {
                $set[] = "{$k}_json = ?";
                $values[] = self::enc($v);
            } else {
                $set[] = "{$k} = ?";
                $values[] = $v;
            }
        }
        $values[] = $id;
        Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE mod_importer_items SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values));
    }

    public static function deleteItems(int $jobId, string $pathKeyLike): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('DELETE FROM mod_importer_items WHERE job_id = ? AND path_key LIKE ?')->execute([$jobId, $pathKeyLike]));
    }

    public static function delete(int $id): void
    {
        Database::write(static function (PDO $pdo) use ($id): void {
            $pdo->prepare('DELETE FROM mod_importer_items WHERE job_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM mod_importer_jobs WHERE id = ?')->execute([$id]);
        });
    }

    /** Address of a page the importer created and then removed on rollback: it may be used again. */
    public static function rolledBack(string $slug): bool
    {
        if (!self::exists()) {
            return false;
        }
        return Database::value("SELECT 1 FROM mod_importer_items WHERE new_slug = ? AND status = 'rolled_back' LIMIT 1", [$slug]) !== null;
    }

    public static function mediaPath(string $url): ?string
    {
        self::ensure();
        $v = Database::value('SELECT path FROM mod_importer_media WHERE url = ?', [$url]);
        return $v === null ? null : (string)$v;
    }

    public static function rememberMedia(string $url, string $path): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('INSERT OR REPLACE INTO mod_importer_media (url, path, created_at) VALUES (?, ?, ?)')->execute([$url, $path, ag_now()]));
    }

    /** @param array<string,mixed> $row */
    private static function decode(array $row): array
    {
        foreach (self::JSON as $k) {
            $row[$k] = json_decode((string)($row[$k . '_json'] ?? '{}'), true) ?: [];
            unset($row[$k . '_json']);
        }
        $row['id'] = (int)$row['id'];
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function decodeItem(array $row): array
    {
        foreach (self::ITEM_JSON as $k) {
            $row[$k] = json_decode((string)($row[$k . '_json'] ?? '{}'), true) ?: [];
            unset($row[$k . '_json']);
        }
        $row['id'] = (int)$row['id'];
        $row['job_id'] = (int)$row['job_id'];
        $row['selected'] = (int)$row['selected'];
        return $row;
    }

    private static function enc(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }
}
