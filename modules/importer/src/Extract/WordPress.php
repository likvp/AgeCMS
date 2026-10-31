<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Detect\Detector;
use AgeCMS\Modules\Importer\Html\Dom;
use AgeCMS\Modules\Importer\Html\MenuFinder;
use AgeCMS\Modules\Importer\ImportException;
use AgeCMS\Modules\Importer\Neutral;
use AgeCMS\Modules\Importer\Net\Fetcher;
use AgeCMS\Modules\Importer\Net\Response;
use AgeCMS\Modules\Importer\Net\Robots;
use AgeCMS\Modules\Importer\Url;

/**
 * WordPress through its open REST API (/wp-json/wp/v2/, or ?rest_route= when pretty links are off): pages, then posts,
 * page by page. SEO from yoast_head_json and the Rank Math / AIOSEO fields, featured images, old addresses (link, ?p=,
 * ?page_id=). A page built with Elementor, Divi or WPBakery is taken from its rendered address instead (the REST content
 * of a builder is not the page). Without the API the site is crawled like any other, with the WordPress profile; the
 * same when robots.txt closes the API to robots (Disallow: /wp-json/, or *? for addresses with a query).
 */
final class WordPress implements Extractor
{
    private const PER_PAGE = 50;

    /** @param array{url:string,limit?:int,robots?:bool} $options */
    public function __construct(private Fetcher $fetcher, private array $options)
    {
    }

    public function step(array &$state, float $deadline, callable $emit): bool
    {
        if (($state['mode'] ?? '') === 'generic') {
            return (new Generic($this->fetcher, $this->options + ['platform' => 'wordpress']))->step($state['generic'], $deadline, $emit) && $this->finishGeneric($state);
        }
        if (($state['phase'] ?? '') === '') {
            $this->init($state);
            if (($state['mode'] ?? '') === 'generic') {
                return $this->step($state, $deadline, $emit);
            }
        }
        $limit = max(1, (int)($this->options['limit'] ?? 200));
        while (microtime(true) < $deadline && $state['phase'] !== 'done') {
            if ($state['emitted'] >= $limit) {
                $state['warnings'][] = 'Достигнут лимит страниц (' . $limit . '), остальные записи не взяты.';
                $state['phase'] = 'done';
                break;
            }
            $type = $state['phase'];
            // Pages leave a third of the limit to the posts (when the site has posts), else the blog never gets in.
            $pagesLimit = $limit - (int)($state['posts_share'] ?? 0);
            if ($type === 'pages' && $state['emitted'] >= $pagesLimit) {
                $this->next($state);
                continue;
            }
            $url = $this->endpoint($state, $type, ['per_page' => self::PER_PAGE, 'page' => $state['page'], '_embed' => 'wp:featuredmedia', 'orderby' => $type === 'pages' ? 'id' : 'date', 'order' => $type === 'pages' ? 'asc' : 'desc']);
            $r = $this->fetcher->get($url, 20971520);
            $items = $r !== null && $r->ok() ? json_decode($r->body, true) : null;
            if (!is_array($items) || !array_is_list($items) || $items === []) {
                $this->next($state);
                continue;
            }
            foreach ($items as $item) {
                if ($state['emitted'] >= ($type === 'pages' ? $pagesLimit : $limit)) {
                    if ($type === 'pages' && $pagesLimit < $limit) {
                        $state['warnings'][] = 'Страниц взято ' . $state['emitted'] . ': остальная часть лимита (' . ($limit - $pagesLimit) . ') отдана записям блога.';
                    }
                    break;
                }
                if (!is_array($item) || ($item['status'] ?? 'publish') !== 'publish') {
                    continue;
                }
                $page = $this->page($item, $type === 'posts' ? 'post' : 'page', $state);
                if ($page !== null) {
                    $state['emitted']++;
                    $emit($page);
                }
            }
            $total = (int)($r->header('x-wp-totalpages') ?: 0);
            if (count($items) < self::PER_PAGE || ($total > 0 && $state['page'] >= $total) || $type === 'pages' && $state['emitted'] >= $pagesLimit) {
                $this->next($state);
            } else {
                $state['page']++;
            }
        }
        return $state['phase'] === 'done';
    }

    /** @param array<string,mixed> $state */
    private function next(array &$state): void
    {
        $state['phase'] = $state['phase'] === 'pages' ? 'posts' : 'done';
        $state['page'] = 1;
    }

    /** @param array<string,mixed> $state */
    private function finishGeneric(array &$state): bool
    {
        $state['site'] = $state['generic']['site'] ?? $state['site'];
        $state['warnings'] = array_merge($state['warnings'] ?? [], $state['generic']['warnings'] ?? []);
        $state['generic']['warnings'] = [];
        return true;
    }

    /** @param array<string,mixed> $state */
    private function init(array &$state): void
    {
        $start = trim((string)$this->options['url']);
        $home = $this->fetcher->get($start);
        if ($home === null || !$home->ok()) {
            throw new ImportException('Сайт не открылся: ' . $start . ($home !== null ? ' (код ' . $home->status . ')' : ''));
        }
        $origin = Url::origin($home->url);
        $doc = Dom::load($home->text());
        $xp = Dom::xpath($doc);
        $siteName = Dom::meta($xp, 'og:site_name');
        $state = [
            'phase' => 'pages',
            'page' => 1,
            'emitted' => 0,
            'origin' => $origin,
            'detect' => $state['detect'] ?? Detector::detect($home),
            'site' => [
                'source_url' => $home->url,
                'platform' => 'wordpress',
                'title' => $siteName !== '' ? $siteName : HtmlPage::stripSiteName(Neutral::line(Dom::first($xp, '//title')?->textContent ?? '')),
                'menus' => MenuFinder::find($doc, $home->url),
            ],
            'warnings' => $state['warnings'] ?? [],
            'api' => '',
            'robots' => [],
            // An SEO plugin that keeps its title and description out of the REST API (Yoast gives them as yoast_head_json).
            'seo_plugin' => preg_match('~<!-- [^>]*(Rank Math|All in One SEO|AIOSEO|SEOPress|The SEO Framework|Squirrly)~i', $home->text()) === 1,
            // What the theme prints on every page (footer, shared forms and contacts), seen on the home page: left out of
            // pages taken from their rendered address.
            'chrome' => array_slice(array_values(array_unique(array_filter(array_map(self::signature(...), HtmlPage::parse($home->text(), $home->url, Profile::for('wordpress'))['elements'])))), 0, 500),
        ];
        if (($this->options['robots'] ?? true) !== false) {
            $r = $this->fetcher->get($origin . '/robots.txt', 512000);
            if ($r !== null && $r->ok() && !$r->isHtml()) {
                $state['robots'] = Robots::parse($r->text())->rules;
            }
        }
        $closed = 0;
        foreach ([$origin . '/wp-json/wp/v2/', $origin . '/?rest_route=/wp/v2/'] as $api) {
            $state['api'] = $api;
            $url = $this->endpoint($state, 'pages', ['per_page' => self::PER_PAGE, 'page' => 1]);
            if (!$this->allowed($state, $url)) {
                // Only the main address counts: /?rest_route= is the same API, closed by "Disallow: /?" on many sites.
                $closed += str_contains($api, '/wp-json/') ? 1 : 0;
                continue;
            }
            $probe = $this->fetcher->get($this->endpoint($state, 'pages', ['per_page' => 1]), 2097152);
            if ($probe !== null && $probe->ok() && is_array(json_decode($probe->body, true))) {
                // How many posts there are (X-WP-Total): they get up to a third of the limit.
                $posts = $this->fetcher->get($this->endpoint($state, 'posts', ['per_page' => 1]), 2097152);
                $count = $posts !== null && $posts->ok() ? (int)($posts->header('x-wp-total') ?: (is_array($list = json_decode($posts->body, true)) ? count($list) : 0)) : 0;
                $state['posts_share'] = min($count, intdiv(max(1, (int)($this->options['limit'] ?? 200)), 3));
                return;
            }
        }
        // No open REST API (closed by a security plugin or by robots.txt): crawl the rendered site.
        $state['mode'] = 'generic';
        $state['warnings'][] = $closed > 0
            ? 'robots.txt запрещает роботам адреса REST API WordPress (/wp-json/ или адреса с «?»): страницы собраны с самого сайта, как у обычного сайта. Если это ваш сайт, точнее перенос через API: снимите галочку «Соблюдать robots.txt» (в командной строке --no-robots).'
            : 'REST API WordPress закрыт: страницы собраны с самого сайта, как у обычного сайта.';
        $state['generic'] = ['detect' => $state['detect']];
    }

    /** May a robot fetch this address by robots.txt of the source (rules for "*")? @param array<string,mixed> $state */
    private function allowed(array $state, string $url): bool
    {
        $rules = (array)($state['robots'] ?? []);
        if ($rules === []) {
            return true;
        }
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '/');
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
        return (new Robots($rules, []))->allowed(($path === '' ? '/' : $path) . ($query !== '' ? '?' . $query : ''));
    }

    /** @param array<string,mixed> $state @param array<string,scalar> $query */
    private function endpoint(array $state, string $type, array $query): string
    {
        $api = (string)$state['api'];
        $sep = str_contains($api, '?') ? '&' : '?';
        return $api . $type . $sep . http_build_query($query);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $state
     * @return array<string,mixed>|null
     */
    private function page(array $item, string $kind, array &$state): ?array
    {
        $link = (string)($item['link'] ?? '');
        if ($link === '' || !Url::sameSite($link, $state['origin'])) {
            return null;
        }
        $id = (int)($item['id'] ?? 0);
        $path = Url::oldPath($link);
        $aliases = [$kind === 'post' ? '/?p=' . $id : '/?page_id=' . $id];
        if (($guid = (string)($item['guid']['rendered'] ?? '')) !== '' && Url::sameSite($guid, $state['origin']) && str_contains($guid, '?')) {
            $aliases[] = Url::oldPath($guid);
        }
        $title = Neutral::line(html_entity_decode(strip_tags((string)($item['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $content = (string)($item['content']['rendered'] ?? '');
        $notes = [];
        $seo = self::seo($item);
        $elements = [];
        if (($item['content']['protected'] ?? false) === true) {
            $content = '';
            // The rendered title of a protected post starts with "Protected: " in the language of the site.
            $title = preg_replace('~^[\p{L} ]{3,24}:\s+~u', '', $title) ?? $title;
            $notes[] = ['what' => 'Текст ' . ($kind === 'post' ? 'записи' : 'страницы'), 'why' => 'защищён паролем в WordPress: перенесён только заголовок, текст добавьте вручную', 'source' => $path];
        }
        // The page itself, fetched once and only when needed (robots.txt allowing).
        $fetched = false;
        $page = null;
        $rendered = function () use (&$fetched, &$page, $link, $state): ?Response {
            if (!$fetched) {
                $fetched = true;
                $r = $this->allowed($state, $link) ? $this->fetcher->get($link) : null;
                $page = $r !== null && $r->ok() && $r->isHtml() ? $r : null;
            }
            return $page;
        };
        $builder = Gutenberg::isBuilder($content) || (string)($item['template'] ?? '') === 'elementor_canvas' || isset($item['meta']['_elementor_edit_mode']);
        if ($builder && ($r = $rendered()) !== null) {
            $elements = self::fromPage($r, $title, $link, $state, $notes);
            $notes[] = ['what' => 'Страница собрана конструктором (Elementor, Divi или WPBakery)', 'why' => 'взята готовая страница сайта: тексты и картинки перенесены, колонки и оформление нет', 'source' => $path];
        }
        if ($elements === []) {
            $elements = Gutenberg::toElements($content, $link, [], [], $notes);
            $notes = array_merge($notes, Gutenberg::renderedNotes($content));
            // A page template of the theme (prices, contacts, team) keeps its text in custom fields, not in the editor: the
            // REST content is empty or a line, the page itself has everything.
            if (($item['content']['protected'] ?? false) !== true && self::textLength($elements) < 200 && ($r = $rendered()) !== null) {
                $pageNotes = [];
                $fromPage = self::fromPage($r, $title, $link, $state, $pageNotes);
                if (self::textLength($fromPage) > self::textLength($elements) + 100) {
                    $elements = $fromPage;
                    $notes = array_merge($notes, $pageNotes);
                    $notes[] = ['what' => 'Текст страницы взят с готовой страницы сайта', 'why' => 'в редакторе WordPress его нет: шаблон темы (' . ((string)($item['template'] ?? '') !== '' ? (string)$item['template'] : 'по умолчанию') . ') выводит поля темы; проверьте, что на странице нет лишнего', 'source' => $path];
                }
            }
        }
        // Rank Math, AIOSEO, SEOPress do not give their fields to the REST API: the page head has them.
        if ($seo['title'] === '' && $seo['description'] === '' && ($state['seo_plugin'] ?? false) === true && ($r = $rendered()) !== null) {
            $doc = Dom::load($r->text());
            $meta = HtmlPage::meta($doc, Dom::xpath($doc), $r->url, Profile::for('wordpress'));
            $seo = ['title' => (string)$meta['meta_title'], 'description' => (string)$meta['meta_description'], 'canonical' => (string)$meta['canonical'], 'og_image' => (string)$meta['og_image'], 'noindex' => (bool)$meta['is_noindex']];
        }
        // Service pages of WooCommerce (cart, checkout, account): the shop is not carried over, they are empty without it.
        $skip = '';
        if ($kind === 'page' && (preg_match('~woocommerce[_/-](cart|checkout|my[_-]account|order[_-]tracking)|wc-block-(cart|checkout)|wp-block-woocommerce-(cart|checkout)|class="woocommerce[ "]~i', $content) === 1
            || preg_match('~^(cart|checkout|my-account|wishlist|basket|place-order|order-received|korzina|oformlenie-zakaza|moj-akkaunt|lichnyj-kabinet)$~', rawurldecode((string)($item['slug'] ?? ''))) === 1) && self::textLength($elements) < 300) {
            $skip = 'служебная страница магазина (корзина, оформление заказа, личный кабинет): магазин не переносится';
            $notes[] = ['what' => 'Служебная страница магазина', 'why' => 'корзина, оформление заказа и личный кабинет работают только с магазином, страница не переносится', 'source' => $path];
        }
        $featured = (string)($item['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '');
        $elements = Gutenberg::postTop($elements, $kind, $title, $featured, Neutral::line((string)($item['_embedded']['wp:featuredmedia'][0]['alt_text'] ?? '')), 'featured_media');
        $excerpt = ($item['content']['protected'] ?? false) === true ? '' : Neutral::line(html_entity_decode(strip_tags((string)($item['excerpt']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return Neutral::page([
            'source_url' => $link,
            'old_path' => $path,
            'aliases' => $aliases,
            'suggested_slug' => rawurldecode((string)($item['slug'] ?? '')),
            'parent_old_path' => (int)($item['parent'] ?? 0) > 0 ? self::parentPath($path) : '',
            'kind' => $kind,
            'title' => $title,
            'meta_title' => $seo['title'],
            'meta_description' => $seo['description'] !== '' ? $seo['description'] : mb_substr(preg_replace('~\s*\[(…|&hellip;|\.\.\.)\]\s*$~u', '', $excerpt) ?? '', 0, 300),
            'canonical' => $seo['canonical'],
            'og_image' => $seo['og_image'] !== '' ? $seo['og_image'] : $featured,
            'published_at' => $kind === 'post' ? (string)($item['date'] ?? '') : '',
            'is_noindex' => $seo['noindex'],
            'elements' => $elements,
            'notes' => $notes,
            'wp_id' => $id,
        ] + ($skip !== '' ? ['skip' => $skip] : []));
    }

    /**
     * Elements of the rendered page. The h1 HtmlPage puts first when the content has none is the first h1 of the whole
     * document; on a builder page it can be the title of a product card in a dropped loop widget: only the page title
     * stays. Parts the home page has too (a footer the theme does not mark as one, shared forms, contacts) are left out.
     *
     * @param array<string,mixed> $state
     * @param list<array{what:string,why:string,source:string}> $notes
     * @return list<array<string,mixed>>
     */
    private static function fromPage(Response $r, string $title, string $link, array $state, array &$notes): array
    {
        $els = HtmlPage::parse($r->text(), $r->url, Profile::for('wordpress'))['elements'];
        if (($els[0]['type'] ?? '') === 'heading' && (int)$els[0]['level'] === 1 && ($els[0]['source'] ?? '') === 'h1' && mb_strtolower(Neutral::line((string)$els[0]['text'])) !== mb_strtolower($title)) {
            array_shift($els);
        }
        $chrome = array_flip((array)($state['chrome'] ?? []));
        if ($chrome === [] || Url::oldPath($link) === '/') {
            return $els;
        }
        // Only the runs at the start and at the end of the page: a header and a footer the theme draws around the
        // content. The same text in the middle (a price list the home page repeats) is the page's own.
        $match = static fn(array $el): bool => !($el['type'] === 'heading' && (int)$el['level'] === 1) && ($sig = self::signature($el)) !== '' && isset($chrome[$sig]);
        $from = 0;
        $to = count($els);
        while ($from < $to && $match($els[$from])) {
            $from++;
        }
        while ($to > $from && $match($els[$to - 1])) {
            $to--;
        }
        $kept = array_slice($els, $from, $to - $from);
        $dropped = count($els) - count($kept);
        // A page whose own content is what the home page shows too (reviews, team): kept whole.
        if ($dropped > 0 && self::textLength($kept) < 150) {
            return $els;
        }
        if ($dropped > 0) {
            $notes[] = ['what' => 'Общие части сайта (' . $dropped . ')', 'why' => 'они есть и на главной (подвал, формы, контакты): на странице не повторяются', 'source' => Url::oldPath($link)];
        }
        return $kept;
    }

    /** A short fingerprint of an element by its text, to find the parts every page repeats. @param array<string,mixed> $el */
    private static function signature(array $el): string
    {
        $text = match ($el['type'] ?? '') {
            'heading' => (string)$el['text'],
            'paragraph', 'quote', 'table' => Neutral::text((string)$el['html']),
            'list' => Neutral::text(implode("\n", array_map('strval', (array)$el['items']))),
            'image' => (string)$el['src'],
            'button' => $el['text'] . ' ' . $el['url'],
            'form' => json_encode($el['fields'] ?? []) . ($el['submit_text'] ?? ''),
            'faq' => json_encode($el['items'] ?? [], JSON_UNESCAPED_UNICODE),
            default => '',
        };
        $text = mb_strtolower(Neutral::line((string)$text));
        return mb_strlen($text) < 4 ? '' : $el['type'] . ':' . substr(md5($text), 0, 12);
    }

    /** Length of the text of elements, to tell an empty page from a real one. @param list<array<string,mixed>> $els */
    private static function textLength(array $els): int
    {
        $n = 0;
        foreach ($els as $el) {
            foreach (['text', 'html', 'h1', 'subtitle', 'caption'] as $k) {
                $n += mb_strlen(trim(strip_tags((string)($el[$k] ?? ''))));
            }
            foreach ((array)($el['items'] ?? []) as $item) {
                $n += mb_strlen(trim(strip_tags(is_array($item) ? (string)($item['q'] ?? '') . (string)($item['a'] ?? '') : (string)$item)));
            }
            $n += 40 * count((array)($el['images'] ?? [])) + ($el['type'] === 'image' ? 40 : 0);
        }
        return $n;
    }

    private static function parentPath(string $path): string
    {
        $trimmed = rtrim($path, '/');
        $pos = strrpos($trimmed, '/');
        return $pos === false || $pos === 0 ? '' : substr($trimmed, 0, $pos) . '/';
    }

    /**
     * SEO fields from Yoast (yoast_head_json), Rank Math and AIOSEO when they are exposed.
     *
     * @param array<string,mixed> $item
     * @return array{title:string,description:string,canonical:string,og_image:string,noindex:bool}
     */
    public static function seo(array $item): array
    {
        $y = is_array($item['yoast_head_json'] ?? null) ? $item['yoast_head_json'] : [];
        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        $title = (string)($y['title'] ?? $item['rank_math_title'] ?? $meta['rank_math_title'] ?? $item['aioseo_head_json']['title'] ?? '');
        $description = (string)($y['description'] ?? $item['rank_math_description'] ?? $meta['rank_math_description'] ?? $item['aioseo_head_json']['description'] ?? '');
        $robots = is_array($y['robots'] ?? null) ? implode(',', $y['robots']) : (string)($y['robots'] ?? '');
        return [
            'title' => Neutral::line(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'description' => Neutral::line(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'canonical' => (string)($y['canonical'] ?? ''),
            'og_image' => (string)($y['og_image'][0]['url'] ?? ''),
            'noindex' => str_contains(strtolower($robots), 'noindex'),
        ];
    }
}
