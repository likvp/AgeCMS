<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/** Form protection without captchas: honeypot, signed fill-time token and per-IP rate limits. */
final class AntiSpam
{
    private const MIN_FILL_SECONDS = 2;
    private const MAX_FILL_SECONDS = 7200;

    /** Issued by GET /_/token when the form loads, so the timestamp cannot be forged by the client. */
    public static function issueToken(): string
    {
        $time = (string)time();
        return $time . '.' . Security::sign('form', $time);
    }

    public static function checkToken(string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !Security::verify('form', $parts[0], $parts[1])) {
            return false;
        }
        $age = time() - (int)$parts[0];
        return $age >= self::MIN_FILL_SECONDS && $age <= self::MAX_FILL_SECONDS;
    }

    /** @param array<string,mixed> $post */
    public static function validate(array $post): bool
    {
        if (!empty($post['website_hp'])) {
            return false;
        }
        return self::checkToken((string)($post['token'] ?? ''));
    }

    /** True when the call is allowed; every allowed call is counted. */
    public static function rateLimit(string $bucket, int $max, int $windowSeconds): bool
    {
        return (bool)Database::write(static function (PDO $pdo) use ($bucket, $max, $windowSeconds): bool {
            $now = time();
            $pdo->prepare('DELETE FROM rate_limits WHERE bucket = ? AND ts < ?')->execute([$bucket, $now - $windowSeconds]);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = ?');
            $stmt->execute([$bucket]);
            if ((int)$stmt->fetchColumn() >= $max) {
                return false;
            }
            $pdo->prepare('INSERT INTO rate_limits (bucket, ts) VALUES (?, ?)')->execute([$bucket, $now]);
            return true;
        });
    }

    /** Counts without blocking: used for failed logins. */
    public static function hit(string $bucket): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('INSERT INTO rate_limits (bucket, ts) VALUES (?, ?)')->execute([$bucket, time()]));
    }

    public static function count(string $bucket, int $windowSeconds): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND ts >= ?', [$bucket, time() - $windowSeconds]);
    }

    public static function clear(string $bucket): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$bucket]));
    }

    /** Stored instead of the raw address. */
    public static function ipHash(string $ip): string
    {
        return substr(Security::sign('ip', $ip), 0, 16);
    }
}
