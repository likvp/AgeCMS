<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\Slug;

/**
 * New addresses for imported pages, their parents, blog sections, conflicts with what the site already has, 301
 * redirects from every old address, and the map old address => new address for links and menus.
 *
 * - The slug comes from the old path when it is a good address (nesting kept as "a/b"), otherwise from the address
 *   the CMS suggested (WordPress post_name) or the transliterated title.
 * - Posts live under a section: the one they already had at the source (/news/x stays news/x), or "blog". The section
 *   page is the imported page at that address, or a new one; it lists its posts (child_pages block).
 * - A taken address (a page, a module route, a page in the trash) gets -2, -3... or the page is skipped, as chosen.
 *   The home page goes to "index"; when the site has one, it is replaced only on request.
 */
final class Planner
{
    private const SECTION_TITLES = ['blog' => 'Блог', 'news' => 'Новости', 'novosti' => 'Новости', 'stati' => 'Статьи', 'articles' => 'Статьи', 'press' => 'Пресс-центр', 'events' => 'События', 'sobytiya' => 'События', 'tpost' => 'Блог', 'post' => 'Блог', 'single-post' => 'Блог', 'posts' => 'Блог'];

    /** @var callable(string):?string */
    private $taken;

    /**
     * @param array{conflict?:string,replace_home?:bool,section?:string,menus?:bool} $options
     * @param (callable(string):?string)|null $taken why an address is not free (null when free)
     */
    public function __construct(private array $options = [], ?callable $taken = null)
    {
        $this->taken = $taken ?? static fn(string $slug): ?string => null;
    }

    public static function slugValid(string $slug): bool
    {
        if (class_exists('AgeCMS\\Pages')) {
            return \AgeCMS\Pages::slugValid($slug);
        }
        return $slug !== '' && strlen($slug) <= 120 && preg_match('~^[a-z0-9]([a-z0-9_-]*[a-z0-9])?(/[a-z0-9]([a-z0-9_-]*[a-z0-9])?)*$~', $slug) === 1;
    }

    /** "Ремонт квартир" to "remont-kvartir" with the core's transliteration. */
    public static function translit(string $s, int $max = 60): string
    {
        if (class_exists('AgeCMS\\Slug')) {
            return Slug::fromTitle($s, $max);
        }
        $s = mb_strtolower($s);
        $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?? '';
        return trim(substr($s, 0, $max), '-');
    }

    /** Slug from an old path, or '' when the path cannot give one (a query address, a reserved word). */
    public static function slugFromPath(string $oldPath): string
    {
        if (str_contains($oldPath, '?')) {
            return '';
        }
        $path = rawurldecode($oldPath);
        $path = preg_replace('~^/index\.php(/|$)~i', '/', $path) ?? $path;
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $seg) {
            if ($seg === '') {
                continue;
            }
            $seg = preg_replace('~\.(html?|php\d?|aspx?|shtml|jsp|cfm)$~i', '', $seg) ?? $seg;
            if (preg_match('~^(index|default)$~i', $seg) === 1 && $segments !== []) {
                continue;
            }
            $clean = strtolower($seg);
            if (preg_match('~^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$~', $clean) !== 1) {
                $clean = self::translit($seg, 80);
            }
            if ($clean === '') {
                return '';
            }
            $segments[] = $clean;
        }
        $slug = implode('/', $segments);
        if ($slug === '' || strlen($slug) > 120) {
            return '';
        }
        return self::slugValid($slug) ? $slug : '';
    }

    /**
     * @param list<array<string,mixed>> $pages neutral pages; each has "id" (the item id)
     * @param array<string,mixed> $site
     * @return array{pages:array<int|string,array<string,mixed>>,sections:list<array<string,mixed>>,links:array<string,string>,menus:array{header:list<array{title:string,url:string}>,footer:list<array{title:string,url:string}>},redirects:list<array{from:string,to:string}>,warnings:list<string>}
     */
    public function plan(array $pages, array $site): array
    {
        $warnings = [];
        $base = [];
        $kinds = [];
        $sections = [];
        // 1. Base slug of every page.
        foreach ($pages as $p) {
            $id = $p['id'];
            $kinds[$id] = $p['kind'];
            if (self::isHome($p)) {
                $base[$id] = 'index';
                continue;
            }
            $slug = self::slugFromPath((string)$p['old_path']);
            if ($slug === '' || $slug === 'index' || !self::slugValid($slug)) {
                $slug = self::translit((string)$p['suggested_slug']);
                if ($slug === '' || !self::slugValid($slug)) {
                    $slug = self::translit((string)$p['title']);
                }
                if ($slug === '' || !self::slugValid($slug)) {
                    $slug = 'page-' . (is_int($id) ? $id : substr(md5((string)$id), 0, 6));
                }
                if (!self::slugValid($slug)) {
                    $slug .= '-page';
                }
            }
            $base[$id] = $slug;
        }
        // Old addresses of the found pages (with their aliases) => page id.
        $pathToId = [];
        foreach ($pages as $p) {
            if (($p['old_path'] ?? '') !== '') {
                $pathToId[Url::key((string)$p['old_path'])] ??= $p['id'];
                foreach ((array)$p['aliases'] as $a) {
                    $pathToId[Url::key((string)$a)] ??= $p['id'];
                }
            }
        }
        // Copies of one Tilda page under several addresses (/, /glavnaya, /page123.html): the first one (the home page
        // when it is among them) is imported, the others only give their old addresses as redirects to it.
        $dupOf = [];
        $byTildaId = [];
        $homeFirst = $pages;
        usort($homeFirst, static fn(array $a, array $b): int => (self::isHome($a) ? 0 : 1) <=> (self::isHome($b) ? 0 : 1));
        foreach ($homeFirst as $p) {
            $tid = (string)($p['tilda_page_id'] ?? '');
            if ($tid === '' || !empty($p['skip'])) {
                continue;
            }
            if (isset($byTildaId[$tid])) {
                $dupOf[$p['id']] = $byTildaId[$tid];
            } else {
                $byTildaId[$tid] = $p['id'];
            }
        }
        // 2. Posts go under their section: the folder of the address (/about/news/x is in about/news), without date
        // folders (/news/2020/05/x is in news). A Tilda Feeds post (/blog/tpost/<uid>-slug) is in the folder before
        // "tpost" or in its feed page named by the source, and loses "tpost" and the uid in its new address.
        $sectionOf = [];
        $forced = trim((string)($this->options['section'] ?? ''), '/');
        foreach ($pages as $p) {
            $id = $p['id'];
            if ($p['kind'] !== 'post' || $base[$id] === 'index' || !empty($p['skip']) || isset($dupOf[$id])) {
                continue;
            }
            $segments = explode('/', $base[$id]);
            $last = (string)array_pop($segments);
            $tpost = array_search('tpost', $segments, true);
            if ($tpost !== false) {
                $segments = array_slice($segments, 0, (int)$tpost);
                if (preg_match('~^(?=[a-z]*\d)[a-z0-9]{8,12}-([a-z0-9].*)$~', $last, $m) === 1) {
                    $last = $m[1];
                }
            }
            $dated = false;
            while ($segments !== [] && preg_match('~^\d+$~', (string)end($segments)) === 1) {
                array_pop($segments);
                $dated = true;
            }
            $section = implode('/', $segments);
            $slug = $section . '/' . $last;
            if ($forced !== '' && self::slugValid($forced)) {
                $section = $forced;
                $slug = $forced . '/' . $last;
            } elseif ($tpost !== false && ($pid = $pathToId[Url::key((string)($p['parent_old_path'] ?? ''))] ?? null) !== null
                && $pid !== $id && ($kinds[$pid] ?? '') === 'page' && $base[$pid] !== 'index') {
                // The feed page of a post in the root (/tpost/x) is named by the source (/company): its section.
                $section = $base[$pid];
                $slug = $section . '/' . $last;
            } elseif ($section === '') {
                $section = 'blog';
                $slug = 'blog/' . $last;
            } elseif ($tpost === false && !$dated) {
                $slug = $base[$id];
            }
            if (preg_match('~^\d+$~', $last) === 1 && $p['title'] !== '') {
                $slug = $section . '/' . (self::translit((string)$p['title']) ?: $last);
            }
            if (!self::slugValid($slug)) {
                $slug = $base[$id];
            }
            $base[$id] = $slug;
            $sectionOf[$id] = $section;
            $sections[$section] = true;
        }
        // 3. Section pages: an imported page at the section address, or a new one.
        $synthetic = [];
        $sectionPage = [];
        foreach (array_keys($sections) as $section) {
            $found = null;
            foreach ($pages as $p) {
                if ($base[$p['id']] === $section && empty($p['skip']) && !isset($dupOf[$p['id']])) {
                    $found = $p['id'];
                    break;
                }
            }
            if ($found === null) {
                $sid = '#section:' . $section;
                $name = (string)substr($section, (int)strrpos('/' . $section, '/'));
                $synthetic[] = Neutral::page([
                    'id' => $sid,
                    'old_path' => '',
                    'title' => self::SECTION_TITLES[$name] ?? mb_convert_case(str_replace(['-', '_'], ' ', $name), MB_CASE_TITLE),
                    'kind' => 'page',
                    'elements' => [],
                    'section' => true,
                ]);
                $base[$sid] = $section;
                $kinds[$sid] = 'page';
                $found = $sid;
            }
            $sectionPage[$section] = $found;
        }
        $all = array_merge($pages, $synthetic);
        $byId = [];
        foreach ($all as $p) {
            $byId[$p['id']] = $p;
        }

        // 4. Final addresses top-down: conflicts, then children follow a parent that moved.
        $order = array_keys($base);
        usort($order, static fn($a, $b): int => [$base[$a] === 'index' ? 0 : 1, substr_count($base[$a], '/'), $base[$a]] <=> [$base[$b] === 'index' ? 0 : 1, substr_count($base[$b], '/'), $base[$b]]);
        $final = [];
        $moved = [];
        $used = [];
        $plan = [];
        $conflict = ($this->options['conflict'] ?? 'suffix') === 'skip' ? 'skip' : 'suffix';
        foreach ($order as $id) {
            $slug = $base[$id];
            foreach ($moved as $from => $to) {
                if (str_starts_with($slug, $from . '/')) {
                    $slug = $to . substr($slug, strlen($from));
                    break;
                }
            }
            $entry = ['action' => 'create', 'slug' => $slug, 'parent_slug' => '', 'reason' => '', 'children_of' => '', 'section' => $sectionOf[$id] ?? ''];
            if (!empty($byId[$id]['skip']) || isset($dupOf[$id])) {
                // Marked by the extractor (a shop product, an empty service page) or a copy of another page.
                $entry['action'] = 'skip';
                $entry['reason'] = isset($dupOf[$id])
                    ? 'копия страницы ' . ((string)$byId[$dupOf[$id]]['old_path'] ?: '/') . ' (та же страница Tilda): её старые адреса ведут на перенесённую'
                    : (string)$byId[$id]['skip'];
                $final[$id] = $slug;
                $plan[$id] = $entry;
                continue;
            }
            if ($slug === 'index') {
                $why = ($this->taken)('index');
                if (isset($used['index'])) {
                    $entry['action'] = 'skip';
                    $entry['reason'] = 'вторая главная страница';
                } elseif ($why !== null) {
                    $entry['action'] = !empty($this->options['replace_home']) ? 'update_home' : 'skip';
                    $entry['reason'] = $entry['action'] === 'skip' ? 'на сайте уже есть главная: её содержимое не заменяется (можно включить «Заменить главную»)' : 'главная страница будет заменена';
                }
            } else {
                $why = isset($used[$slug]) ? 'адрес уже занят другой страницей импорта' : ($this->taken)($slug);
                if ($why !== null) {
                    if ($conflict === 'skip') {
                        $entry['action'] = 'skip';
                        $entry['reason'] = 'адрес /' . $slug . ' занят: ' . $why;
                    } else {
                        $n = 2;
                        $candidate = $slug . '-' . $n;
                        while ((isset($used[$candidate]) || ($this->taken)($candidate) !== null || !self::slugValid($candidate)) && $n < 200) {
                            $candidate = $slug . '-' . (++$n);
                        }
                        $entry['reason'] = 'адрес /' . $slug . ' занят (' . $why . '), выбран /' . $candidate;
                        $moved[$slug] = $candidate;
                        $slug = $candidate;
                        $entry['slug'] = $slug;
                    }
                }
            }
            if ($entry['action'] !== 'skip') {
                $used[$slug] = true;
            }
            $final[$id] = $slug;
            $plan[$id] = $entry;
        }
        // 5. Parents: a post is under its section page; a page under the address one level up when it is imported or
        // exists, else under the parent named by the source.
        foreach ($plan as $id => &$entry) {
            $slug = $entry['slug'];
            if ($slug === 'index' || $entry['action'] === 'skip') {
                continue;
            }
            if (isset($sectionOf[$id])) {
                $sid = $sectionPage[$sectionOf[$id]] ?? null;
                if ($sid !== null && $sid !== $id && isset($plan[$sid]) && $plan[$sid]['action'] !== 'skip' && $final[$sid] !== 'index') {
                    $entry['parent_slug'] = $final[$sid];
                    continue;
                }
            }
            if (str_contains($slug, '/')) {
                $up = substr($slug, 0, (int)strrpos($slug, '/'));
                if (isset($used[$up]) || ($this->taken)($up) === 'страница') {
                    $entry['parent_slug'] = $up;
                }
                continue;
            }
            $parentPath = (string)($byId[$id]['parent_old_path'] ?? '');
            $pid = $parentPath === '' ? null : ($pathToId[Url::key($parentPath)] ?? null);
            if ($pid !== null && $pid !== $id && isset($plan[$pid]) && $plan[$pid]['action'] !== 'skip' && $final[$pid] !== 'index') {
                $entry['parent_slug'] = $final[$pid];
            }
        }
        unset($entry);
        foreach ($sectionPage as $section => $sid) {
            if (isset($plan[$sid]) && $plan[$sid]['action'] !== 'skip') {
                $plan[$sid]['children_of'] = $final[$sid];
            }
        }

        // 6. Links: every old address of a page that will exist => its new address.
        $links = [];
        foreach ($all as $p) {
            $id = $p['id'];
            if (($p['old_path'] ?? '') === '') {
                continue;
            }
            if (!empty($p['skip'])) {
                continue;
            }
            $to = $dupOf[$id] ?? $id;
            $target = $final[$to] === 'index' ? '/' : '/' . $final[$to];
            if ($plan[$to]['action'] === 'skip' && $final[$to] !== 'index' && ($this->taken)($final[$to]) === null) {
                continue;
            }
            foreach (array_merge([(string)$p['old_path']], (array)$p['aliases']) as $old) {
                $links[Url::key($old)] ??= $target;
            }
        }
        // A new section answers links to the listing of the old site at the same address (/blog/, /news/).
        foreach ($synthetic as $s) {
            if ($plan[$s['id']]['action'] !== 'skip') {
                $links[Url::key('/' . $base[$s['id']])] ??= '/' . $final[$s['id']];
            }
        }

        // 7. Redirects from every old address that differs from the new one.
        $redirects = [];
        $seen = [];
        foreach ($all as $p) {
            $id = $p['id'];
            $main = $dupOf[$id] ?? $id;
            if (($p['old_path'] ?? '') === '' || $plan[$main]['action'] === 'skip' && !($final[$main] === 'index' && $main !== $id) || $main === $id && $plan[$id]['action'] === 'skip') {
                $plan[$id]['redirects'] = [];
                continue;
            }
            $to = $final[$main] === 'index' ? '/' : '/' . $final[$main];
            $list = [];
            foreach (array_merge([(string)$p['old_path']], (array)$p['aliases']) as $old) {
                $key = Url::key($old);
                if ($old === '' || $key === '/' || $key === Url::key($to) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $list[] = ['from' => $old, 'to' => $to];
            }
            $plan[$id]['redirects'] = $list;
            $redirects = array_merge($redirects, $list);
        }

        return [
            'pages' => $plan,
            'sections' => $synthetic,
            'links' => $links,
            'menus' => ($this->options['menus'] ?? true) === false ? ['header' => [], 'footer' => []] : $this->menus((array)($site['menus'] ?? []), $links, (string)($site['source_url'] ?? ''), $warnings),
            'redirects' => $redirects,
            'warnings' => $warnings,
        ];
    }

    /** @param array<string,mixed> $p */
    public static function isHome(array $p): bool
    {
        if (Url::key((string)$p['old_path']) === '/') {
            return true;
        }
        foreach ((array)$p['aliases'] as $a) {
            if (Url::key((string)$a) === '/') {
                return true;
            }
        }
        return false;
    }

    /**
     * Menu items with new addresses. Links to pages that are not imported are dropped (they would lead nowhere).
     *
     * @param array<string,mixed> $menus
     * @param array<string,string> $links
     * @param list<string> $warnings
     * @return array{header:list<array{title:string,url:string}>,footer:list<array{title:string,url:string}>}
     */
    private function menus(array $menus, array $links, string $source, array &$warnings): array
    {
        $out = ['header' => [], 'footer' => []];
        foreach (['header', 'footer'] as $name) {
            $dropped = [];
            foreach ((array)($menus[$name] ?? []) as $item) {
                $title = mb_substr(Neutral::line((string)($item['title'] ?? '')), 0, 60);
                $url = (string)($item['url'] ?? '');
                if ($title === '' || $url === '') {
                    continue;
                }
                $new = self::resolve($url, $links, $source);
                if ($new === null) {
                    if (preg_match('~^https?://~i', $url) === 1 && ($source === '' || !Url::sameSite($url, $source))) {
                        $new = $url;
                    } elseif (str_contains($url, '#') && Url::key(Url::oldPath($url)) === '/') {
                        $new = '/' . substr($url, (int)strpos($url, '#'));
                    } else {
                        $dropped[] = $title;
                        continue;
                    }
                }
                $out[$name][] = ['title' => $title, 'url' => $new];
                if (count($out[$name]) >= 30) {
                    break;
                }
            }
            if ($dropped !== []) {
                $warnings[] = 'Меню ' . ($name === 'header' ? 'шапки' : 'подвала') . ': без пунктов ' . implode(', ', array_map(static fn(string $t): string => '«' . $t . '»', $dropped)) . ' (их страницы не переносятся).';
            }
        }
        return $out;
    }

    /**
     * New address for a link of the old site, with its #anchor; null when it is not a link to an imported page.
     *
     * @param array<string,string> $links
     */
    public static function resolve(string $url, array $links, string $source): ?string
    {
        if ($url === '' || preg_match('~^(mailto|tel|javascript|data):~i', $url) === 1) {
            return null;
        }
        $isLocal = str_starts_with($url, '/') && !str_starts_with($url, '//');
        if (!$isLocal && ($source === '' || !Url::sameSite($url, $source))) {
            return null;
        }
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $key = Url::key(Url::oldPath($isLocal ? 'http://x' . $url : $url));
        if (!isset($links[$key])) {
            return null;
        }
        return $links[$key] . $fragment;
    }
}
