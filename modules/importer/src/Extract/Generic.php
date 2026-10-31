<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Detect\Detector;
use AgeCMS\Modules\Importer\Html\Dom;
use AgeCMS\Modules\Importer\Html\MenuFinder;
use AgeCMS\Modules\Importer\ImportException;
use AgeCMS\Modules\Importer\Net\Fetcher;
use AgeCMS\Modules\Importer\Net\Response;
use AgeCMS\Modules\Importer\Net\Robots;
use AgeCMS\Modules\Importer\Url;

/**
 * Any site: pages from sitemap.xml (nested sitemap indexes, Sitemap: lines of robots.txt), otherwise a crawl of links from
 * the home page within the same site. Respects Disallow of robots.txt for "*", keeps to the page limit and pauses between
 * requests (the fetcher does). Each page is parsed by the parser of its platform: Tilda records, or the main content found
 * with the CMS profile (Bitrix, Joomla, MODX, HostCMS, Wix, Webflow, OpenCart, WordPress without REST) or by text density.
 */
final class Generic implements Extractor
{
    private const SKIP_PATHS = '~/(page|tag|tags|category|categories|author|feed|rss|wp-json|wp-admin|wp-login\.php|xmlrpc\.php|search|poisk|cart|basket|korzina|checkout|order|my-account|tproduct|login|logout|register|registration|uniregister|simpleregister|auth|personal|cabinet|kabinet|lk|compare|wishlist|bitrix|cgi-bin|upload|uploads|wp-content|images|img|files|cdn-cgi|amp)(/|$)~i';
    private const SKIP_QUERY = '~(^|&)(PAGEN_\d+|page|paged|sort|order|orderby|filter|set_filter|print|replytocom|share|lang|amp|add2basket|action|login|logout|register|backurl)=|(^|&)route=(account|checkout|affiliate|product/(compare|search))~i';
    /** Service pages a new site makes itself: the HTML site map, the version for the visually impaired, the 404 page. */
    private const SKIP_PAGES = '~/(sitemap|site-?map|karta-?sa[ij]?ta|map-site)(\.html?|\.php)?/?$|slabovid|/(special-version|bvi)/?$|/(404|oshibka-404|error-?404|page-not-found)(\.html?|\.php)?/?$~i';
    private const MAX_SITEMAPS = 60;
    /** Pages of an OpenCart catalog (product, category, manufacturer, search): the shop is not carried over. */
    private const OPENCART_CATALOG = '~<body[^>]*class="[^"]*\bproduct-(product|category|manufacturer|search|special)|id="product-(product|category|manufacturer|search|special)"|class="[^"]*\bcategory-info\b|itemtype="https?://schema\.org/Product"~i';

    /** @param array{url:string,limit?:int,robots?:bool,platform?:string} $options */
    public function __construct(private Fetcher $fetcher, private array $options)
    {
    }

    public function step(array &$state, float $deadline, callable $emit): bool
    {
        if (($state['phase'] ?? '') === '') {
            $this->init($state);
        }
        $limit = max(1, (int)($this->options['limit'] ?? 200));
        while (microtime(true) < $deadline) {
            if ($state['phase'] === 'sitemaps') {
                $state['posts'] ??= [];
                $pagesFull = count($state['queue']) >= self::cap($limit);
                $postsFull = count($state['posts']) >= self::cap($limit);
                $next = array_shift($state['sitemaps']);
                if ($next === null || $pagesFull && $postsFull) {
                    $state['phase'] = 'crawl';
                    if (($state['sitemap_urls'] ?? 0) === 0) {
                        $state['discover'] = true;
                        $state['warnings'][] = 'Карта сайта (sitemap.xml) не найдена или пуста: страницы собраны обходом ссылок с главной.';
                    } else {
                        $state['queue'] = self::prioritize($state['queue'], (int)($state['first'] ?? 1));
                    }
                    // Posts (Tilda Feeds, WordPress posts) get a third of the limit, so the pages cannot take it all.
                    $state['posts_share'] = min(count($state['posts']), intdiv($limit, 3));
                    continue;
                }
                // With the pages full, only the sitemaps of posts are still read (and the other way round).
                $isPosts = $this->isPostsMap($state, $next);
                if ($isPosts ? $postsFull : $pagesFull) {
                    continue;
                }
                $this->sitemap($state, $next, $limit);
                continue;
            }
            $budget = $limit * 4 + 20;
            if (($state['catalog_run'] ?? 0) >= 30 && $state['queue'] !== []) {
                // Thirty catalog pages in a row: the rest of the sitemap is the shop.
                $state['warnings'][] = 'Подряд 30 страниц каталога: остальные ' . count($state['queue']) . ' адресов карты сайта не просмотрены.';
                $state['queue'] = [];
            }
            $state['posts'] ??= [];
            if ($state['phase'] !== 'crawl' || $state['emitted'] >= $limit || $state['queue'] === [] && $state['posts'] === [] || ($state['visited'] ?? 0) >= $budget) {
                $left = count($state['queue']) + count($state['posts']);
                if ($state['emitted'] >= $limit && $left > 0) {
                    $state['warnings'][] = 'Достигнут лимит страниц (' . $limit . '): ещё ' . $left . ' адресов не просмотрено.';
                } elseif ($left > 0 && ($state['visited'] ?? 0) >= $budget) {
                    $state['warnings'][] = 'Просмотрено ' . $budget . ' адресов, страниц для переноса среди них ' . $state['emitted'] . ': остальные ' . $left . ' адресов не просмотрены.';
                }
                if ($state['posts'] !== []) {
                    $state['warnings'][] = 'Записи блога: взято ' . (int)($state['posts_emitted'] ?? 0) . ', ещё ' . count($state['posts']) . ' не просмотрено (лимит страниц; записям отдаётся треть лимита, если страниц хватает).';
                }
                if (($state['skipped']['catalog'] ?? 0) > 0) {
                    $state['warnings'][] = 'Страницы каталога магазина (товары, категории) не переносятся: пропущено ' . $state['skipped']['catalog'] . '.';
                }
                $state['phase'] = 'done';
                $state['queue'] = [];
                $state['posts'] = [];
                return true;
            }
            // A post when the pages have used their part of the limit or ran out, else a page.
            $pagesEmitted = $state['emitted'] - (int)($state['posts_emitted'] ?? 0);
            $post = $state['posts'] !== [] && ($state['queue'] === [] || $pagesEmitted >= $limit - (int)($state['posts_share'] ?? 0));
            $url = $post ? array_shift($state['posts']) : array_shift($state['queue']);
            $before = $state['emitted'];
            $this->visit($state, (string)$url, $emit);
            if ($post && $state['emitted'] > $before) {
                $state['posts_emitted'] = (int)($state['posts_emitted'] ?? 0) + 1;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $state */
    private function init(array &$state): void
    {
        $start = trim((string)$this->options['url']);
        $home = $this->fetcher->get($start);
        if ($home === null) {
            throw new ImportException('Сайт не открылся: адрес недоступен с этого сервера или закрыт для запросов (' . $start . ').');
        }
        if (!$home->ok()) {
            throw new ImportException('Сайт ответил кодом ' . $home->status . ' на адрес ' . $start . '.');
        }
        $start = $home->url;
        $platform = (string)($this->options['platform'] ?? '');
        $detect = Detector::detect($home);
        if ($platform === '') {
            $platform = $detect['platform'];
        }
        $html = $home->text();
        $doc = Dom::load($html);
        $xp = Dom::xpath($doc);
        $siteName = Dom::meta($xp, 'og:site_name');
        $title = $siteName !== '' ? $siteName : \AgeCMS\Modules\Importer\Neutral::line(Dom::first($xp, '//title')?->textContent ?? '');
        // A site in a folder of a shared host (user.wixsite.com/site): its pages are addressed from that folder.
        $startPath = rtrim(Url::oldPath($start, false), '/');
        $prefix = $platform === 'wix' && $startPath !== '' && preg_match('~\.[a-z0-9]{2,5}$~i', $startPath) !== 1 ? $startPath : '';
        $menus = MenuFinder::find($doc, $start);
        if ($platform === 'tilda' && ($tilda = Tilda::parsePage($html, $start)) !== null) {
            // The Tilda parser knows its menus (no logo, phone, popups, search, feed links; *.tilda.ws made local).
            foreach (['header', 'footer'] as $name) {
                if (($tilda['menus'][$name] ?? []) !== []) {
                    $menus[$name] = $tilda['menus'][$name];
                }
            }
        }
        $state = [
            'phase' => 'sitemaps',
            'start' => $start,
            'origin' => Url::origin($start),
            'prefix' => $prefix,
            'platform' => $platform,
            'detect' => $state['detect'] ?? $detect,
            'robots' => ['rules' => [], 'sitemaps' => []],
            'sitemaps' => [],
            'sitemaps_seen' => 0,
            'sitemap_urls' => 0,
            'queue' => [$start],
            'posts' => [],
            'posts_maps' => [],
            'seen' => [],
            'emitted' => 0,
            'visited' => 0,
            'discover' => false,
            'skipped' => ['robots' => 0, 'not_html' => 0, 'errors' => 0, 'duplicates' => 0, 'catalog' => 0],
            'site' => ['source_url' => $start, 'platform' => $platform, 'title' => $title, 'menus' => $menus],
            'warnings' => $state['warnings'] ?? [],
            'home_links' => [],
        ];
        // Links of the home page: the menus always go in first, whatever the sitemap says (the footer menu holds the
        // pages about the company, delivery and contacts of a shop).
        foreach ($platform === 'opencart' ? ['footer', 'header'] : ['header', 'footer'] as $menu) {
            foreach ($state['site']['menus'][$menu] as $item) {
                $this->enqueue($state, $item['url']);
            }
        }
        $state['first'] = count($state['queue']);
        if (($this->options['robots'] ?? true) !== false) {
            $r = $this->fetcher->get($state['origin'] . '/robots.txt', 512000);
            if ($r !== null && $r->ok() && !$r->isHtml()) {
                $robots = Robots::parse($r->text());
                $state['robots'] = ['rules' => $robots->rules, 'sitemaps' => $robots->sitemaps];
            }
        }
        $maps = $state['robots']['sitemaps'];
        if ($maps === []) {
            $maps = [$state['origin'] . '/sitemap.xml'];
            if ($platform === 'wordpress') {
                $maps[] = $state['origin'] . '/wp-sitemap.xml';
                $maps[] = $state['origin'] . '/sitemap_index.xml';
            }
        }
        if ($startPath !== '' && preg_match('~\.[a-z0-9]{2,5}$~i', $startPath) !== 1) {
            // Wix sites on wixsite.com and other sites in a folder keep their sitemap in that folder.
            array_unshift($maps, $state['origin'] . $startPath . '/sitemap.xml');
        }
        if ($platform === 'tilda') {
            // Tilda: the store is not imported, the posts of Feeds have their own sitemap (read even with the pages full).
            $store = array_filter($maps, static fn(string $m): bool => preg_match('~/sitemap-store~i', $m) === 1);
            if ($store !== []) {
                $state['warnings'][] = 'Магазин Tilda (sitemap-store.xml) не переносится: карточки товаров пропущены.';
                $maps = array_diff($maps, $store);
            }
            $maps[] = $state['origin'] . '/sitemap-feeds.xml';
        }
        $state['sitemaps'] = self::orderMaps(array_values(array_unique($maps)), $platform);
        // Pages linked from the home page, in case the sitemap is missing or partial.
        $base = HtmlPage::base($xp, $start);
        foreach (Dom::all($xp, '//a[@href]') as $a) {
            $state['home_links'][] = Url::resolve($base, $a->getAttribute('href'));
        }
    }

    /** @param array<string,mixed> $state */
    private function sitemap(array &$state, string $url, int $limit): void
    {
        if (++$state['sitemaps_seen'] > self::MAX_SITEMAPS) {
            return;
        }
        $r = $this->fetcher->get($url, 20971520);
        if ($r === null || !$r->ok()) {
            return;
        }
        $body = $r->body;
        if (str_ends_with(strtolower((string)parse_url($url, PHP_URL_PATH)), '.gz') || str_starts_with($body, "\x1f\x8b")) {
            $body = function_exists('gzdecode') ? (string)@gzdecode($body) : '';
        }
        if (!str_contains($body, '<urlset') && !str_contains($body, '<sitemapindex')) {
            return;
        }
        preg_match_all('~<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]]+?)\s*(?:\]\]>)?\s*</loc>~i', $body, $m);
        $isIndex = str_contains($body, '<sitemapindex');
        $isPosts = $this->isPostsMap($state, $url);
        $platform = (string)($state['platform'] ?? '');
        $children = [];
        foreach ($m[1] as $loc) {
            $loc = html_entity_decode(trim($loc), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($isIndex) {
                if ($platform === 'tilda' && preg_match('~/sitemap-store~i', $loc) === 1) {
                    continue;
                }
                if ($isPosts) {
                    $state['posts_maps'][$loc] = true;
                }
                $children[] = $loc;
                continue;
            }
            // A Tilda feed sitemap lists the pages that show the feed too: only the posts themselves go to the posts.
            $target = $isPosts && ($platform !== 'tilda' || preg_match('~/tpost/~', $loc) === 1) ? 'posts' : 'queue';
            if (count($state[$target]) < self::cap($limit) && $this->enqueue($state, $loc, $target)) {
                $state['sitemap_urls'] = ($state['sitemap_urls'] ?? 0) + 1;
            }
        }
        if ($children !== []) {
            // The sitemaps of an index go before the rest, in their order (pages, posts, then the less useful ones).
            $ordered = self::orderMaps($children, $platform);
            $tail = array_values(array_filter($ordered, fn(string $c): bool => self::mapRank($c, $platform) >= 3));
            $head = array_values(array_filter($ordered, fn(string $c): bool => self::mapRank($c, $platform) < 3));
            $state['sitemaps'] = array_merge($head, $state['sitemaps'], $tail);
        }
    }

    /** Sitemap of posts: Tilda Feeds, WordPress posts (Yoast, Rank Math, AIOSEO, the core sitemap). @param array<string,mixed> $state */
    private function isPostsMap(array $state, string $url): bool
    {
        if (isset($state['posts_maps'][$url])) {
            return true;
        }
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        return preg_match('~/sitemap-feeds?(-[^/]*)?\.xml~', $path) === 1
            || ($state['platform'] ?? '') === 'wordpress' && preg_match('~/(post-sitemap\d*\.xml|wp-sitemap-posts-post-\d+\.xml)$~', $path) === 1;
    }

    /**
     * Rank of a sitemap: 0 pages, 1 posts, 2 anything else, 3 images, videos and products of a shop (last), 9 not read
     * (archives of WordPress categories, tags and authors: the same posts again).
     */
    public static function mapRank(string $url, string $platform): int
    {
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        if ($platform === 'wordpress') {
            if (preg_match('~/(page-sitemap\d*\.xml|wp-sitemap-posts-page-\d+\.xml)$~', $path) === 1) {
                return 0;
            }
            if (preg_match('~/(post-sitemap\d*\.xml|wp-sitemap-posts-post-\d+\.xml)$~', $path) === 1) {
                return 1;
            }
            if (preg_match('~wp-sitemap-(taxonomies|users)-|/(category|post_tag|tag|author|[a-z0-9_-]+[-_](category|cat|tag|tags)|product_cat|product_tag|post_format)-sitemap\d*\.xml$~', $path) === 1) {
                return 9;
            }
            if (preg_match('~/(sitemap_index|wp-sitemap|sitemap)\.xml$~', $path) === 1) {
                return 0;
            }
            // Custom post types (reviews, testimonials, portfolio, products): after the pages and posts.
            return preg_match('~(image|video|product|attachment)~', $path) === 1 ? 4 : 3;
        }
        if (preg_match('~/sitemap-feeds?(-[^/]*)?\.xml~', $path) === 1) {
            return 1;
        }
        return preg_match('~(image|video|product|tovar|catalog|attachment)~', $path) === 1 ? 3 : 2;
    }

    /** @param list<string> $maps @return list<string> */
    private static function orderMaps(array $maps, string $platform): array
    {
        $ranked = [];
        foreach (array_values($maps) as $i => $m) {
            $rank = self::mapRank($m, $platform);
            if ($rank < 9) {
                $ranked[] = [$rank, $i, $m];
            }
        }
        usort($ranked, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column($ranked, 2);
    }

    /**
     * Adds an address of the site to the queue. True when it went in.
     *
     * @param array<string,mixed> $state
     */
    private function enqueue(array &$state, string $url, string $target = 'queue'): bool
    {
        $url = Dom::encodeUrl(Url::stripFragment($url));
        if ($url === '' || !preg_match('~^https?://~i', $url) || !Url::sameSite($url, $state['start']) || Url::isFile($url)) {
            return false;
        }
        $path = (string)parse_url($url, PHP_URL_PATH);
        $query = (string)parse_url($url, PHP_URL_QUERY);
        if (preg_match(self::SKIP_PATHS, $path) === 1 || preg_match(self::SKIP_PAGES, $path) === 1 || ($query !== '' && preg_match(self::SKIP_QUERY, $query) === 1)) {
            return false;
        }
        // The query is kept only when it names a page (?p=12, index.php?id=3).
        $clean = Url::origin($url) . ($path === '' ? '/' : $path);
        $pageQuery = Url::pageQuery($query);
        if ($pageQuery !== '') {
            $clean .= '?' . $pageQuery;
        }
        $key = Url::key(Url::oldPath($clean));
        if (isset($state['seen'][$key]) || isset($state['queued'][$key])) {
            return false;
        }
        $state['queued'][$key] = true;
        $state[$target][] = $clean;
        return true;
    }

    /**
     * A page of an OpenCart catalog: the markers of the default theme (product-product, product-category), schema.org
     * Product, or several product cards with an add-to-cart field in a custom theme. Information pages never are.
     */
    public static function isShopCatalog(string $html): bool
    {
        if (preg_match('~id="information-|class="[^"]*\binformation-(information|contact|sitemap)~i', $html) === 1) {
            return false;
        }
        if (preg_match(self::OPENCART_CATALOG, $html) === 1) {
            return true;
        }
        return substr_count($html, 'name="product_id"') >= 3 || preg_match_all('~class="[^"]*\b(product-thumb|product-layout|catalog-card__item|product-card|product-item)\b~i', $html) >= 3;
    }

    /** How many addresses the sitemaps may give: enough to choose from, not the whole shop. */
    private static function cap(int $limit): int
    {
        return min(5000, max($limit * 5, 300));
    }

    /**
     * Order of the queue taken from sitemaps under a page limit: the home page and the menus as they were, then pages
     * one level deep, then a page from every section in turn (a section is the folder of the address), so that news,
     * services and articles all get a share instead of the first hundred products of the sitemap.
     *
     * @param list<string> $queue
     * @return list<string>
     */
    public static function prioritize(array $queue, int $keep): array
    {
        $out = array_slice($queue, 0, $keep);
        $groups = [];
        $pending = [];
        foreach (array_slice($queue, $keep) as $url) {
            $path = trim(Url::oldPath($url, false), '/');
            $dir = str_contains($path, '/') ? substr($path, 0, (int)strrpos($path, '/')) : '';
            $groups[$dir][] = $url;
            $pending[Url::key('/' . $path)] = $url;
        }
        // A page goes after its parent: the folder page above it, when it is in the queue, comes first.
        $take = static function (string $url) use (&$take, &$out, &$pending): void {
            $path = trim(Url::oldPath($url, false), '/');
            $key = Url::key('/' . $path);
            if (!isset($pending[$key])) {
                return;
            }
            unset($pending[$key]);
            for ($up = $path; str_contains($up, '/');) {
                $up = substr($up, 0, (int)strrpos($up, '/'));
                if (isset($pending[Url::key('/' . $up)])) {
                    $take($pending[Url::key('/' . $up)]);
                    break;
                }
            }
            $out[] = $url;
        };
        foreach ($groups[''] ?? [] as $url) {
            $take($url);
        }
        unset($groups['']);
        $dirs = array_keys($groups);
        usort($dirs, static fn($a, $b): int => substr_count((string)$a, '/') <=> substr_count((string)$b, '/'));
        while ($dirs !== []) {
            foreach ($dirs as $i => $dir) {
                $take((string)array_shift($groups[$dir]));
                if ($groups[$dir] === []) {
                    unset($dirs[$i]);
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $state
     * @param callable(array<string,mixed>):void $emit
     */
    private function visit(array &$state, string $url, callable $emit): void
    {
        $key = Url::key(Url::oldPath($url));
        if (isset($state['seen'][$key])) {
            return;
        }
        $state['seen'][$key] = true;
        $robots = new Robots($state['robots']['rules'], []);
        $pathWithQuery = ((string)parse_url($url, PHP_URL_PATH) ?: '/') . (($q = (string)parse_url($url, PHP_URL_QUERY)) !== '' ? '?' . $q : '');
        if (!$robots->allowed($pathWithQuery)) {
            $state['skipped']['robots']++;
            return;
        }
        $state['visited'] = ($state['visited'] ?? 0) + 1;
        $r = $this->fetcher->get($url);
        if ($r === null || !$r->ok()) {
            $state['skipped']['errors']++;
            if ($r !== null && $r->status >= 400 && $state['skipped']['errors'] <= 20) {
                $state['warnings'][] = 'Адрес ' . Url::oldPath($url) . ' ответил кодом ' . $r->status . ', пропущен.';
            }
            return;
        }
        if (!$r->isHtml()) {
            $state['skipped']['not_html']++;
            return;
        }
        if (!Url::sameSite($r->url, $state['start'])) {
            return;
        }
        $finalKey = Url::key(Url::oldPath($r->url));
        if ($finalKey !== $key) {
            if (isset($state['seen'][$finalKey])) {
                $state['skipped']['duplicates']++;
                return;
            }
            $state['seen'][$finalKey] = true;
        }
        $html = $r->text();
        if ($state['platform'] === 'opencart' && $state['emitted'] > 0 && self::isShopCatalog($html)) {
            $state['skipped']['catalog'] = ($state['skipped']['catalog'] ?? 0) + 1;
            $state['catalog_run'] = ($state['catalog_run'] ?? 0) + 1;
            return;
        }
        $state['catalog_run'] = 0;
        $page = self::parsePage($html, $r->url, (string)$state['platform']);
        if ($page === null) {
            return;
        }
        $prefix = (string)($state['prefix'] ?? '');
        if ($prefix !== '') {
            // The folder of the site is its root: /site/about becomes /about, the full old address stays an alias.
            $full = $page['old_path'];
            if ($full === $prefix || str_starts_with($full, $prefix . '/') || str_starts_with($full, $prefix . '?')) {
                $page['old_path'] = '/' . ltrim(substr($full, strlen($prefix)), '/');
                $page['aliases'][] = $full;
                if (($page['parent_old_path'] ?? '') !== '' && str_starts_with($page['parent_old_path'], $prefix . '/')) {
                    $page['parent_old_path'] = '/' . ltrim(substr($page['parent_old_path'], strlen($prefix)), '/');
                }
            }
        }
        // The canonical address of another page: a duplicate (print version, sorting, tracking parameters).
        if ($page['canonical'] !== '' && Url::sameSite($page['canonical'], $r->url)) {
            $canonicalKey = Url::key(Url::oldPath($page['canonical']));
            if ($canonicalKey !== $finalKey && isset($state['emitted_keys'][$canonicalKey])) {
                $state['skipped']['duplicates']++;
                return;
            }
        }
        // The same text under another address (Joomla shows an article under every menu item that lists it).
        $body = '';
        array_walk_recursive($page['elements'], static function ($v, $k) use (&$body): void {
            if (is_string($v) && in_array($k, ['text', 'html', 'q', 'a', 'h1', 'subtitle'], true) || is_string($v) && is_int($k)) {
                $body .= ' ' . strip_tags($v);
            }
        });
        $body = Dom::clean($body);
        if (mb_strlen($body) >= 200) {
            $hash = md5($page['title'] . "\n" . $body);
            if (isset($state['content'][$hash])) {
                $state['skipped']['duplicates']++;
                return;
            }
            $state['content'][$hash] = true;
        }
        if (Url::oldPath($url) !== $page['old_path'] && Url::oldPath($url) !== ($page['aliases'][count($page['aliases']) - 1] ?? '')) {
            $page['aliases'][] = Url::oldPath($url);
        }
        if (isset($page['menus'])) {
            foreach (['header', 'footer'] as $menu) {
                if (($state['site']['menus'][$menu] ?? []) === [] && ($page['menus'][$menu] ?? []) !== []) {
                    $state['site']['menus'][$menu] = $page['menus'][$menu];
                }
            }
            unset($page['menus']);
        }
        $state['emitted_keys'][$finalKey] = true;
        $state['emitted']++;
        $emit($page);

        $links = $state['discover'] || $state['emitted'] === 1 ? $this->links($html, $r->url) : [];
        if ($state['emitted'] === 1) {
            $links = array_merge($links, $state['home_links']);
            $state['home_links'] = [];
            if ($state['platform'] === 'opencart' && !$state['discover']) {
                // A shop sitemap is the catalog first and the information pages at its very end (hobby-idea.ru: 9492
                // addresses): the pages of the first level linked from the home page, footer first, go before it.
                $front = [];
                foreach (array_reverse($links) as $link) {
                    $path = trim((string)parse_url(Url::ascii($link), PHP_URL_PATH), '/');
                    if ($path !== '' && !str_contains($path, '/') && Url::sameSite($link, $state['start']) && $this->enqueue($state, $link, 'front')) {
                        $front[] = array_pop($state['front']);
                    }
                }
                $state['queue'] = array_merge($front, $state['queue']);
                unset($state['front']);
            }
        }
        if ($state['discover'] || $state['emitted'] === 1) {
            foreach ($links as $link) {
                $this->enqueue($state, $link);
            }
        }
    }

    /** @return list<string> */
    private function links(string $html, string $base): array
    {
        if (preg_match('~<base\s[^>]*href\s*=\s*["\']([^"\']+)~i', $html, $b) === 1 && ($resolved = Url::resolve($base, $b[1])) !== '') {
            $base = $resolved;
        }
        preg_match_all('~<a\s[^>]*href\s*=\s*["\']([^"\'#]+)~i', $html, $m);
        return array_map(static fn(string $h): string => Url::resolve($base, $h), $m[1]);
    }

    /** One page by the parser of its platform. Null when there is nothing to take. */
    public static function parsePage(string $html, string $url, string $platform): ?array
    {
        if ($platform === 'tilda' || str_contains($html, 't-records') && str_contains($html, 'data-record-type')) {
            $page = Tilda::parsePage($html, $url);
            if ($page !== null) {
                return $page;
            }
        }
        $profile = $platform === 'bitrix' ? Bitrix::profile() : Profile::for($platform);
        $page = HtmlPage::parse($html, $url, $profile);
        if ($platform === 'bitrix') {
            $page = Bitrix::refine($page, $html);
        }
        return $page;
    }
}
