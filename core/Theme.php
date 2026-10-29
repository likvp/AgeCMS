<?php
declare(strict_types=1);

namespace AgeCMS;

/** Theme manifests. The manifest declares the block types and the fields each may hold. */
final class Theme
{
    /** @var array<string,array<string,mixed>> */
    private static array $manifests = [];

    public static function valid(string $name): bool
    {
        return preg_match('/^[a-z0-9_]{1,40}$/', $name) === 1;
    }

    public static function dir(string $name): string
    {
        return AGECMS_ROOT . '/themes/' . $name;
    }

    public static function exists(string $name): bool
    {
        return self::valid($name) && is_file(self::dir($name) . '/theme.json') && is_file(self::dir($name) . '/layout.php');
    }

    public static function active(): string
    {
        $name = (string)(Database::value("SELECT value FROM settings WHERE key = 'active_theme'") ?? 'default');
        return self::exists($name) ? $name : 'default';
    }

    /** @return array<string,mixed> */
    public static function manifest(string $name): array
    {
        if (!self::exists($name)) {
            throw new NotFoundException("Theme '{$name}' not found");
        }
        return self::$manifests[$name] ??= json_decode((string)file_get_contents(self::dir($name) . '/theme.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,array<string,mixed>> */
    public static function blocks(string $name): array
    {
        return self::manifest($name)['blocks'] ?? [];
    }

    /**
     * Fields of the theme's own settings ("settings" in theme.json): the sections of SiteSettings::THEME_SECTIONS, each
     * {"title": "...", "hint": "...", "fields": {name: field}} with fields like those of a block, of the types in
     * SiteSettings::THEME_TYPES. Anything else is left out, so a mistake in the manifest never breaks the settings page.
     *
     * @return array<string,array{title:string,hint:string,fields:array<string,array<string,mixed>>}>
     */
    public static function settings(string $name): array
    {
        $declared = self::exists($name) ? (self::manifest($name)['settings'] ?? []) : [];
        $out = [];
        foreach (SiteSettings::THEME_SECTIONS as $section => $title) {
            $spec = is_array($declared) ? ($declared[$section] ?? null) : null;
            if (!is_array($spec) || !is_array($spec['fields'] ?? null)) {
                continue;
            }
            $fields = [];
            foreach ($spec['fields'] as $field => $fieldSpec) {
                if (is_string($field) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $field) === 1 && is_array($fieldSpec)
                    && in_array($fieldSpec['type'] ?? 'string', SiteSettings::THEME_TYPES, true)) {
                    $fields[$field] = $fieldSpec;
                }
            }
            if ($fields !== []) {
                $out[$section] = [
                    'title' => is_string($spec['title'] ?? null) ? $spec['title'] : $title,
                    'hint' => is_string($spec['hint'] ?? null) ? $spec['hint'] : '',
                    'fields' => $fields,
                ];
            }
        }
        return $out;
    }

    /**
     * Values of the theme settings for the layout, typed: ['header' => ['phone' => '...', 'sticky' => true], 'footer' => [...]].
     * A field never saved has its "default" from theme.json, or '' (string types), false (bool), null (int).
     *
     * @param array<string,string> $stored the settings table (Seo::settings())
     * @return array<string,array<string,mixed>>
     */
    public static function settingValues(string $name, array $stored): array
    {
        $out = array_fill_keys(array_keys(SiteSettings::THEME_SECTIONS), []);
        foreach (self::settings($name) as $section => $spec) {
            foreach ($spec['fields'] as $field => $fieldSpec) {
                $raw = $stored["theme.{$section}.{$field}"] ?? null;
                $type = (string)($fieldSpec['type'] ?? 'string');
                if ($raw === null || ($raw === '' && $type !== 'string' && $type !== 'text' && $type !== 'url')) {
                    $out[$section][$field] = $fieldSpec['default'] ?? match ($type) { 'bool' => false, 'int' => null, default => '' };
                    continue;
                }
                $out[$section][$field] = match ($type) { 'bool' => $raw === '1', 'int' => (int)$raw, default => $raw };
            }
        }
        return $out;
    }

    /**
     * Pages whose content the layout shows on every page (a phone number or an address in the header, for example).
     * Listed in theme.json as "cache_pages"; invalid entries are skipped. Editing such a page drops every cached page.
     *
     * @return list<string>
     */
    public static function cachePages(string $name): array
    {
        $list = self::manifest($name)['cache_pages'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $slugs = [];
        foreach ($list as $slug) {
            if (is_string($slug) && Pages::slugValid($slug)) {
                $slugs[$slug] = true;
            }
        }
        return array_keys($slugs);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $names = [];
        foreach (glob(AGECMS_ROOT . '/themes/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (self::exists(basename($dir))) {
                $names[] = basename($dir);
            }
        }
        return $names;
    }
}
