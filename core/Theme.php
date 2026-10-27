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
