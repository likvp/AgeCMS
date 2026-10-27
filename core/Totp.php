<?php
declare(strict_types=1);

namespace AgeCMS;

/** One-time codes for the second sign-in step (RFC 6238: HMAC-SHA1, 30 seconds, 6 digits), compatible with the usual authenticator apps. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const PERIOD = 30;

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secret, int $step, int $digits = 6): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Checks a code against the current step and one step either side (clock drift). A step that was used before
     * ($lastStep) is refused, so a code seen over the shoulder or intercepted cannot be used twice.
     *
     * @return int|null the step that matched, to be remembered as the last used one
     */
    public static function verify(string $secret, string $code, int $lastStep = 0, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $current = intdiv($now ?? time(), self::PERIOD);
        $found = null;
        foreach ([-1, 0, 1] as $drift) {
            $step = $current + $drift;
            if (hash_equals(self::code($secret, $step), $code) && $step > $lastStep) {
                $found = $step;
            }
        }
        return $found;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=' . self::PERIOD;
    }

    /** The secret in groups of four for typing it in by hand. */
    public static function pretty(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /** Encrypts the secret for the database: a copy of the database alone does not give the second factor away. */
    public static function seal(string $secret): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', Security::key('totp'), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new AgeCMSException('Cannot encrypt the secret');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function open(string $sealed): string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', Security::key('totp'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int)bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int)bindec($byte));
            }
        }
        return $out;
    }
}
