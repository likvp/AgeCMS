<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/**
 * Time-Travel ledger. Every change stores an RFC 6902 patch (before -> after) and,
 * every CHECKPOINT_EVERY revisions of a page, a full snapshot so reading stays cheap.
 * Rows are chained by hash and protected from UPDATE/DELETE by SQLite triggers.
 */
final class Revisions
{
    public const CHECKPOINT_EVERY = 25;
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * Must run inside Database::write(). Returns the new revision id, or null when nothing changed.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function record(PDO $pdo, string $slug, Actor $actor, string $description, array $before, array $after): ?int
    {
        $patch = JsonPatch::diff($before, $after);
        if ($patch === []) {
            return null;
        }
        $patchJson = json_encode($patch, self::JSON);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM revisions WHERE page_slug = ?');
        $stmt->execute([$slug]);
        $count = (int)$stmt->fetchColumn();
        $snapshot = ($count === 0 || $count % self::CHECKPOINT_EVERY === 0) ? json_encode($after, self::JSON) : null;

        $prev = $pdo->query('SELECT hash FROM revisions ORDER BY id DESC LIMIT 1')->fetchColumn();
        $prev = $prev === false ? self::GENESIS : (string)$prev;
        $now = ag_now();
        $hash = self::hash($prev, $slug, $now, $actor->type, $actor->name, $description, $patchJson);

        $pdo->prepare(
            'INSERT INTO revisions (page_slug, created_at, actor_type, actor_name, session_id, action_desc, patch_json, snapshot_json, prev_hash, hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$slug, $now, $actor->type, $actor->name, $actor->session, $description, $patchJson, $snapshot, $prev, $hash]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Document as it was right after revision $revisionId.
     *
     * @return array<string,mixed>
     */
    public static function stateAt(string $slug, int $revisionId): array
    {
        $head = Database::row('SELECT id FROM revisions WHERE page_slug = ? AND id = ?', [$slug, $revisionId]);
        if ($head === null) {
            throw new NotFoundException("Revision {$revisionId} of '{$slug}' not found");
        }
        $checkpoint = Database::value(
            'SELECT MAX(id) FROM revisions WHERE page_slug = ? AND id <= ? AND snapshot_json IS NOT NULL',
            [$slug, $revisionId],
        );
        $rows = Database::rows(
            'SELECT patch_json, snapshot_json FROM revisions WHERE page_slug = ? AND id >= ? AND id <= ? ORDER BY id',
            [$slug, (int)($checkpoint ?? 0), $revisionId],
        );
        $state = [];
        foreach ($rows as $i => $row) {
            if ($i === 0 && $row['snapshot_json'] !== null) {
                $state = json_decode((string)$row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
                continue;
            }
            $state = JsonPatch::apply($state, json_decode((string)$row['patch_json'], true, 512, JSON_THROW_ON_ERROR));
        }
        return $state;
    }

    /** @return list<array<string,mixed>> */
    public static function history(string $slug, int $limit = 50): array
    {
        return Database::rows(
            'SELECT id, created_at, actor_type, actor_name, session_id, action_desc FROM revisions WHERE page_slug = ? ORDER BY id DESC LIMIT ?',
            [$slug, max(1, min(500, $limit))],
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 50): array
    {
        return Database::rows(
            'SELECT id, page_slug, created_at, actor_type, actor_name, action_desc FROM revisions ORDER BY id DESC LIMIT ?',
            [max(1, min(500, $limit))],
        );
    }

    /**
     * Recomputes every hash. A broken link means rows were edited outside the application
     * (for example by dropping the triggers).
     *
     * @return array{ok:bool,checked:int,broken_at:?int}
     */
    public static function verifyChain(): array
    {
        $stmt = Database::pdo()->query('SELECT id, page_slug, created_at, actor_type, actor_name, action_desc, patch_json, prev_hash, hash FROM revisions ORDER BY id');
        $prev = self::GENESIS;
        $checked = 0;
        foreach ($stmt as $row) {
            $expected = self::hash($prev, $row['page_slug'], $row['created_at'], $row['actor_type'], $row['actor_name'], $row['action_desc'], $row['patch_json']);
            if ($row['prev_hash'] !== $prev || $row['hash'] !== $expected) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int)$row['id']];
            }
            $prev = $row['hash'];
            $checked++;
        }
        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }

    private static function hash(string $prev, string $slug, string $at, string $type, string $name, string $desc, string $patch): string
    {
        return hash('sha256', implode("\x1f", [$prev, $slug, $at, $type, $name, $desc, $patch]));
    }
}
