<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * A module that adds its own public addresses to sitemap.xml (a catalogue of items, say).
 * Optional on top of RoutedModuleInterface. The core calls it only for a verified module and keeps only the
 * addresses that one of the module's own public routes marked "indexable" answers (ModuleSitemap).
 */
interface SitemapModuleInterface extends RoutedModuleInterface
{
    /**
     * @return iterable<array{path:string,lastmod?:string}> path like "/katalog/42", lastmod like "2026-10-27" (optional).
     *   Return the newest addresses first: the core takes at most ModuleSitemap::LIMIT of them.
     */
    public function sitemapUrls(): iterable;
}
