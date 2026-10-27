<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;
use Throwable;

/** Versioned migrations tracked in PRAGMA user_version. */
final class Schema
{
    public static function migrate(PDO $pdo): void
    {
        $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        foreach (self::steps() as $target => $apply) {
            if ($target <= $version) {
                continue;
            }
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $apply($pdo);
                $pdo->exec('PRAGMA user_version = ' . $target);
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\PDOException) {
                    // Already ended by SQLite. PDO::inTransaction() is not reliable before PHP 8.4.
                }
                throw $e;
            }
        }
    }

    /** @return array<int,callable(PDO):void> */
    private static function steps(): array
    {
        return [
            1 => static function (PDO $pdo): void {
                $pdo->exec(<<<'SQL'
CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE pages (
    slug TEXT PRIMARY KEY,
    parent_slug TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL,
    meta_title TEXT NOT NULL DEFAULT '',
    meta_description TEXT NOT NULL DEFAULT '',
    canonical_url TEXT NOT NULL DEFAULT '',
    og_image TEXT NOT NULL DEFAULT '',
    is_noindex INTEGER NOT NULL DEFAULT 0,
    is_published INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    schema_type TEXT NOT NULL DEFAULT 'WebPage',
    theme TEXT NOT NULL DEFAULT '',
    blocks_json TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE menus (
    name TEXT PRIMARY KEY,
    items_json TEXT NOT NULL
);

CREATE TABLE revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_slug TEXT NOT NULL,
    created_at TEXT NOT NULL,
    actor_type TEXT NOT NULL CHECK (actor_type IN ('human', 'agent', 'system', 'cli')),
    actor_name TEXT NOT NULL,
    session_id TEXT,
    action_desc TEXT NOT NULL,
    patch_json TEXT NOT NULL,
    snapshot_json TEXT,
    prev_hash TEXT NOT NULL,
    hash TEXT NOT NULL
);
CREATE INDEX revisions_slug ON revisions (page_slug, id);

CREATE TRIGGER revisions_no_update BEFORE UPDATE ON revisions
BEGIN
    SELECT RAISE(ABORT, 'revisions is append-only: UPDATE is not allowed');
END;

CREATE TRIGGER revisions_no_delete BEFORE DELETE ON revisions
BEGIN
    SELECT RAISE(ABORT, 'revisions is append-only: DELETE is not allowed');
END;

CREATE TABLE agent_sessions (
    id TEXT PRIMARY KEY,
    actor_type TEXT NOT NULL,
    actor_name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    baseline_pages INTEGER NOT NULL DEFAULT 0,
    creates INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE session_pages (
    session_id TEXT NOT NULL,
    page_slug TEXT NOT NULL,
    PRIMARY KEY (session_id, page_slug)
) WITHOUT ROWID;

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    created_at TEXT NOT NULL
);

CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL UNIQUE,
    role TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_used_at TEXT,
    revoked_at TEXT
);

CREATE TABLE rate_limits (
    bucket TEXT NOT NULL,
    ts INTEGER NOT NULL
);
CREATE INDEX rate_limits_bucket ON rate_limits (bucket, ts);

CREATE TABLE leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    page_slug TEXT NOT NULL DEFAULT '',
    name TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    message TEXT NOT NULL DEFAULT '',
    ip_hash TEXT NOT NULL DEFAULT ''
);

CREATE TABLE analytics_daily (
    date TEXT PRIMARY KEY,
    views INTEGER NOT NULL DEFAULT 0,
    unique_visitors INTEGER NOT NULL DEFAULT 0,
    leads_count INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE visitors_daily (
    day TEXT NOT NULL,
    hash TEXT NOT NULL,
    PRIMARY KEY (day, hash)
) WITHOUT ROWID;

CREATE TABLE cache_tags (
    tag TEXT NOT NULL,
    slug TEXT NOT NULL,
    PRIMARY KEY (tag, slug)
) WITHOUT ROWID;
CREATE INDEX cache_tags_slug ON cache_tags (slug);
SQL);
                // FTS5 may be missing on exotic builds; search then falls back to LIKE.
                try {
                    $pdo->exec('CREATE VIRTUAL TABLE pages_fts USING fts5(slug UNINDEXED, title, body)');
                } catch (Throwable) {
                    $pdo->exec("INSERT INTO settings (key, value) VALUES ('fts', '0')");
                }
            },
        ];
    }
}
