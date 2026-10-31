<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\Database;
use AgeCMS\ModuleRoutes;
use AgeCMS\Pages;
use AgeCMS\Theme;
use AgeCMS\Modules\Importer\Detect\Detector;
use AgeCMS\Modules\Importer\Extract\Extractor;
use AgeCMS\Modules\Importer\Extract\Generic;
use AgeCMS\Modules\Importer\Extract\TildaZip;
use AgeCMS\Modules\Importer\Extract\WordPress;
use AgeCMS\Modules\Importer\Extract\Wxr;
use AgeCMS\Modules\Importer\Net\Fetcher;

/**
 * The importer as a whole: what the source is, which extractor reads it, how the found pages become a plan of pages
 * with blocks of the active theme. Used by the admin page, the agent skills, the command line and the tests; the
 * stepwise job on top of it is Runner.
 */
final class Importer
{
    public const DEFAULTS = ['limit' => 200, 'delay_ms' => 500, 'robots' => true, 'conflict' => 'suffix', 'replace_home' => false, 'menus' => true, 'section' => '', 'chunk' => 5];

    private Fetcher $fetcher;
    /** @var array<string,mixed> */
    public array $options;

    /** @param array<string,mixed> $options see DEFAULTS */
    public function __construct(array $options = [], ?Fetcher $fetcher = null)
    {
        $this->options = self::options($options);
        $this->fetcher = $fetcher ?? Net\Network::fetcher((int)$this->options['delay_ms']);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public static function options(array $options): array
    {
        $o = $options + self::DEFAULTS;
        $o['limit'] = max(1, min(2000, (int)$o['limit']));
        $o['delay_ms'] = max(0, min(10000, (int)$o['delay_ms']));
        $o['chunk'] = max(1, min(20, (int)$o['chunk']));
        $o['robots'] = (bool)$o['robots'];
        $o['replace_home'] = (bool)$o['replace_home'];
        $o['menus'] = (bool)$o['menus'];
        $o['conflict'] = $o['conflict'] === 'skip' ? 'skip' : 'suffix';
        $o['section'] = trim((string)$o['section'], '/');
        return $o;
    }

    public function fetcher(): Fetcher
    {
        return $this->fetcher;
    }

    /**
     * What the input is: a site address, a WordPress export file, a Tilda export archive or one saved HTML page.
     *
     * @return array{type:string,source:string}
     */
    public static function source(string $input): array
    {
        $input = trim($input);
        if ($input !== '' && is_file($input)) {
            $head = (string)file_get_contents($input, false, null, 0, 4096);
            if (Wxr::looks($head)) {
                return ['type' => 'wxr', 'source' => $input];
            }
            if (str_starts_with($head, "PK\x03\x04")) {
                if (!TildaZip::looks($input)) {
                    throw new ImportException('Архив не похож на экспорт проекта Tilda (нет файлов page*.html).');
                }
                return ['type' => 'tilda_zip', 'source' => $input];
            }
            if (preg_match('~<(html|body|div)[\s>]~i', $head) === 1) {
                return ['type' => 'html_file', 'source' => $input];
            }
            throw new ImportException('Файл не распознан: нужна выгрузка WordPress (.xml), архив экспорта Tilda (.zip) или адрес сайта.');
        }
        if ($input === '') {
            throw new ImportException('Укажите адрес сайта или файл выгрузки.');
        }
        if (preg_match('~^https?://~i', $input) !== 1) {
            if (preg_match('~^[a-zа-яё0-9.-]+\.[a-zа-яё]{2,}(/.*)?$~iu', $input) !== 1) {
                throw new ImportException('Это не адрес сайта: ' . mb_substr($input, 0, 100));
            }
            $input = 'https://' . $input;
        }
        $host = (string)parse_url($input, PHP_URL_HOST);
        if ($host !== '' && preg_match('~[^\x00-\x7F]~', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii)) {
                $input = str_replace($host, $ascii, $input);
            }
        }
        return ['type' => 'url', 'source' => $input];
    }

    /** Platform of a site by its home page (and, for WordPress, the REST API). @return array<string,mixed> */
    public function detect(string $url): array
    {
        $src = self::source($url);
        if ($src['type'] !== 'url') {
            throw new ImportException('Для распознавания нужен адрес сайта.');
        }
        $home = $this->fetcher->get($src['source']);
        if ($home === null) {
            throw new ImportException('Сайт не открылся: адрес недоступен с этого сервера (' . $src['source'] . ').');
        }
        $result = Detector::detect($home);
        $result['url'] = $home->url;
        $result['status'] = $home->status;
        if (($result['platform'] === 'wordpress' || ($result['scores']['wordpress'] ?? 0) > 0.3) && !$this->restClosedByRobots($home->url)) {
            $probe = $this->fetcher->get(Url::origin($home->url) . '/wp-json/wp/v2/pages?per_page=1', 1048576);
            $open = $probe !== null && $probe->ok() && is_array(json_decode($probe->body, true));
            $result['wp_rest'] = $open;
            if ($open && $result['platform'] !== 'wordpress') {
                $result['platform'] = 'wordpress';
                $result['name'] = 'WordPress';
                $result['confidence'] = 0.9;
            }
            $result['evidence'][] = $open ? 'REST API /wp-json/ открыт' : 'REST API /wp-json/ закрыт';
        }
        return $result;
    }

    /** robots.txt of the site closes the REST API to every robot (Disallow: /wp-json/, /wp-, *?): it is not asked then. */
    private function restClosedByRobots(string $url): bool
    {
        if (($this->options['robots'] ?? true) === false) {
            return false;
        }
        $r = $this->fetcher->get(Url::origin($url) . '/robots.txt', 512000);
        if ($r === null || !$r->ok() || $r->isHtml()) {
            return false;
        }
        return !Net\Robots::parse($r->text())->allowed('/wp-json/wp/v2/pages?per_page=1');
    }

    /**
     * The extractor for a source.
     *
     * @param array{type:string,source:string} $src
     * @param string $dir folder of the job (unpacked archives)
     */
    public function extractor(array $src, string $platform = '', string $dir = ''): Extractor
    {
        $o = $this->options;
        return match ($src['type']) {
            'wxr' => new Wxr(['file' => $src['source'], 'limit' => $o['limit']]),
            'tilda_zip' => new TildaZip(['file' => $src['source'], 'dir' => $dir !== '' ? $dir : sys_get_temp_dir() . '/agecms-import-' . bin2hex(random_bytes(4)), 'limit' => $o['limit']]),
            'html_file' => new class ($src['source']) implements Extractor {
                public function __construct(private string $file)
                {
                }

                public function step(array &$state, float $deadline, callable $emit): bool
                {
                    $html = Net\Response::utf8((string)file_get_contents($this->file));
                    $page = Generic::parsePage($html, 'https://saved-page.invalid/', '');
                    if ($page !== null) {
                        $page['old_path'] = '/' . (preg_replace('~[^A-Za-z0-9_-]+~', '-', pathinfo($this->file, PATHINFO_FILENAME)) ?: 'page');
                        unset($page['menus']);
                        $emit($page);
                    }
                    $state['site'] = ['source_url' => '', 'platform' => 'generic', 'title' => (string)($page['title'] ?? ''), 'menus' => ['header' => [], 'footer' => []]];
                    $state['detect'] = ['platform' => 'generic', 'name' => 'Сохранённая страница', 'confidence' => 1.0, 'evidence' => ['файл HTML'], 'scores' => []];
                    return true;
                }
            },
            default => $platform === 'wordpress'
                ? new WordPress($this->fetcher, ['url' => $src['source'], 'limit' => $o['limit'], 'robots' => $o['robots']])
                : new Generic($this->fetcher, ['url' => $src['source'], 'limit' => $o['limit'], 'robots' => $o['robots'], 'platform' => $platform]),
        };
    }

    /**
     * Reads the whole source in one go (command line, tests).
     *
     * @param (callable(array<string,mixed>):void)|null $progress called for every page found
     * @return array{source:array{type:string,source:string},detect:array<string,mixed>,site:array<string,mixed>,pages:list<array<string,mixed>>,warnings:list<string>}
     */
    public function analyze(string $input, ?callable $progress = null, string $dir = ''): array
    {
        $src = self::source($input);
        $state = [];
        $platform = '';
        if ($src['type'] === 'url') {
            $state['detect'] = $this->detect($src['source']);
            $platform = (string)$state['detect']['platform'];
        }
        $extractor = $this->extractor($src, $platform, $dir);
        $pages = [];
        $seen = [];
        $emit = static function (array $page) use (&$pages, &$seen, $progress): void {
            $key = Url::key((string)$page['old_path']);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $pages[] = $page;
            if ($progress !== null) {
                $progress($page);
            }
        };
        while (!$extractor->step($state, microtime(true) + 3600, $emit)) {
            // Keep going: the deadline is far, a step ends only when the extractor wants a break.
        }
        $site = Neutral::site((array)($state['site'] ?? []));
        $site['local_base'] = (string)($state['local_base'] ?? $state['root'] ?? '');
        return ['source' => $src, 'detect' => (array)($state['detect'] ?? []), 'site' => $site, 'pages' => $pages, 'warnings' => array_values(array_unique((array)($state['warnings'] ?? [])))];
    }

    /**
     * The plan: new address, action and the page document (blocks of the theme) for every page, plus sections, menus
     * and redirects.
     *
     * @param list<array<string,mixed>> $pages neutral pages with "id"
     * @param array<string,mixed> $site
     * @param array<string,array<string,mixed>>|null $blocks theme blocks (theme.json), the active theme when null
     * @param (callable(string):?string)|null $taken
     * @return array{items:array<int|string,array<string,mixed>>,sections:list<array<string,mixed>>,menus:array<string,mixed>,redirects:list<array{from:string,to:string}>,warnings:list<string>,roles:array<string,string>}
     */
    public function plan(array $pages, array $site, ?array $blocks = null, ?callable $taken = null): array
    {
        $blocks ??= self::themeBlocks();
        $plan = (new Planner($this->options, $taken ?? self::takenChecker()))->plan($pages, $site);
        $source = (string)($site['source_url'] ?? '');
        $links = $plan['links'];
        $mapper = new Mapper($blocks, static fn(string $url): ?string => Planner::resolve($url, $links, $source));
        $wanted = [];
        foreach ($plan['menus'] as $items) {
            foreach ($items as $item) {
                if (($hash = strpos((string)$item['url'], '#')) !== false) {
                    $wanted[] = rawurldecode(substr((string)$item['url'], $hash + 1));
                }
            }
        }
        $mapper->wantAnchors($wanted);
        $withPublished = self::publishedAtSupported();
        $all = array_merge($pages, $plan['sections']);
        $children = [];
        foreach ($all as $p) {
            $entry = $plan['pages'][$p['id']];
            if ($p['kind'] === 'post' && $entry['action'] !== 'skip' && $entry['section'] !== '') {
                $children[$entry['section']][] = ['title' => (string)$p['title'], 'url' => '/' . $entry['slug']];
            }
        }
        $items = [];
        $anchors = [];
        $siteTitle = (string)($site['title'] ?? '');
        foreach ($all as $p) {
            $entry = $plan['pages'][$p['id']];
            $extra = [];
            if ($entry['children_of'] !== '') {
                $extra = ['children_of' => $entry['children_of'], 'children' => $children[$entry['children_of']] ?? []];
            }
            $mapped = $mapper->map($p, $extra);
            $doc = [
                'title' => mb_substr((string)$p['title'] !== '' ? (string)$p['title'] : 'Без названия', 0, 200),
                'meta_title' => mb_substr(Mapper::metaTitle((string)$p['meta_title'], (string)$p['title'], $siteTitle), 0, 200),
                'meta_description' => mb_substr((string)$p['meta_description'], 0, 500),
                'og_image' => Mapper::urlOk((string)$p['og_image']) && strlen((string)$p['og_image']) <= 500 ? (string)$p['og_image'] : '',
                'is_noindex' => $p['is_noindex'] ? 1 : 0,
                'schema_type' => $p['kind'] === 'post' ? 'Article' : ($entry['children_of'] !== '' ? 'CollectionPage' : 'WebPage'),
                'blocks' => $mapped['blocks'],
            ];
            if ($entry['slug'] !== 'index') {
                $doc['parent_slug'] = $entry['parent_slug'];
            }
            if ($withPublished && $p['kind'] === 'post' && (string)$p['published_at'] !== '') {
                $doc['published_at'] = (string)$p['published_at'];
            }
            $items[$p['id']] = $entry + ['doc' => $doc, 'losses' => $mapped['losses']];
            if ($entry['action'] !== 'skip') {
                $anchors[$entry['slug'] === 'index' ? '/' : '/' . $entry['slug']] = $mapped['anchors'] ?? [];
            }
        }
        $plan['menus'] = self::menuAnchors($plan['menus'], $anchors, $all, $plan['pages'], $plan['warnings']);
        return ['items' => $items, 'sections' => $plan['sections'], 'menus' => $plan['menus'], 'redirects' => $plan['redirects'], 'warnings' => $plan['warnings'], 'roles' => $mapper->roles()];
    }

    /**
     * Menu items that lead to an anchor of a page (/#rec769263598, /#services): to the block the anchored part of the
     * source went to; when no imported block has it, to the page with the same title, else to the page without the anchor.
     *
     * @param array{header:list<array{title:string,url:string}>,footer:list<array{title:string,url:string}>} $menus
     * @param array<string,array<string,string>> $anchors new page address => anchor => block id
     * @param list<array<string,mixed>> $pages
     * @param array<int|string,array<string,mixed>> $entries
     * @param list<string> $warnings
     * @return array{header:list<array{title:string,url:string}>,footer:list<array{title:string,url:string}>}
     */
    public static function menuAnchors(array $menus, array $anchors, array $pages, array $entries, array &$warnings): array
    {
        $byTitle = [];
        foreach ($pages as $p) {
            $e = $entries[$p['id']] ?? null;
            if ($e !== null && $e['action'] !== 'skip' && (string)$p['title'] !== '') {
                $byTitle[mb_strtolower(Neutral::line((string)$p['title']))] ??= $e['slug'] === 'index' ? '/' : '/' . $e['slug'];
            }
        }
        $moved = [];
        foreach (['header', 'footer'] as $name) {
            foreach ($menus[$name] ?? [] as $i => $item) {
                $url = (string)$item['url'];
                if (!str_starts_with($url, '/') || ($hash = strpos($url, '#')) === false) {
                    continue;
                }
                $path = substr($url, 0, $hash) ?: '/';
                $anchor = rawurldecode(substr($url, $hash + 1));
                if (!isset($anchors[$path])) {
                    continue;
                }
                if (isset($anchors[$path][$anchor])) {
                    $menus[$name][$i]['url'] = $path . '#' . $anchors[$path][$anchor];
                    continue;
                }
                $menus[$name][$i]['url'] = $byTitle[mb_strtolower(Neutral::line((string)$item['title']))] ?? $path;
                $moved[] = '«' . $item['title'] . '»';
            }
        }
        if ($moved !== []) {
            $warnings[] = 'Меню: пункты ' . implode(', ', array_unique($moved)) . ' вели на якорь страницы, которого нет среди перенесённых блоков: они ведут на страницу (с тем же названием или без якоря).';
        }
        return $menus;
    }

    /** Blocks of the active theme; on a site that is not installed, of the default theme. @return array<string,array<string,mixed>> */
    public static function themeBlocks(): array
    {
        try {
            return Theme::blocks(Theme::active());
        } catch (\Throwable) {
            $file = (defined('AGECMS_ROOT') ? AGECMS_ROOT : dirname(__DIR__, 3)) . '/themes/default/theme.json';
            $json = json_decode((string)@file_get_contents($file), true);
            return is_array($json['blocks'] ?? null) ? $json['blocks'] : [];
        }
    }

    public static function publishedAtSupported(): bool
    {
        try {
            return Compat::publishedAt();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Why an address of this site is not free, or null. Without a database only the form of the address is checked. */
    public static function takenChecker(): callable
    {
        $installed = class_exists(Database::class) && defined('AGECMS_DATA') && Database::exists();
        return static function (string $slug) use ($installed): ?string {
            if (!Planner::slugValid($slug)) {
                return 'адрес не подходит';
            }
            if (!$installed) {
                return null;
            }
            if (Pages::row($slug) !== null) {
                return 'страница';
            }
            if (ModuleRoutes::claimedBy($slug) !== null) {
                return 'адрес модуля';
            }
            if (Database::row('SELECT 1 FROM revisions WHERE page_slug = ? LIMIT 1', [$slug]) !== null && !self::rolledBackSafe($slug)) {
                return 'страница в корзине или перенесённая';
            }
            return null;
        };
    }

    private static function rolledBackSafe(string $slug): bool
    {
        try {
            return Jobs::rolledBack($slug);
        } catch (\Throwable) {
            return false;
        }
    }
}
