<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Meta tags, JSON-LD, sitemap, robots.txt, llms.txt and IndexNow.
 * Every absolute URL is built from the configured site_url, never from the Host header.
 */
final class Seo
{
    private const LD_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT;

    public static function siteUrl(): string
    {
        return rtrim((string)Config::get('site_url', ''), '/');
    }

    public static function url(string $slug): string
    {
        return self::siteUrl() . ($slug === 'index' ? '/' : '/' . $slug);
    }

    private static function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? self::siteUrl() . $url : $url;
    }

    /**
     * Lines for the <head> element.
     *
     * @param array<string,mixed> $doc
     * @param array<string,string> $settings
     */
    public static function head(string $slug, array $doc, array $settings): string
    {
        $site = $settings['site_name'] ?? '';
        $title = $doc['meta_title'] !== '' ? $doc['meta_title'] : $doc['title'];
        if ($site !== '' && $title !== $site && $slug !== 'index') {
            $title .= ' - ' . $site;
        }
        $description = $doc['meta_description'] !== '' ? $doc['meta_description'] : ($settings['site_description'] ?? '');
        $canonical = $doc['canonical_url'] !== '' ? $doc['canonical_url'] : self::url($slug);
        $image = $doc['og_image'] !== '' ? self::absolute($doc['og_image']) : '';

        $lines = [
            '<title>' . ag_e($title) . '</title>',
            '<meta name="description" content="' . ag_e($description) . '">',
            '<link rel="canonical" href="' . ag_e($canonical) . '">',
            '<meta name="robots" content="' . ((int)$doc['is_noindex'] === 1 ? 'noindex, nofollow' : 'index, follow') . '">',
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="' . ag_e($site) . '">',
            '<meta property="og:title" content="' . ag_e($title) . '">',
            '<meta property="og:description" content="' . ag_e($description) . '">',
            '<meta property="og:url" content="' . ag_e($canonical) . '">',
        ];
        if ($image !== '') {
            $lines[] = '<meta property="og:image" content="' . ag_e($image) . '">';
            $lines[] = '<meta name="twitter:card" content="summary_large_image">';
        }
        $lines[] = '<script type="application/ld+json">' . json_encode(self::jsonLd($slug, $doc, $settings), self::LD_FLAGS) . '</script>';
        return implode("\n    ", $lines);
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,string> $settings
     * @return array<string,mixed>
     */
    public static function jsonLd(string $slug, array $doc, array $settings): array
    {
        $url = self::url($slug);
        $org = ['@type' => 'Organization', '@id' => self::siteUrl() . '/#organization', 'name' => $settings['site_name'] ?? '', 'url' => self::siteUrl() . '/'];
        if (!empty($settings['org_phone'])) {
            $org['telephone'] = $settings['org_phone'];
        }
        if (!empty($settings['org_address'])) {
            $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $settings['org_address']];
        }
        if ($doc['schema_type'] === 'LocalBusiness') {
            $org['@type'] = 'LocalBusiness';
        }

        $page = [
            '@type' => $doc['schema_type'] === 'LocalBusiness' ? 'WebPage' : $doc['schema_type'],
            '@id' => $url . '#page',
            'url' => $url,
            'name' => $doc['title'],
            'isPartOf' => ['@id' => self::siteUrl() . '/#website'],
            'publisher' => ['@id' => $org['@id']],
        ];
        if ($doc['meta_description'] !== '') {
            $page['description'] = $doc['meta_description'];
        }

        $graph = [
            $org,
            ['@type' => 'WebSite', '@id' => self::siteUrl() . '/#website', 'url' => self::siteUrl() . '/', 'name' => $settings['site_name'] ?? ''],
            $page,
        ];

        $crumbs = self::breadcrumbs($slug, $doc['title']);
        if (count($crumbs) > 1) {
            $items = [];
            foreach ($crumbs as $i => [$name, $link]) {
                $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $link];
            }
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }

        $questions = [];
        foreach ($doc['blocks'] as $block) {
            if ($block['type'] === 'faq') {
                foreach ($block['data']['items'] ?? [] as $item) {
                    $questions[] = ['@type' => 'Question', 'name' => $item['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']]];
                }
            }
        }
        if ($questions !== []) {
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $questions];
        }
        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /** @return list<array{0:string,1:string}> name and URL pairs, home first */
    private static function breadcrumbs(string $slug, string $ownTitle): array
    {
        $crumbs = [['Главная', self::siteUrl() . '/']];
        if ($slug === 'index') {
            return $crumbs;
        }
        $ancestors = Pages::ancestors($slug);
        $titles = [];
        if ($ancestors !== []) {
            $marks = implode(',', array_fill(0, count($ancestors), '?'));
            foreach (Database::rows("SELECT slug, title FROM pages WHERE slug IN ({$marks}) AND is_published = 1", $ancestors) as $row) {
                $titles[$row['slug']] = $row['title'];
            }
        }
        foreach ($ancestors as $ancestor) {
            if (isset($titles[$ancestor])) {
                $crumbs[] = [$titles[$ancestor], self::url($ancestor)];
            }
        }
        $crumbs[] = [$ownTitle, self::url($slug)];
        return $crumbs;
    }

    public static function sitemap(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $rows = Database::rows('SELECT slug, updated_at FROM pages WHERE is_published = 1 AND is_noindex = 0 ORDER BY sort_order, slug');
        foreach ($rows as $row) {
            $xml .= '  <url><loc>' . htmlspecialchars(self::url($row['slug']), ENT_XML1) . '</loc><lastmod>' . substr((string)$row['updated_at'], 0, 10) . '</lastmod><priority>' . ($row['slug'] === 'index' ? '1.0' : '0.8') . "</priority></url>\n";
        }
        return $xml . '</urlset>' . "\n";
    }

    public static function robots(): string
    {
        return "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /search\nSitemap: " . self::siteUrl() . "/sitemap.xml\n";
    }

    /** Markdown summary for language models, see https://llmstxt.org */
    public static function llms(): string
    {
        $settings = self::settings();
        $out = '# ' . ($settings['site_name'] ?? 'Site') . "\n\n";
        if (!empty($settings['site_description'])) {
            $out .= '> ' . $settings['site_description'] . "\n\n";
        }
        $out .= "## Pages\n\n";
        foreach (Database::rows('SELECT slug, title, meta_description FROM pages WHERE is_published = 1 AND is_noindex = 0 ORDER BY sort_order, slug') as $row) {
            $out .= '- [' . $row['title'] . '](' . self::url($row['slug']) . ')' . ($row['meta_description'] !== '' ? ': ' . $row['meta_description'] : '') . "\n";
        }
        $out .= "\n## For agents\n\n- [Agent instructions](" . self::siteUrl() . "/AGENT.md): rules, limits and the MCP endpoint\n- MCP endpoint: " . self::siteUrl() . "/admin/mcp.php (JSON-RPC 2.0, Bearer token)\n";
        return $out;
    }

    /** @return array<string,string> */
    public static function settings(): array
    {
        $out = [];
        foreach (Database::rows("SELECT key, value FROM settings WHERE key NOT IN ('frozen', 'cache_gen', 'fts') AND key NOT LIKE 'integration\_%' ESCAPE '\\'") as $row) {
            $out[$row['key']] = (string)$row['value'];
        }
        return $out;
    }

    /** Remembers that a page changed, the ping itself is sent after the response by flushPings(). */
    public static function queuePing(string $slug): void
    {
        if (!self::indexNowEnabled()) {
            return;
        }
        $dir = Queue::dir() . '/ping';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($dir . '/' . (int)(microtime(true) * 1000000) . '-' . bin2hex(random_bytes(3)) . '.json', json_encode(['url' => self::url($slug)]));
    }

    public static function indexNowEnabled(): bool
    {
        $site = self::siteUrl();
        $host = (string)parse_url($site, PHP_URL_HOST);
        return (bool)Config::get('indexnow', true)
            && Config::get('indexnow_key', '') !== ''
            && str_starts_with($site, 'https://')
            && !in_array($host, ['localhost', '127.0.0.1'], true)
            && !str_ends_with($host, '.localhost')
            && !str_ends_with($host, '.test');
    }

    /** Sends queued URLs to IndexNow in one request. @return int URLs sent */
    public static function flushPings(): int
    {
        $files = glob(Queue::dir() . '/ping/*.json') ?: [];
        if ($files === []) {
            return 0;
        }
        $urls = [];
        foreach (array_slice($files, 0, 500) as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && isset($data['url'])) {
                $urls[(string)$data['url']] = true;
            }
            @unlink($file);
        }
        if ($urls === [] || !self::indexNowEnabled()) {
            return 0;
        }
        $key = (string)Config::get('indexnow_key');
        $result = Http::postJson('https://api.indexnow.org/indexnow', [
            'host' => (string)parse_url(self::siteUrl(), PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => self::siteUrl() . '/' . $key . '.txt',
            'urlList' => array_slice(array_keys($urls), 0, 100),
        ]);
        if ($result === null || $result['status'] >= 300) {
            Logger::log('warning', 'indexnow.failed', ['status' => $result['status'] ?? null]);
            return 0;
        }
        return count($urls);
    }
}
