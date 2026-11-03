<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/**
 * The owner's control over agent tokens, the same as over people: see who has access and what each agent did,
 * freeze a token for a while, block it until lifted, lower its role, take it away for good. And one switch that
 * freezes every agent at once. Issuing tokens stays in Auth.
 */
final class Tokens
{
    /** Freeze terms offered in the admin, hours => label. */
    public const TERMS = [1 => '1 час', 24 => 'сутки', 168 => '7 дней', 720 => '30 дней'];

    public const STATUS_LABELS = [
        'active' => ['работает', 'ok'],
        'frozen' => ['заморожен', 'info'],
        'blocked' => ['заблокирован', 'err'],
        'user_blocked' => ['пользователь заблокирован', 'err'],
        'expired' => ['истёк', 'warn'],
        'revoked' => ['отозван', 'plain'],
    ];

    /**
     * What a token can do right now. A revoked token stays revoked; a blocked one stays blocked even after a freeze ends.
     *
     * @param array<string,mixed> $t a row of api_tokens, with `user_blocked` (bool) when the token belongs to a person
     */
    public static function status(array $t): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return match (true) {
            ($t['revoked_at'] ?? null) !== null => 'revoked',
            ($t['blocked_at'] ?? null) !== null => 'blocked',
            !empty($t['user_blocked']) => 'user_blocked',
            ($t['frozen_until'] ?? null) !== null && (string)$t['frozen_until'] > $now => 'frozen',
            ($t['expires_at'] ?? null) !== null && (string)$t['expires_at'] < $now => 'expired',
            default => 'active',
        };
    }

    /**
     * Every token with its owner user, status and how many changes it made in the last 30 days.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $rows = Database::rows(
            'SELECT t.id, t.name, t.role, t.created_at, t.last_used_at, t.last_ip, t.revoked_at, t.expires_at, t.frozen_until, t.blocked_at,
                    t.hold_reason, t.hold_by, t.user_id, u.username, (t.user_id IS NOT NULL AND (u.id IS NULL OR u.blocked_at IS NOT NULL)) AS user_blocked
             FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.revoked_at IS NOT NULL, t.id DESC',
        );
        $counts = [];
        foreach (Database::rows("SELECT actor_name, COUNT(*) AS n FROM revisions WHERE actor_type = 'agent' AND created_at >= ? GROUP BY actor_name", [gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400)]) as $c) {
            $counts[$c['actor_name']] = (int)$c['n'];
        }
        foreach ($rows as &$r) {
            $r['status'] = self::status($r);
            $r['actions_30d'] = $counts[$r['name']] ?? 0;
        }
        unset($r);
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        foreach (self::all() as $t) {
            if ((int)$t['id'] === $id) {
                return $t;
            }
        }
        return null;
    }

    /**
     * What the agent behind a token did: the journal entries made with it, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function actions(string $name, int $limit = 100): array
    {
        return Database::rows("SELECT id, created_at, page_slug, action_desc FROM revisions WHERE actor_type = 'agent' AND actor_name = ? ORDER BY id DESC LIMIT " . max(1, min(500, $limit)), [$name]);
    }

    /** Stops the token for some hours. The agent hears when it may come back; the freeze ends by itself. */
    public static function freeze(int $id, int $hours, string $reason = ''): void
    {
        if ($hours < 1 || $hours > 24 * 365) {
            throw new ValidationException(['Заморозить можно на срок от 1 часа до года']);
        }
        $until = gmdate('Y-m-d\TH:i:s\Z', time() + $hours * 3600);
        self::hold($id, 'frozen_until = ?', [$until], $reason, static function (array $t) use ($until, $reason): string {
            if ($t['blocked_at'] !== null) {
                throw new ValidationException(['Токен заблокирован, замораживать его не нужно']);
            }
            return "Заморожен токен «{$t['name']}» до {$until}" . self::because($reason);
        });
    }

    public static function unfreeze(int $id): void
    {
        self::hold($id, 'frozen_until = NULL', [], '', static fn(array $t) => "Разморожен токен «{$t['name']}»");
    }

    /** Stops the token until the owner lifts the block. Name, role and history stay. */
    public static function block(int $id, string $reason = ''): void
    {
        self::hold($id, 'blocked_at = ?, frozen_until = NULL', [ag_now()], $reason, static fn(array $t) => "Заблокирован токен «{$t['name']}»" . self::because($reason));
    }

    public static function unblock(int $id): void
    {
        self::hold($id, 'blocked_at = NULL', [], '', static fn(array $t) => "Разблокирован токен «{$t['name']}»");
    }

    /** Raises or lowers what the token may do; takes effect on its next request. */
    public static function setRole(int $id, string $role): void
    {
        if (!in_array($role, Security::roles(), true)) {
            throw new ValidationException(["Unknown role '{$role}'"]);
        }
        Database::write(static function (PDO $pdo) use ($id, $role): void {
            $t = self::locked($pdo, $id);
            if ($t['role'] === $role) {
                return;
            }
            $pdo->prepare('UPDATE api_tokens SET role = ? WHERE id = ?')->execute([$role, $id]);
            Logger::log('info', 'token.role_changed', ['name' => $t['name'], 'from' => $t['role'], 'to' => $role]);
            Audit::record(Audit::TOKENS, "Роль токена «{$t['name']}»: {$t['role']} -> {$role}");
        });
    }

    /** Takes the token away for good. */
    public static function revoke(int $id): void
    {
        $name = Database::value('SELECT name FROM api_tokens WHERE id = ? AND revoked_at IS NULL', [$id]);
        if ($name === null) {
            throw new NotFoundException('Token not found or already revoked');
        }
        Auth::revokeToken((string)$name);
    }

    /**
     * The emergency switch: every agent token stops for some hours, tokens issued meanwhile too. People keep working.
     */
    public static function freezeAll(int $hours, string $reason = ''): void
    {
        if ($hours < 1 || $hours > 24 * 365) {
            throw new ValidationException(['Заморозить можно на срок от 1 часа до года']);
        }
        $until = gmdate('Y-m-d\TH:i:s\Z', time() + $hours * 3600);
        Config::set('agent_access.frozen', ['until' => $until, 'reason' => mb_substr(trim($reason), 0, 200), 'by' => Audit::actor()->name]);
        Logger::log('warning', 'token.all_frozen', ['until' => $until]);
        Audit::record(Audit::TOKENS, "Заморожены все агенты до {$until}" . self::because($reason));
    }

    public static function unfreezeAll(): void
    {
        Config::set('agent_access.frozen', null);
        Logger::log('info', 'token.all_unfrozen', []);
        Audit::record(Audit::TOKENS, 'Все агенты разморожены');
    }

    /** @return array{until:string,reason:string,by:string}|null the freeze of every agent while it lasts */
    public static function allFrozen(): ?array
    {
        $f = Config::get('agent_access.frozen');
        if (!is_array($f) || !is_string($f['until'] ?? null) || $f['until'] <= gmdate('Y-m-d\TH:i:s\Z')) {
            return null;
        }
        return ['until' => $f['until'], 'reason' => (string)($f['reason'] ?? ''), 'by' => (string)($f['by'] ?? '')];
    }

    /**
     * @param list<mixed> $params
     * @param callable(array<string,mixed>):string $describe
     */
    private static function hold(int $id, string $set, array $params, string $reason, callable $describe): void
    {
        $reason = mb_substr(trim($reason), 0, 200);
        Database::write(static function (PDO $pdo) use ($id, $set, $params, $reason, $describe): void {
            $t = self::locked($pdo, $id);
            if ($t['revoked_at'] !== null) {
                throw new ValidationException(['Токен уже отозван']);
            }
            $desc = $describe($t);
            $pdo->prepare("UPDATE api_tokens SET {$set}, hold_reason = ?, hold_by = ? WHERE id = ?")->execute([...$params, $reason, Audit::actor()->name, $id]);
            Logger::log('info', 'token.hold', ['name' => $t['name'], 'what' => $desc]);
            Audit::record(Audit::TOKENS, $desc);
        });
    }

    /** @return array<string,mixed> */
    private static function locked(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT id, name, role, revoked_at, blocked_at FROM api_tokens WHERE id = ?');
        $stmt->execute([$id]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($t === false) {
            throw new NotFoundException('Token not found');
        }
        return $t;
    }

    private static function because(string $reason): string
    {
        $reason = trim($reason);
        return $reason !== '' ? ': ' . mb_substr($reason, 0, 200) : '';
    }
}
