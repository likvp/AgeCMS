<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/**
 * Stops mass edits. Within one session an actor may change at most `ratio` of the pages that existed
 * when the session started (and at least `min_touched` pages, so tiny sites stay editable).
 * An agent session lasts an hour, so an agent is also counted over a rolling 24 hours across its sessions: spreading
 * a mass edit over several hours does not get around the limit.
 * Creating new pages is counted separately: it is additive and reversible, and is how a migration works.
 * Undoing a session (allowRollback + Content::rollbackSession) is not counted either: it removes only pages that session
 * created and nobody changed afterwards.
 * On a trip the surrounding transaction rolls back, writes are frozen and the owner is notified.
 */
final class CircuitBreaker
{
    private const SESSION_TTL = 86400;
    /** Most new pages one session may create after allowBulkCreate(). */
    public const BULK_MAX = 5000;
    /** How long a permission of allowRollback() lasts: a week, so a long rollback that goes on in portions can finish. */
    public const ROLLBACK_TTL = 604800;

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
        Audit::record(Audit::SYSTEM, 'Запись заморожена автоматически: ' . $reason, 'system:предохранитель');
    }

    public static function unfreeze(): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->exec("DELETE FROM settings WHERE key = 'frozen'"));
        Logger::log('info', 'circuit_breaker.unfrozen');
        Audit::record(Audit::SYSTEM, 'Снята заморозка записи');
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

    /**
     * Lets the session of a person (an administrator moving a site from another CMS) create up to $count more pages, at most
     * BULK_MAX in all, instead of circuit_breaker.max_creates. Only for an actor with system.manage that is not an agent token:
     * the limits of agents never change. The permission lives as long as the session (at most a day) and is written to the
     * history with the reason. In such a session an edit of a page the session itself created does not count towards the
     * share of changed pages: an import creates pages and then fixes links between them, that touches nothing that was there.
     * The actor must carry its session (Auth::actor() of the admin does; on the command line use Actor::withSession()).
     *
     * @return array{session:string,limit:int,created:int}
     */
    public static function allowBulkCreate(Actor $actor, int $count, string $reason): array
    {
        Security::requirePermission($actor, 'system.manage');
        if ($actor->type === 'agent') {
            throw new ForbiddenException('Bulk creation is allowed by a person only, an agent token keeps the usual limit');
        }
        if ($actor->session === null || $actor->session === '') {
            throw new ValidationException(['allowBulkCreate needs an actor with a session (Actor::withSession), the permission belongs to that session']);
        }
        if ($count < 1 || $count > self::BULK_MAX) {
            throw new ValidationException(['count must be between 1 and ' . self::BULK_MAX]);
        }
        $reason = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason));
        if ($reason === '') {
            throw new ValidationException(['Say why: the reason goes to the history']);
        }
        $reason = mb_substr($reason, 0, 200);
        return Database::write(static function (PDO $pdo) use ($actor, $count, $reason): array {
            $session = self::ensureSession($pdo, $actor);
            $stmt = $pdo->prepare('SELECT creates, creates_limit FROM agent_sessions WHERE id = ?');
            $stmt->execute([$session]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['creates' => 0, 'creates_limit' => 0];
            $limit = min(self::BULK_MAX, max((int)$row['creates_limit'], (int)$row['creates'] + $count));
            $pdo->prepare('UPDATE agent_sessions SET creates_limit = ? WHERE id = ?')->execute([$limit, $session]);
            Revisions::mark($pdo, Audit::SYSTEM, $actor, "Разрешено массовое создание страниц: до {$limit} в этой сессии вместо " . self::maxCreates() . ". Причина: {$reason}");
            Logger::log('info', 'circuit_breaker.bulk_allowed', ['actor' => $actor->name, 'session' => $session, 'limit' => $limit]);
            return ['session' => $session, 'limit' => $limit, 'created' => (int)$row['creates']];
        });
    }

    /**
     * Who stands behind an actor, for sessions that outlive a request: "tok-<id>" for an agent token (its hourly sessions
     * are tok-<id>-<hour>), "human:<name>", "cli:<name>", "system:<name>". Null for an agent without a token session.
     */
    public static function ownerKey(Actor $actor): ?string
    {
        if ($actor->type === 'agent') {
            return preg_match('/^tok-(\d+)(?:-|$)/', (string)$actor->session, $m) === 1 ? 'tok-' . $m[1] : null;
        }
        return $actor->type . ':' . $actor->name;
    }

    /**
     * Ties a working session that a module opens for a long job (the import "imp-<job>", written in portions by later
     * requests and the background tick) to the actor that started it, so that the same agent token can later undo it
     * (see allowRollback). The first claim stays: a session that already belongs to someone else, or that already has
     * history entries of another actor, cannot be claimed. Claiming again by the same owner changes nothing.
     */
    public static function claimSession(Actor $actor, string $session): void
    {
        Security::requirePermission($actor, 'content.write');
        self::checkSessionId($session);
        $owner = self::ownerKey($actor);
        if ($owner === null) {
            throw new ValidationException(['claimSession needs an agent with its token session or a person']);
        }
        Database::write(static function (PDO $pdo) use ($actor, $session, $owner): void {
            $stmt = $pdo->prepare('SELECT owner FROM session_owners WHERE session_id = ?');
            $stmt->execute([$session]);
            $current = $stmt->fetchColumn();
            if ($current !== false && $current !== '' && $current !== $owner) {
                throw new ForbiddenException("Session {$session} belongs to someone else");
            }
            if ($current === $owner) {
                return;
            }
            $stmt = $pdo->prepare('SELECT 1 FROM revisions WHERE session_id = ? AND (actor_type != ? OR actor_name != ?) LIMIT 1');
            $stmt->execute([$session, $actor->type, $actor->name]);
            if ($stmt->fetchColumn() !== false) {
                throw new ForbiddenException("Session {$session} already has changes of another actor");
            }
            if ($current === false) {
                $pdo->prepare('INSERT INTO session_owners (session_id, owner, actor_type, actor_name, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$session, $owner, $actor->type, $actor->name, ag_now()]);
            } else {
                $pdo->prepare('UPDATE session_owners SET owner = ?, actor_type = ?, actor_name = ? WHERE session_id = ?')->execute([$owner, $actor->type, $actor->name, $session]);
            }
        });
    }

    /** Whether $session is the actor's own: an hourly session of the same agent token, or a session it claimed. */
    public static function ownsSession(Actor $actor, string $session): bool
    {
        $owner = self::ownerKey($actor);
        if ($owner === null || $session === '') {
            return false;
        }
        if (preg_match('/^tok-(\d+)-/', $session, $m) === 1) {
            return $owner === 'tok-' . $m[1];
        }
        return Database::value('SELECT owner FROM session_owners WHERE session_id = ?', [$session]) === $owner;
    }

    /**
     * Opens the explicit way to undo what a session created: Content::rollbackSession() then moves pages created by $session
     * to the trash without counting them towards the share of changed pages (they did not exist before that session, so
     * removing them touches nothing that was there). A page that somebody else changed after it was created stays.
     * Who may: a person (or the command line) with system.manage; an agent only for a session of its own token
     * (ownsSession: its hourly session or one claimed with claimSession), never for a session of a person or another token.
     * Written to the history with the reason; valid ROLLBACK_TTL seconds, asking again renews it.
     *
     * @return array{session:string,pages:int,until:string}
     */
    public static function allowRollback(Actor $actor, string $session, string $reason): array
    {
        self::checkSessionId($session);
        if ($actor->type === 'agent') {
            Security::requirePermission($actor, 'content.write');
            if (!self::ownsSession($actor, $session)) {
                throw new ForbiddenException("Session {$session} was not started by this agent token: only an administrator can undo it");
            }
        } else {
            Security::requirePermission($actor, 'system.manage');
        }
        $reason = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason));
        if ($reason === '') {
            throw new ValidationException(['Say why: the reason goes to the history']);
        }
        $reason = mb_substr($reason, 0, 200);
        return Database::write(static function (PDO $pdo) use ($actor, $session, $reason): array {
            $stmt = $pdo->prepare('SELECT COUNT(DISTINCT r.page_slug) FROM revisions r JOIN pages p ON p.slug = r.page_slug WHERE r.session_id = ? AND r.action_desc = ?');
            $stmt->execute([$session, Content::CREATE_DESC]);
            $pages = (int)$stmt->fetchColumn();
            $now = ag_now();
            $by = $actor->type . ':' . $actor->name;
            $stmt = $pdo->prepare('UPDATE session_owners SET rollback_by = ?, rollback_at = ?, rollback_reason = ? WHERE session_id = ?');
            $stmt->execute([$by, $now, $reason, $session]);
            if ($stmt->rowCount() === 0) {
                $pdo->prepare("INSERT INTO session_owners (session_id, owner, actor_type, actor_name, created_at, rollback_by, rollback_at, rollback_reason) VALUES (?, '', '', '', ?, ?, ?, ?)")
                    ->execute([$session, $now, $by, $now, $reason]);
            }
            Revisions::mark($pdo, Audit::SYSTEM, $actor, "Разрешена отмена сессии {$session}: созданные ею страницы ({$pages}), которые потом не меняли другие, уходят в корзину вне лимита предохранителя. Причина: {$reason}");
            Logger::log('info', 'circuit_breaker.rollback_allowed', ['actor' => $actor->name, 'type' => $actor->type, 'session' => $session, 'pages' => $pages]);
            return ['session' => $session, 'pages' => $pages, 'until' => gmdate('Y-m-d\\TH:i:s\\Z', time() + self::ROLLBACK_TTL)];
        });
    }

    /**
     * Whether $actor may now remove pages of $session through Content::rollbackSession(): allowRollback() was called within
     * ROLLBACK_TTL, and the actor is the one who asked for it or works inside that very session (the job of a module going on
     * in later requests under the session it created the pages with). Runs inside the writer's transaction.
     */
    public static function rollbackAllowed(PDO $pdo, Actor $actor, string $session): bool
    {
        $stmt = $pdo->prepare('SELECT rollback_by, rollback_at FROM session_owners WHERE session_id = ?');
        $stmt->execute([$session]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['rollback_at'] === '' || strtotime((string)$row['rollback_at']) < time() - self::ROLLBACK_TTL) {
            return false;
        }
        return $actor->session === $session || $row['rollback_by'] === $actor->type . ':' . $actor->name;
    }

    private static function checkSessionId(string $session): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/', $session) !== 1) {
            throw new ValidationException(["'{$session}' is not a session id"]);
        }
    }

    private static function maxCreates(): int
    {
        return (int)Config::get('circuit_breaker.max_creates', 500);
    }

    /** Registers a change to an existing page and throws when the session crossed the limit. */
    public static function guardModify(PDO $pdo, string $sessionId, string $slug): void
    {
        if (self::createdInBulkSession($pdo, $sessionId, $slug)) {
            return;
        }
        $pdo->prepare('INSERT OR IGNORE INTO session_pages (session_id, page_slug) VALUES (?, ?)')->execute([$sessionId, $slug]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM session_pages WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $touched = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT baseline_pages, actor_type, actor_name FROM agent_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['baseline_pages' => 1, 'actor_type' => '', 'actor_name' => ''];
        $baseline = max(1, (int)$row['baseline_pages']);
        $window = '';

        // Rolling window: the same agent over the last 24 hours, whatever its hourly session was. The oldest baseline counts.
        if ($row['actor_type'] === 'agent') {
            $since = gmdate('Y-m-d\\TH:i:s\\Z', time() - self::SESSION_TTL);
            $stmt = $pdo->prepare('SELECT COUNT(DISTINCT sp.page_slug) FROM session_pages sp JOIN agent_sessions s ON s.id = sp.session_id WHERE s.actor_type = ? AND s.actor_name = ? AND s.created_at >= ?');
            $stmt->execute(['agent', $row['actor_name'], $since]);
            $windowTouched = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare('SELECT baseline_pages FROM agent_sessions WHERE actor_type = ? AND actor_name = ? AND created_at >= ? ORDER BY created_at LIMIT 1');
            $stmt->execute(['agent', $row['actor_name'], $since]);
            $windowBaseline = max(1, (int)$stmt->fetchColumn());
            if ($windowTouched > $touched) {
                $touched = $windowTouched;
                $baseline = $windowBaseline;
                $window = ' in 24 hours';
            }
        }

        $ratio = (float)Config::get('circuit_breaker.ratio', 0.15);
        $min = (int)Config::get('circuit_breaker.min_touched', 3);
        if ($touched >= $min && $touched / $baseline > $ratio) {
            throw new CircuitBreakerException(sprintf(
                'Circuit breaker: this %s changed %d of %d pages%s (%.0f%%, limit %.0f%%). The change was rolled back and writes are frozen.',
                $window === '' ? 'session' : 'agent',
                $touched,
                $baseline,
                $window,
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
        $stmt = $pdo->prepare('SELECT creates_limit FROM agent_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $max = max(self::maxCreates(), (int)$stmt->fetchColumn());
        if ($creates > $max) {
            throw new CircuitBreakerException("Circuit breaker: this session created more than {$max} pages. The change was rolled back and writes are frozen.");
        }
    }

    /** A page created by this very session, when the session was allowed a bulk creation (see allowBulkCreate). */
    private static function createdInBulkSession(PDO $pdo, string $sessionId, string $slug): bool
    {
        $stmt = $pdo->prepare('SELECT creates_limit FROM agent_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        if ((int)$stmt->fetchColumn() <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM revisions WHERE page_slug = ? AND session_id = ? AND action_desc = ? LIMIT 1');
        $stmt->execute([$slug, $sessionId, Content::CREATE_DESC]);
        return $stmt->fetchColumn() !== false;
    }

    /** Called after the transaction was rolled back. */
    public static function trip(Actor $actor, string $reason): void
    {
        self::freeze($reason);
        Logger::log('error', 'circuit_breaker.tripped', ['actor' => $actor->name, 'type' => $actor->type, 'session' => $actor->session, 'reason' => $reason]);
        EventBus::trigger('circuit_breaker.tripped', ['actor' => $actor->name, 'reason' => $reason]);
    }
}
