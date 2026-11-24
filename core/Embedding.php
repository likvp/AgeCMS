<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Who may show the public pages of the site in a frame. By default nobody but the site itself: the security headers say
 * X-Frame-Options SAMEORIGIN and frame-ancestors 'self'. The setting security.frame_ancestors lists other sites that may
 * (the web version of Telegram opens a Mini App in a frame). With sources set, the policy of a PUBLIC page gets them after
 * 'self' and X-Frame-Options is not sent at all (it cannot name sites). The admin, the sign-in page, the pages of a signed-in
 * user and every module address that is not marked public or "embed" keep the strict headers.
 *
 * This file has no dependencies on purpose: the fast path of index.php (a page from the shadow cache) loads it before the
 * framework starts and reads data/config.php itself.
 */
final class Embedding
{
    /** Short names for the setting. Telegram opens a Mini App in the web clients inside a frame on these hosts. */
    public const PRESETS = [
        'telegram' => ['https://web.telegram.org', 'https://webk.telegram.org', 'https://webz.telegram.org'],
    ];

    private const MAX_SOURCES = 20;

    /**
     * Turns the setting into a list of sources. Accepts a list or a string with names separated by spaces, commas or lines:
     * a preset name (telegram) or an origin: https://host, https://host:port, https://*.host; for a test on the own machine
     * also http://localhost and http://127.0.0.1 (optional port). Anything else (paths, "*", http: of another host) is refused.
     *
     * @return array{sources:list<string>,invalid:list<string>}
     */
    public static function parse(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $sources = [];
        $invalid = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $item = is_string($item) ? trim($item) : '';
            if ($item === '') {
                continue;
            }
            $key = strtolower($item);
            if (isset(self::PRESETS[$key])) {
                array_push($sources, ...self::PRESETS[$key]);
            } elseif (self::validOrigin($key)) {
                $sources[] = $key;
            } else {
                $invalid[] = $item;
            }
        }
        $sources = array_slice(array_values(array_unique($sources)), 0, self::MAX_SOURCES);
        return ['sources' => $sources, 'invalid' => $invalid];
    }

    private static function validOrigin(string $s): bool
    {
        $port = '(?::[0-9]{1,5})?';
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        return preg_match('~^https://(?:\*\.)?' . $label . '(?:\.' . $label . ')+' . $port . '$~', $s) === 1
            || preg_match('~^http://(?:localhost|127\.0\.0\.1)' . $port . '$~', $s) === 1;
    }

    /** Sources from the site settings (valid ones only: a typo never opens anything). @return list<string> */
    public static function sources(): array
    {
        return self::parse(Config::get('security.frame_ancestors', []))['sources'];
    }

    /** The same without the framework: reads the settings file itself (fast path of index.php). @return list<string> */
    public static function sourcesFromFile(string $configFile): array
    {
        if (!is_file($configFile)) {
            return [];
        }
        $config = (static fn() => require $configFile)();
        $value = is_array($config) && is_array($config['security'] ?? null) ? ($config['security']['frame_ancestors'] ?? []) : [];
        return self::parse($value)['sources'];
    }

    /**
     * Headers of a public page: the strict set of core/security-headers.php with the sources added to frame-ancestors and
     * without X-Frame-Options. With no sources the headers come back as they are.
     *
     * @param array<string,string> $headers
     * @param list<string>|null $sources null reads the site settings
     * @return array<string,string>
     */
    public static function open(array $headers, ?array $sources = null): array
    {
        $sources ??= self::sources();
        if ($sources === []) {
            return $headers;
        }
        unset($headers['X-Frame-Options']);
        $csp = (string)($headers['Content-Security-Policy'] ?? '');
        $ancestors = "frame-ancestors 'self' " . implode(' ', $sources);
        $headers['Content-Security-Policy'] = preg_match('/frame-ancestors[^;]*/', $csp) === 1
            ? (string)preg_replace('/frame-ancestors[^;]*/', $ancestors, $csp)
            : rtrim($csp, '; ') . '; ' . $ancestors;
        return $headers;
    }
}
