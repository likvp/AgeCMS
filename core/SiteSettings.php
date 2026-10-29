<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Settings of the site in the table "settings", the ones set_settings may change. Two kinds:
 *  - the core's own keys (LIMITS): the name and contacts of the organisation, the SEO texts, the cookie notice, analytics;
 *  - fields of the active theme, declared in theme.json under "settings" (sections "header" and "footer", fields like the
 *    fields of a block) and stored as "theme.<section>.<field>".
 * Every value is kept as a string; theme fields come back to the layout typed (see Theme::settingValues).
 */
final class SiteSettings
{
    /** Allowed length of each core key, [min, max]. */
    public const LIMITS = [
        'site_name' => [1, 100], 'site_description' => [0, 300], 'llms_text' => [0, 4000],
        'org_logo' => [0, 500], 'org_phone' => [0, 40], 'org_phone2' => [0, 40], 'org_email' => [0, 120],
        'org_inn' => [0, 12], 'org_legal_name' => [0, 200], 'org_address' => [0, 300],
        'cookie_banner' => [0, 1], 'cookie_text' => [0, 500], 'cookie_policy_url' => [0, 500],
        'yandex_metrika_id' => [0, 12], 'yandex_metrika_options' => [0, 120], 'yandex_webmaster' => [0, 64], 'verification_tags' => [0, 2000],
    ];

    /** Keys that may hold several lines; every other value is one line of plain text. */
    public const MULTILINE = ['llms_text', 'verification_tags'];

    /** Text of the cookie notice when the owner has not written their own. */
    public const COOKIE_TEXT = 'Сайт использует файлы cookie и сервисы аналитики, чтобы работать удобнее. Продолжая пользоваться сайтом, вы соглашаетесь с этим.';

    /** Sections of theme fields, each is a tab of the settings page. */
    public const THEME_SECTIONS = ['header' => 'Шапка', 'footer' => 'Подвал'];

    /** Field types a theme may use in its settings (lists stay for blocks). */
    public const THEME_TYPES = ['string', 'text', 'url', 'enum', 'bool', 'int'];

    /**
     * Checks the values and turns them into the strings to store.
     *
     * @param array<mixed,mixed> $values
     * @return array{0:array<string,string>,1:list<string>} clean values and errors
     */
    public static function clean(array $values, string $theme): array
    {
        $fields = self::themeFields($theme);
        $clean = [];
        $errors = [];
        foreach ($values as $key => $value) {
            $key = (string)$key;
            if (isset($fields[$key])) {
                $stored = self::themeValue($fields[$key], $value, $key, $errors);
                if ($stored !== null) {
                    $clean[$key] = $stored;
                }
                continue;
            }
            if (!isset(self::LIMITS[$key])) {
                $errors[] = str_starts_with($key, 'theme.') ? "Setting '{$key}' is not declared by theme '{$theme}' (theme.json, settings)" : "Unknown setting '{$key}'";
                continue;
            }
            $multiline = in_array($key, self::MULTILINE, true);
            if (!is_string($value) || preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $value) === 1) {
                $errors[] = $multiline ? "{$key} must be plain text" : "{$key} must be plain text on one line";
                continue;
            }
            $value = trim($multiline ? str_replace(["\r\n", "\r"], "\n", $value) : $value);
            $length = mb_strlen($value);
            [$min, $max] = self::LIMITS[$key];
            if ($length < $min || $length > $max) {
                $errors[] = "{$key} must be {$min}-{$max} characters";
            } elseif ($value !== '' && ($problem = self::problem($key, $value)) !== null) {
                $errors[] = $problem;
            }
            $clean[$key] = $value;
        }
        return [$clean, $errors];
    }

    /** Why a non-empty value of a core key is wrong, or null. */
    private static function problem(string $key, string $value): ?string
    {
        return match ($key) {
            'org_logo', 'cookie_policy_url' => SchemaValidator::urlValid($value) ? null : "{$key} must be an http(s) URL or a path on the site (/assets/uploads/...)",
            'org_email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'org_email must be an e-mail address',
            'org_phone', 'org_phone2' => preg_match('/^[+0-9 ()\-.,]{3,40}$/', $value) === 1 && preg_match('/\d/', $value) === 1 ? null : "{$key} must be a phone number: digits, spaces, + ( ) -",
            'org_inn' => preg_match('/^(\d{10}|\d{12})$/', $value) === 1 ? null : 'org_inn must be 10 or 12 digits',
            'cookie_banner' => in_array($value, ['0', '1'], true) ? null : 'cookie_banner must be "1" (shown) or "0" (hidden)',
            default => Analytics::problem($key, $value),
        };
    }

    /**
     * Fields the theme declared for its settings, by key "theme.<section>.<field>". Unknown sections and field types are skipped.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function themeFields(string $theme): array
    {
        $out = [];
        foreach (Theme::settings($theme) as $section => $spec) {
            foreach ($spec['fields'] as $name => $field) {
                $out["theme.{$section}.{$name}"] = $field;
            }
        }
        return $out;
    }

    /**
     * A theme field as the string to store; null and an error when it does not fit. A form sends strings, the API may send
     * the value itself: "1"/"0", true/false for bool, digits or an integer for int. An empty string clears any field.
     *
     * @param array<string,mixed> $spec
     * @param list<string> $errors
     */
    private static function themeValue(array $spec, mixed $value, string $key, array &$errors): ?string
    {
        $type = (string)($spec['type'] ?? 'string');
        if ($value === '' || $value === null) {
            return '';
        }
        if ($type === 'bool') {
            $value = match (true) {
                is_bool($value) => $value,
                $value === '1', $value === 1, $value === 'true' => true,
                $value === '0', $value === 0, $value === 'false' => false,
                default => $value,
            };
        } elseif ($type === 'int' && is_string($value) && preg_match('/^-?\d{1,10}$/', trim($value)) === 1) {
            $value = (int)trim($value);
        }
        $before = count($errors);
        $normalized = SchemaValidator::value($spec, $value, $key, $errors);
        if (count($errors) > $before) {
            return null;
        }
        return match (true) {
            is_bool($normalized) => $normalized ? '1' : '0',
            default => (string)$normalized,
        };
    }

    /**
     * Is the cookie notice on: it is, unless the owner switched it off.
     *
     * @param array<string,string> $settings
     */
    public static function cookieOn(array $settings): bool
    {
        return ($settings['cookie_banner'] ?? '1') !== '0';
    }
}
