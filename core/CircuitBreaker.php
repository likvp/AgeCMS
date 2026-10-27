<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/**
 * Stops mass edits. Within one session an actor may change at most `ratio` of the pages that existed
 * when the session started (and at least `min_touched` pages, so tiny sites stay editable).
 * Creating new pages is counted separately: it is additive and reversible, and is how a migration works.
 * On a trip the surrounding transaction rolls back, writes are frozen and the owner is notified.
 */
final class CircuitBreaker
{
    private const SESSION_TTL = 86400;

    public static function isFrozen(): bool
    {
        return Database::value("SELECT value FROM settings WHERE key = 'frozen'") !== null;
    }

    /** @return array{since:string,reason:string}|null */
    public static function frozenInfo(): ?array
    {
        $raw = Database::value("SELECT value FROM settings WHERE key = 'frozen'");
        return $raw === null ? null : json_decode((string)$raw, true);
    }

    public static function freeze(string $reason): void
    {
        $value = json_encode(['since' => ag_now(), 'reason' => $reason], JSON_UNESCAPED_UNICODE);
        Database::write(static fn(PDO $pdo) => $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('frozen', ?)")->execute([$value]));
    }

    public static function unfreeze(): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->exec("DELETE FROM settings WHERE key = 'frozen'"));
        Logger::log('info', 'circuit_breaker.unfrozen');
    }

    /**
     * Returns the session id for this actor, creating the row (and its page baseline) on first use.
     * Runs inside the writer's transaction.
     */
    public static function ensureSession(PDO $pdo, Actor $actor): string
    {
        $id = $actor->session ?? 'e-' . bin2hex(random_bytes(8));
        $stmt = $pdo->prepare('SELECT created_at FROM agent_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $created = $stmt->fetchColumn();
        if ($created !== false && strtotime((string)$created) < time() - self::SESSION_TTL) {
            $pdo->prepare('DELETE FROM session_pages WHERE session_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM agent_sessions WHERE id = ?')->execute([$id]);
            $created = false;
        }
        if ($created === false) {
            $baseline = (int)$pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn();
            $pdo->prepare('INSERT INTO agent_sessions (id, actor_type, actor_name, created_at, baseline_pages) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $actor->type, $actor->name, ag_now(), $baseline]);
        }
        return $id;
    }

    /** Registers a change to an existing page and throws when the session crossed the limit. */
    public static function guardModify(PDO $pdo, string $sessionId, string $slug): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO session_pages (session_id, page_slug) VALUES (?, ?)')->execute([$sessionId, $slug]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM session_pages WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $touched = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT baseline_pages FROM agent_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $baseline = max(1, (int)$stmt->fetchColumn());

        $ratio = (float)Config::get('circuit_breaker.ratio', 0.15);
        $min = (int)Config::get('circuit_breaker.min_touched', 3);
        if ($touched >= $min && $touched / $baseline > $ratio) {
            throw new CircuitBreakerException(sprintf(
                'Circuit breaker: this session changed %d of %d pages (%.0f%%, limit %.0f%%). The change was rolled back and writes are frozen.',
                $touched,
                $baseline,
                $touched / $baseline * 100,
                $ratio * 100,
            ));
        }
    }

    public static function guardCreate(PDO $pdo, string $sessionId): void
    {
        $pdo->prepare('UPDATE agent_sessions SET creates = creates + 1 WHERE id = ?')->execute([$sessionId]);
        $stmt = $pdo->prepare('SELECT creates FROM agent_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $creates = (int)$stmt->fetchColumn();
        $max = (int)Config::get('circuit_breaker.max_creates', 500);
        if ($creates > $max) {
            throw new CircuitBreakerException("Circuit breaker: this session created more than {$max} pages. The change was rolled back and writes are frozen.");
        }
    }

    /** Called after the transaction was rolled back. */
    public static function trip(Actor $actor, string $reason): void
    {
        self::freeze($reason);
        Logger::log('error', 'circuit_breaker.tripped', ['actor' => $actor->name, 'type' => $actor->type, 'session' => $actor->session, 'reason' => $reason]);
        EventBus::trigger('circuit_breaker.tripped', ['actor' => $actor->name, 'reason' => $reason]);
    }
}
