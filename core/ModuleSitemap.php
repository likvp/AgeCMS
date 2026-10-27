<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Addresses of modules in sitemap.xml and IndexNow. A module is never trusted with what it lists: an address
 * is accepted only when a route of the same module that is public and "indexable" answers it for GET.
 */
final class ModuleSitemap
{
    /** Addresses taken from one module. */
    public const LIMIT = 1000;
    /** Addresses taken from all modules together; a sitemap file may hold 50000. */
    private const TOTAL = 5000;

    /** @return list<array{loc:string,lastmod:string}> */
    public static function entries(): array
    {
        $out = [];
        foreach (SkillRegistry::modules() as $name => $info) {
            if ($info['status'] !== 'ok' || ($info['manifest']['routes'] ?? []) === []) {
                continue;
            }
            try {
                $module = SkillRegistry::routed($name);
                if (!$module instanceof SitemapModuleInterface) {
                    continue;
                }
                $taken = 0;
                $seen = [];
                foreach ($module->sitemapUrls() as $item) {
                    $path = is_array($item) ? ($item['path'] ?? null) : null;
                    if (!is_string($path) || !self::allowed($name, $path) || isset($seen[$path])) {
                        if (is_string($path)) {
                            Logger::log('warning', 'sitemap.rejected', ['module' => $name, 'path' => substr($path, 0, 120)]);
                        }
                        continue;
                    }
                    $seen[$path] = true;
                    $lastmod = (string)($item['lastmod'] ?? '');
                    $out[] = ['loc' => Seo::siteUrl() . $path, 'lastmod' => preg_match('/^\d{4}-\d{2}-\d{2}/', $lastmod) === 1 ? substr($lastmod, 0, 10) : ''];
                    if (++$taken >= self::LIMIT) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                Logger::log('error', 'sitemap.module_failed', ['module' => $name, 'error' => $e->getMessage()]);
            }
        }
        return array_slice($out, 0, self::TOTAL);
    }

    /** True when $path is a GET address that an indexable route of $module answers. */
    public static function allowed(string $module, string $path): bool
    {
        if (strlen($path) > 200 || preg_match('~^/[A-Za-z0-9_/-]+$~', $path) !== 1 || str_contains($path, '//') || str_ends_with($path, '/')) {
            return false;
        }
        $match = ModuleRoutes::match('GET', $path);
        return $match !== null && $match['route'] !== null && $match['route']['module'] === $module && $match['route']['indexable'] === true;
    }

    /**
     * Tells search engines (IndexNow) that addresses of the module changed. Addresses that do not pass allowed() are skipped.
     *
     * @param list<string> $paths
     * @return int addresses queued
     */
    public static function ping(string $module, array $paths): int
    {
        if ((SkillRegistry::modules()[$module]['status'] ?? '') !== 'ok') {
            return 0;
        }
        $queued = 0;
        foreach (array_slice($paths, 0, 100) as $path) {
            if (is_string($path) && self::allowed($module, $path)) {
                Seo::queueUrl(Seo::siteUrl() . $path);
                $queued++;
            }
        }
        return $queued;
    }
}
