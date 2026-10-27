<?php
declare(strict_types=1);

namespace AgeCMS;

/** RBAC, HMAC signing, derived secrets and token helpers. */
final class Security
{
    /** Permissions per role. '*' means everything. */
    private const ROLES = [
        'admin' => ['*'],
        'editor' => ['content.read', 'content.write', 'cache.clear', 'search'],
        'viewer' => ['content.read', 'search'],
    ];

    public static function roles(): array
    {
        return array_keys(self::ROLES);
    }

    public static function can(string $role, string $permission): bool
    {
        $granted = self::ROLES[$role] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /** A purpose-bound key derived from the master secret, so one leak does not break every feature. */
    public static function key(string $purpose): string
    {
        $master = (string)Config::get('secret', '');
        if (strlen($master) < 32) {
            throw new AgeCMSException('Master secret is not configured');
        }
        return hash_hkdf('sha256', hex2bin($master) ?: $master, 32, 'agecms:' . $purpose);
    }

    public static function sign(string $purpose, string $message): string
    {
        return hash_hmac('sha256', $message, self::key($purpose));
    }

    public static function verify(string $purpose, string $message, string $signature): bool
    {
        return hash_equals(self::sign($purpose, $message), $signature);
    }

    /** Random URL-safe token. */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function requirePermission(Actor $actor, string $permission): void
    {
        if (!$actor->can($permission)) {
            throw new ForbiddenException("Role '{$actor->role}' lacks permission '{$permission}'");
        }
    }
}
