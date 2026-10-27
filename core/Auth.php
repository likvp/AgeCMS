<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/** Human login (PHP session + CSRF) and agent API tokens. */
final class Auth
{
    /** Why the last bearer token was refused: 'expired', 'blocked' or ''. */
    private static string $tokenProblem = '';
    /** @var array<string,mixed>|null the token of the current request */
    private static ?array $currentToken = null;

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
        $user = Database::row('SELECT id, username, role, email, pwd_epoch, blocked_at FROM users WHERE id = ?', [$uid]);
        // A password change or reset ends every other session of that person, blocking ends them all.
        if ($user === null || $user['blocked_at'] !== null || (int)($_SESSION['epoch'] ?? 0) !== (int)$user['pwd_epoch']) {
            return null;
        }
        return $user;
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
     * Checks a login and password against the failure limits, without opening a session.
     *
     * @return array<string,mixed>|null the user row on success, null on wrong credentials
     * @throws ForbiddenException when the address or account is temporarily blocked
     */
    public static function checkCredentials(string $username, string $password): ?array
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
        return $user;
    }

    /**
     * @return array<string,mixed>|null the user row on success, null on wrong credentials
     * @throws ForbiddenException when the address or account is temporarily blocked
     */
    public static function login(string $username, string $password): ?array
    {
        $user = self::checkCredentials($username, $password);
        if ($user === null) {
            return null;
        }
        if ($user['blocked_at'] !== null) {
            throw new ForbiddenException('Учётная запись заблокирована. Обратитесь к администратору сайта.');
        }

        self::startSession();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['epoch'] = (int)$user['pwd_epoch'];
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
        self::$tokenProblem = '';
        self::$currentToken = null;
        $row = Database::row(
            'SELECT t.id, t.name, t.role, t.last_used_at, t.expires_at, t.user_id, u.id AS uid, u.blocked_at
             FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.revoked_at IS NULL',
            [Security::hashToken($bearer)],
        );
        if ($row === null) {
            return null;
        }
        if ($row['user_id'] !== null && ($row['uid'] === null || $row['blocked_at'] !== null)) {
            self::$tokenProblem = 'blocked';
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string)$row['expires_at']) < time()) {
            self::$tokenProblem = 'expired';
            return null;
        }
        if ($row['last_used_at'] === null || strtotime((string)$row['last_used_at']) < time() - 60) {
            Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([ag_now(), $row['id']]));
        }
        self::$currentToken = ['name' => (string)$row['name'], 'role' => (string)$row['role'], 'expires_at' => $row['expires_at']];
        return new Actor('agent', (string)$row['name'], (string)$row['role'], 'tok-' . $row['id'] . '-' . gmdate('YmdH'));
    }

    /** What the agent should read when its request is refused with 401. */
    public static function authError(): string
    {
        $doc = Seo::siteUrl() . '/AGENT.md';
        return match (self::$tokenProblem) {
            'expired' => "The token has expired. Ask the owner for a new one: a ready token or a one-time invitation code, see {$doc}",
            'blocked' => 'The user behind this token is blocked or deleted. Ask the owner.',
            default => "Authentication required: send a Bearer token. If the owner gave you a token, use it; if you got a one-time invitation code or the owner's login and password, see {$doc}",
        };
    }

    /**
     * Facts about the token of the current request for the agent: when it expires, and a warning a week ahead.
     *
     * @return array{name:string,role:string,expires_at:?string,days_left:?int,warning:?string}|null
     */
    public static function currentToken(): ?array
    {
        if (self::$currentToken === null) {
            return null;
        }
        $left = self::$currentToken['expires_at'] === null ? null : max(0, (int)ceil((strtotime((string)self::$currentToken['expires_at']) - time()) / 86400));
        $warn = $left !== null && $left <= 7 ? "This token expires in {$left} day(s). Tell the owner now so that a new one can be issued." : null;
        return ['name' => self::$currentToken['name'], 'role' => self::$currentToken['role'], 'expires_at' => self::$currentToken['expires_at'], 'days_left' => $left, 'warning' => $warn];
    }

    public static function createUser(string $username, string $password, string $role = 'admin', string $email = ''): int
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
        $email = self::normalizeEmail($email);
        return (int)Database::write(static function (PDO $pdo) use ($username, $password, $role, $email): string {
            self::assertEmailFree($pdo, $email, 0);
            $pdo->prepare('INSERT INTO users (username, password_hash, role, email, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $email, ag_now()]);
            return $pdo->lastInsertId();
        });
    }

    /** Empty string for "no address"; anything else must be a valid, lower-cased address. */
    public static function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return '';
        }
        if (strlen($email) > 120 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(['Email address is not valid']);
        }
        return $email;
    }

    private static function assertEmailFree(PDO $pdo, string $email, int $exceptUserId): void
    {
        if ($email === '') {
            return;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $exceptUserId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new ValidationException(['This email address already belongs to another user']);
        }
    }

    /** Administrator sets or clears the recovery address of any account. */
    public static function setEmail(int $userId, string $email): void
    {
        $email = self::normalizeEmail($email);
        Database::write(static function (PDO $pdo) use ($userId, $email): void {
            $user = self::lockedUser($pdo, $userId);
            self::assertEmailFree($pdo, $email, $userId);
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);
            Logger::log('info', 'user.email_changed', ['user' => $user['username']]);
        });
    }

    /** Own address change: the current password is required, so a stolen session cannot redirect recovery mail. */
    public static function changeOwnEmail(int $userId, string $current, string $email): void
    {
        $user = Database::row('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($user === null || !password_verify($current, $user['password_hash'])) {
            throw new ValidationException(['Current password is wrong']);
        }
        self::setEmail($userId, $email);
    }

    /**
     * Sends a single-use link that sets a new password. The answer never says whether the account exists.
     * Limits: 5 requests per address per hour, 3 links per account per hour.
     *
     * @throws ForbiddenException when the address asks too often
     */
    public static function requestReset(string $identifier): void
    {
        $identifier = mb_strtolower(trim($identifier));
        $ipBucket = 'reset-ip:' . Http::clientIp();
        if (AntiSpam::count($ipBucket, 3600) >= 5) {
            throw new ForbiddenException('Too many requests. Try again in an hour.');
        }
        AntiSpam::hit($ipBucket);
        if ($identifier === '' || strlen($identifier) > 120) {
            return;
        }
        $user = Database::row('SELECT id, username, email, blocked_at FROM users WHERE username = ? COLLATE NOCASE OR email = ?', [$identifier, $identifier]);
        if ($user === null || $user['email'] === '' || $user['blocked_at'] !== null) {
            Logger::log('info', 'user.reset_skipped', ['reason' => $user === null ? 'unknown' : ($user['blocked_at'] !== null ? 'blocked' : 'no_email')]);
            return;
        }
        $userBucket = 'reset-user:' . $user['id'];
        if (AntiSpam::count($userBucket, 3600) >= 3) {
            return;
        }
        AntiSpam::hit($userBucket);

        $siteUrl = rtrim((string)Config::get('site_url', ''), '/');
        if ($siteUrl === '') {
            Logger::log('error', 'user.reset_failed', ['reason' => 'site_url is empty']);
            return;
        }
        $token = Security::token(32);
        Database::write(static function (PDO $pdo) use ($user, $token): void {
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
            $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$user['id'], Security::hashToken($token), time() + 3600, ag_now()]);
        });
        $site = (string)Config::get('site_name', parse_url($siteUrl, PHP_URL_HOST) ?: 'AgeCMS');
        $body = "Здравствуйте, {$user['username']}!\n\n"
            . "Для входа в админку {$site} запрошена смена пароля. Чтобы задать новый пароль, откройте ссылку (она действует один час и сработает один раз):\n\n"
            . $siteUrl . '/admin/?s=reset&token=' . $token . "\n\n"
            . "Если вы ничего не запрашивали, просто удалите это письмо: пароль останется прежним.\n";
        try {
            Mailer::send((string)$user['email'], 'Смена пароля: ' . $site, $body);
        } catch (AgeCMSException $e) {
            Logger::log('error', 'user.reset_failed', ['reason' => $e->getMessage()]);
        }
    }

    /** True while the link is unused and not expired. */
    public static function resetTokenValid(string $token): bool
    {
        return $token !== '' && Database::row('SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?', [Security::hashToken($token), time()]) !== null;
    }

    /**
     * Sets the new password through a reset link. Every other link of that person dies, and so do their sessions.
     *
     * @throws ForbiddenException after too many wrong links from one address
     * @throws ValidationException when the link is unknown, used or expired, or the password is weak
     */
    public static function completeReset(string $token, string $new): string
    {
        $bucket = 'reset-bad:' . Http::clientIp();
        if (AntiSpam::count($bucket, 3600) >= 10) {
            throw new ForbiddenException('Too many attempts. Try again later.');
        }
        if (!self::resetTokenValid($token)) {
            AntiSpam::hit($bucket);
            throw new ValidationException(['The link is not valid or has expired. Request a new one.']);
        }
        self::checkPasswordStrength($new);
        $username = Database::write(static function (PDO $pdo) use ($token, $new): string {
            $stmt = $pdo->prepare('SELECT r.id, r.user_id, u.username FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at >= ?');
            $stmt->execute([Security::hashToken($token), time()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new ValidationException(['The link is not valid or has expired. Request a new one.']);
            }
            $pdo->prepare('UPDATE users SET password_hash = ?, pwd_epoch = pwd_epoch + 1 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $row['user_id']]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$row['user_id']]);
            Logger::log('info', 'user.password_changed', ['user' => $row['username'], 'by' => 'email_link']);
            return (string)$row['username'];
        });
        AntiSpam::clear('login-user:' . strtolower($username));
        return $username;
    }

    /** @return list<array<string,mixed>> */
    public static function listUsers(): array
    {
        return Database::rows('SELECT id, username, email, role, blocked_at, created_at FROM users ORDER BY id');
    }

    public static function changeRole(int $userId, string $role, int $actingUserId): void
    {
        if (!in_array($role, Security::roles(), true)) {
            throw new ValidationException(["Unknown role '{$role}'"]);
        }
        if ($userId === $actingUserId) {
            throw new ValidationException(['You cannot change your own role']);
        }
        Database::write(static function (PDO $pdo) use ($userId, $role): void {
            $user = self::lockedUser($pdo, $userId);
            if ($user['role'] === 'admin' && $user['blocked_at'] === null && $role !== 'admin' && self::adminCount($pdo) <= 1) {
                throw new ValidationException(['The last administrator cannot be demoted']);
            }
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
            Logger::log('info', 'user.role_changed', ['user' => $user['username'], 'from' => $user['role'], 'to' => $role]);
        });
    }

    public static function deleteUser(int $userId, int $actingUserId): void
    {
        if ($userId === $actingUserId) {
            throw new ValidationException(['You cannot delete your own account']);
        }
        Database::write(static function (PDO $pdo) use ($userId): void {
            $user = self::lockedUser($pdo, $userId);
            if ($user['role'] === 'admin' && $user['blocked_at'] === null && self::adminCount($pdo) <= 1) {
                throw new ValidationException(['The last administrator cannot be deleted']);
            }
            $pdo->prepare('UPDATE api_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL')->execute([ag_now(), $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            Logger::log('info', 'user.deleted', ['user' => $user['username']]);
        });
    }

    /**
     * Blocks or unblocks a person. A blocked user cannot sign in, open sessions end, and tokens that
     * were issued on that user's behalf stop working. History and name stay. The last active administrator
     * and your own account cannot be blocked.
     */
    public static function setBlocked(int $userId, bool $blocked, int $actingUserId): void
    {
        if ($blocked && $userId === $actingUserId) {
            throw new ValidationException(['You cannot block your own account']);
        }
        Database::write(static function (PDO $pdo) use ($userId, $blocked): void {
            $user = self::lockedUser($pdo, $userId);
            if ($blocked && $user['role'] === 'admin' && $user['blocked_at'] === null && self::adminCount($pdo) <= 1) {
                throw new ValidationException(['The last administrator cannot be blocked']);
            }
            $pdo->prepare('UPDATE users SET blocked_at = ?, pwd_epoch = pwd_epoch + 1 WHERE id = ?')->execute([$blocked ? ag_now() : null, $userId]);
            Logger::log('info', $blocked ? 'user.blocked' : 'user.unblocked', ['user' => $user['username']]);
        });
    }

    /** Own password change: the current password is required. */
    public static function changeOwnPassword(int $userId, string $current, string $new): void
    {
        $user = Database::row('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($user === null || !password_verify($current, $user['password_hash'])) {
            throw new ValidationException(['Current password is wrong']);
        }
        self::checkPasswordStrength($new);
        $epoch = self::storePassword($userId, $new);
        if (isset($_SESSION) && ($_SESSION['uid'] ?? null) === $userId) {
            $_SESSION['epoch'] = $epoch; // this device stays signed in, the others are ended
        }
        Logger::log('info', 'user.password_changed', ['id' => $userId, 'by' => 'self']);
    }

    /** Administrator sets a new password for another account. */
    public static function resetPassword(int $userId, string $new): void
    {
        self::checkPasswordStrength($new);
        Database::write(static function (PDO $pdo) use ($userId, $new): void {
            $user = self::lockedUser($pdo, $userId);
            $pdo->prepare('UPDATE users SET password_hash = ?, pwd_epoch = pwd_epoch + 1 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            Logger::log('info', 'user.password_changed', ['user' => $user['username'], 'by' => 'admin']);
        });
    }

    /** Random password that is easy to read aloud: no look-alike characters. */
    public static function generatePassword(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private static function checkPasswordStrength(string $password): void
    {
        if (strlen($password) < 10) {
            throw new ValidationException(['Password must be at least 10 characters']);
        }
    }

    /** @return array<string,mixed> */
    private static function lockedUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT id, username, role, blocked_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            throw new NotFoundException('User not found');
        }
        return $user;
    }

    private static function adminCount(PDO $pdo): int
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND blocked_at IS NULL")->fetchColumn();
    }

    /** Stores a new password and ends the person's other sessions. Returns the new session epoch. */
    private static function storePassword(int $userId, string $password): int
    {
        return (int)Database::write(static function (PDO $pdo) use ($userId, $password): int {
            $pdo->prepare('UPDATE users SET password_hash = ?, pwd_epoch = pwd_epoch + 1 WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
            $stmt = $pdo->prepare('SELECT pwd_epoch FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        });
    }

    /** Re-hash only (cost upgrade at login): the person stays signed in everywhere. */
    public static function setPassword(int $userId, string $password): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]));
    }

    /** Creates an API token. The plain value is returned once and only its hash is stored. */
    /** @param ?int $days lifetime in days, null or 0 for no expiry; $userId ties the token to a person, so blocking that person stops it */
    public static function createToken(string $name, string $role, ?int $days = null, ?int $userId = null): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]{2,40}$/', $name) !== 1) {
            throw new ValidationException(['Token name must be 2-40 characters: letters, digits, _ . -']);
        }
        if (!in_array($role, Security::roles(), true)) {
            throw new ValidationException(["Unknown role '{$role}'"]);
        }
        if ($days !== null && ($days < 0 || $days > 3650)) {
            throw new ValidationException(['Token lifetime must be 0 (no expiry) to 3650 days']);
        }
        $expires = $days === null || $days === 0 ? null : gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400);
        $plain = 'agc_' . Security::token(32);
        Database::write(static fn(PDO $pdo) => $pdo->prepare('INSERT INTO api_tokens (name, token_hash, role, created_at, user_id, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, Security::hashToken($plain), $role, ag_now(), $userId, $expires]));
        return $plain;
    }

    /** Lifetime for a new agent token in days: the owner's default, a shorter wish of the agent is honoured. 0 means no expiry. */
    public static function tokenDays(?int $wanted = null): int
    {
        $max = max(0, (int)Config::get('agent_access.token_days', 90));
        if ($wanted === null || $wanted <= 0) {
            return $max;
        }
        return $max > 0 ? min($wanted, $max) : $wanted;
    }

    /**
     * An agent exchanges the owner's login and password for its own token, as the instructions at /AGENT.md describe.
     * The role cannot be higher than the user's. The plain token is returned once.
     *
     * @return array{token:string,role:string,name:string,expires_at:?string}
     * @throws ForbiddenException when the owner switched self-service off, or too many attempts
     * @throws ValidationException on bad name or role, wrong login or password
     */
    public static function issueTokenForCredentials(string $username, string $password, string $name, string $role = '', ?int $days = null): array
    {
        if (Config::get('agent_access.self_issue', true) !== true) {
            throw new ForbiddenException('self_issue_disabled: the owner switched off self-service tokens. Ask the owner for a token (Admin, System).');
        }
        $user = self::checkCredentials($username, $password);
        if ($user === null) {
            throw new ValidationException(['Wrong login or password']);
        }
        if ($user['blocked_at'] !== null) {
            throw new ForbiddenException('This account is blocked. Ask the owner.');
        }
        $rank = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
        $userRank = $rank[$user['role']] ?? 1;
        $role = $role !== '' ? $role : ($userRank >= 2 ? 'editor' : 'viewer');
        if (!isset($rank[$role])) {
            throw new ValidationException(["Unknown role '{$role}', use viewer, editor or admin"]);
        }
        if ($rank[$role] > $userRank) {
            throw new ForbiddenException("The user '{$user['username']}' has the role '{$user['role']}', a token cannot be stronger");
        }
        if (!AntiSpam::rateLimit('token-issue:' . $user['id'], 10, 3600)) {
            throw new ForbiddenException('Too many tokens requested. Try again in an hour.');
        }
        if (Database::row('SELECT id FROM api_tokens WHERE name = ?', [$name]) !== null) {
            throw new ValidationException(["The token name '{$name}' is taken, pick another"]);
        }
        $lifetime = self::tokenDays($days);
        $token = self::createToken($name, $role, $lifetime, (int)$user['id']);
        Logger::log('info', 'token.self_issued', ['user' => $user['username'], 'name' => $name, 'role' => $role, 'days' => $lifetime]);
        return ['token' => $token, 'role' => $role, 'name' => $name, 'expires_at' => $lifetime > 0 ? gmdate('Y-m-d\TH:i:s\Z', time() + $lifetime * 86400) : null];
    }

    /**
     * A one-time invitation for an agent: the owner pastes the code into the chat with the agent, the agent
     * exchanges it for its own token. No password is shared, the code works once and dies after a day.
     * The role and lifetime of the token are fixed here, by the owner.
     *
     * @return string the plain code, shown once
     */
    public static function createInvite(string $role, int $tokenDays, string $note, string $createdBy): string
    {
        if (!in_array($role, Security::roles(), true)) {
            throw new ValidationException(["Unknown role '{$role}'"]);
        }
        if ($tokenDays < 0 || $tokenDays > 3650) {
            throw new ValidationException(['Token lifetime must be 0 (no expiry) to 3650 days']);
        }
        $code = 'agi_' . Security::token(24);
        $hours = max(1, (int)Config::get('agent_access.invite_hours', 24));
        Database::write(static fn(PDO $pdo) => $pdo->prepare('INSERT INTO agent_invites (code_hash, role, token_days, note, expires_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([Security::hashToken($code), $role, $tokenDays, mb_substr(trim($note), 0, 80), time() + $hours * 3600, $createdBy, ag_now()]));
        return $code;
    }

    /**
     * The agent exchanges an invitation code for a token.
     *
     * @return array{token:string,role:string,name:string,expires_at:?string}
     */
    public static function redeemInvite(string $code, string $name): array
    {
        $bucket = 'invite-bad:' . Http::clientIp();
        if (AntiSpam::count($bucket, 3600) >= 10) {
            throw new ForbiddenException('Too many wrong codes. Try again in an hour.');
        }
        $row = Database::row('SELECT id, role, token_days FROM agent_invites WHERE code_hash = ? AND used_at IS NULL AND expires_at >= ?', [Security::hashToken(trim($code)), time()]);
        if ($row === null) {
            AntiSpam::hit($bucket);
            throw new ValidationException(['The invitation code is wrong, already used or expired. Ask the owner for a new one.']);
        }
        if (Database::row('SELECT id FROM api_tokens WHERE name = ?', [$name]) !== null) {
            throw new ValidationException(["The token name '{$name}' is taken, pick another"]);
        }
        $used = (int)Database::write(static function (PDO $pdo) use ($row): int {
            $stmt = $pdo->prepare('UPDATE agent_invites SET used_at = ? WHERE id = ? AND used_at IS NULL');
            $stmt->execute([time(), $row['id']]);
            return $stmt->rowCount();
        });
        if ($used !== 1) {
            throw new ValidationException(['The invitation code is wrong, already used or expired. Ask the owner for a new one.']);
        }
        $days = (int)$row['token_days'];
        $token = self::createToken($name, (string)$row['role'], $days);
        Logger::log('info', 'token.invite_redeemed', ['name' => $name, 'role' => $row['role'], 'days' => $days]);
        return ['token' => $token, 'role' => (string)$row['role'], 'name' => $name, 'expires_at' => $days > 0 ? gmdate('Y-m-d\TH:i:s\Z', time() + $days * 86400) : null];
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
