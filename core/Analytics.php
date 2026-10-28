<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Counters and site verification codes in the <head>: Yandex Metrika, Yandex Webmaster and free "meta name=content" tags
 * (Google, Bing, Yandex Direct and others). Values live in the settings table (versioned like the site name), so an
 * administrator edits them in the admin and an agent through set_settings.
 *
 * Nothing here is inline: the counter is started by /assets/analytics.js from data attributes, which is what the
 * Content-Security-Policy (script-src 'self' https://mc.yandex.ru) allows. Pages that are not indexable (the cabinet,
 * search, errors) never get a counter, so personal data stays out of Webvisor.
 */
final class Analytics
{
    /** Metrika options an administrator can switch on: key => label. */
    public const METRIKA_OPTIONS = [
        'webvisor' => 'Вебвизор (записи визитов)',
        'clickmap' => 'Карта кликов и скроллинга',
        'trackLinks' => 'Внешние ссылки и скачивание файлов',
        'accurateTrackBounce' => 'Точный показатель отказов',
        'trackHash' => 'Учитывать хеш в адресе (#)',
    ];

    /** Why a value is refused, or null when it is fine. */
    public static function problem(string $key, string $value): ?string
    {
        switch ($key) {
            case 'yandex_metrika_id':
                return preg_match('/^[1-9][0-9]{0,11}$/', $value) === 1 ? null : 'yandex_metrika_id must be the number of the counter (digits only)';
            case 'yandex_metrika_options':
                foreach (explode(',', $value) as $option) {
                    if (!isset(self::METRIKA_OPTIONS[$option])) {
                        return "yandex_metrika_options: unknown option '{$option}' (allowed: " . implode(', ', array_keys(self::METRIKA_OPTIONS)) . ')';
                    }
                }
                return null;
            case 'yandex_webmaster':
                return preg_match('/^[A-Za-z0-9]{8,64}$/', $value) === 1 ? null : 'yandex_webmaster must be the verification code from Yandex Webmaster (letters and digits, 8-64)';
            case 'verification_tags':
                $lines = explode("\n", $value);
                if (count($lines) > 10) {
                    return 'verification_tags: at most 10 lines';
                }
                foreach ($lines as $line) {
                    if (self::parseTag($line) === null) {
                        return 'verification_tags: every line is name=value, for example google-site-verification=abc123';
                    }
                }
                return null;
        }
        return null;
    }

    /** @return array{0:string,1:string}|null name and value of one "name=value" line */
    public static function parseTag(string $line): ?array
    {
        if (preg_match('/^\s*([A-Za-z0-9._:-]{1,64})\s*=\s*([A-Za-z0-9._:\/+=~-]{1,200})\s*$/', $line, $m) !== 1) {
            return null;
        }
        // Names that would change how the page is handled are not verification codes.
        if (in_array(strtolower($m[1]), ['robots', 'viewport', 'refresh', 'content-security-policy', 'set-cookie', 'generator', 'description', 'referrer'], true)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /**
     * Lines for the <head> of an indexable page.
     *
     * @param array<string,string> $settings
     * @return list<string>
     */
    public static function headLines(array $settings): array
    {
        $lines = [];
        $webmaster = $settings['yandex_webmaster'] ?? '';
        if ($webmaster !== '' && self::problem('yandex_webmaster', $webmaster) === null) {
            $lines[] = '<meta name="yandex-verification" content="' . ag_e($webmaster) . '">';
        }
        foreach (explode("\n", $settings['verification_tags'] ?? '') as $line) {
            $tag = self::parseTag($line);
            if ($tag !== null && !($tag[0] === 'yandex-verification' && $webmaster !== '')) {
                $lines[] = '<meta name="' . ag_e($tag[0]) . '" content="' . ag_e($tag[1]) . '">';
            }
        }
        $id = $settings['yandex_metrika_id'] ?? '';
        if ($id !== '' && self::problem('yandex_metrika_id', $id) === null) {
            $options = array_filter(explode(',', $settings['yandex_metrika_options'] ?? ''), static fn(string $o): bool => isset(self::METRIKA_OPTIONS[$o]));
            $lines[] = '<script src="/assets/analytics.js" defer data-ym="' . ag_e($id) . '" data-ym-options="' . ag_e(implode(',', $options)) . '"></script>';
        }
        return $lines;
    }
}
