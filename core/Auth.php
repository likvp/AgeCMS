<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/** Human login (PHP session + CSRF) and agent API tokens. */
final class Auth
{
    public const SESSION_NAME = 'agecms';
    public const MARKER_COOKIE = 'agecms_admin';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = AGECMS_DATA . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_name(self::SESSION_NAME);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '28800');
        session_start();
    }

    private static function https(): bool
    {
        return str_starts_with((string)Config::get('site_url', ''), 'https://') || (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off');
    }

    /** The signed-in user row, or null. Starts the session only when the visitor already has its cookie. */
    public static function user(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!isset($_COOKIE[self::SESSION_NAME])) {
                return null;
            }
            self::startSession();
        }
        $uid = $_SESSION['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }
        return Database::row('SELECT id, username, role FROM users WHERE id = ?', [$uid]);
    }

    public static function actor(): ?Actor
    {
        $user = self::user();
        if ($user === null) {
            return null;
        }
        return new Actor('human', $user['username'], $user['role'], 'h-' . substr(hash('sha256', session_id()), 0, 16));
    }

    /**
     * @return array<string,mixed>|null the user row on success, null on wrong credentials
     * @throws ForbiddenException when the address or account is temporarily blocked
     */
    public static function login(string $username, string $password): ?array
    {
        $limit = (int)Config::get('rate_limit.login_fails', 5);
        $window = (int)Config::get('rate_limit.login_window', 900);
        $buckets = ['login-ip:' . Http::clientIp(), 'login-user:' . strtolower($username)];
        foreach ($buckets as $bucket) {
            if (AntiSpam::count($bucket, $window) >= $limit) {
                throw new ForbiddenException('Too many failed attempts. Try again later.');
            }
        }

        $user = Database::row('SELECT * FROM users WHERE username = ?', [$username]);
        // Verify against a dummy hash for unknown users so timing does not reveal which accounts exist.
        static $dummy = null;
        $hash = $user['password_hash'] ?? ($dummy ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
        $ok = password_verify($password, $hash) && $user !== null;
        if (!$ok) {
            foreach ($buckets as $bucket) {
                AntiSpam::hit($bucket);
            }
            return null;
        }

        foreach ($buckets as $bucket) {
            AntiSpam::clear($bucket);
        }
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['csrf'] = Security::token(24);
        setcookie(self::MARKER_COOKIE, '1', ['path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax']);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            self::setPassword((int)$user['id'], $password);
        }
        return $user;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
        setcookie(self::SESSION_NAME, '', ['expires' => 1, 'path' => '/']);
        setcookie(self::MARKER_COOKIE, '', ['expires' => 1, 'path' => '/']);
    }

    public static function csrf(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSession();
        }
        return $_SESSION['csrf'] ??= Security::token(24);
    }

    public static function checkCsrf(?string $token): bool
    {
        return $token !== null && $token !== '' && isset($_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], $token);
    }

    /** Resolves a Bearer token to an actor. Sessions are scoped to token and hour, so an agent cannot reset its own breaker. */
    public static function tokenActor(string $bearer): ?Actor
    {
        $row = Database::row('SELECT id, name, role, last_used_at FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL', [Security::hashToken($bearer)]);
        if ($row === null) {
            return null;
        }
        if ($row['last_used_at'] === null || strtotime((string)$row['last_used_at']) < time() - 60) {
            Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([ag_now(), $row['id']]));
        }
        return new Actor('agent', (string)$row['name'], (string)$row['role'], 'tok-' . $row['id'] . '-' . gmdate('YmdH'));
    }

    public static function createUser(string $username, string $password, string $role = 'admin'): int
    {
        if (preg_match('/^[A-Za-z0-9_.@-]{3,40}$/', $username) !== 1) {
            throw new ValidationException(['Username must be 3-40 characters: letters, digits, _ . @ -']);
        }
        if (strlen($password) < 10) {
            throw new ValidationException(['Password must be at least 10 characters']);
        }
        if (!in_array($role, Security::roles(), true)) {
            throw new ValidationException(["Unknown role '{$role}'"]);
        }
        return (int)Database::write(static function (PDO $pdo) use ($username, $password, $role): string {
            $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, ag_now()]);
            return $pdo->lastInsertId();
        });
    }

    public static function setPassword(int $userId, string $password): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]));
    }

    /** Creates an API token. The plain value is returned once and only its hash is stored. */
    public static function createToken(string $name, string $role): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]{2,40}$/', $name) !== 1) {
            throw new ValidationException(['Token name must be 2-40 characters: letters, digits, _ . -']);
        }
        if (!in_array($role, Security::roles(), true)) {
            throw new ValidationException(["Unknown role '{$role}'"]);
        }
        $plain = 'agc_' . Security::token(32);
        Database::write(static fn(PDO $pdo) => $pdo->prepare('INSERT INTO api_tokens (name, token_hash, role, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$name, Security::hashToken($plain), $role, ag_now()]));
        return $plain;
    }

    public static function revokeToken(string $name): bool
    {
        return (bool)Database::write(static function (PDO $pdo) use ($name): bool {
            $stmt = $pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE name = ? AND revoked_at IS NULL');
            $stmt->execute([ag_now(), $name]);
            return $stmt->rowCount() > 0;
        });
    }
}
