<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\ImportException;
use AgeCMS\Modules\Importer\Url;
use ZipArchive;

/**
 * Tilda project export (zip: page*.html, images/, files/, htaccess with the page addresses, sitemap.xml). The archive is
 * unpacked into the job folder (only pages, pictures and the address files), each page is parsed like a published one,
 * pictures that are in the archive are taken from disk ("local:images/...").
 */
final class TildaZip implements Extractor
{
    private const FAKE = 'https://tilda-export.invalid';
    private const KEEP = '~\.(html?|jpe?g|png|gif|webp|svg|xml|txt)$|(^|/)\.?htaccess$~i';
    private const MAX_BYTES = 314572800;

    /** @param array{file:string,dir:string,limit?:int} $options dir: where to unpack (the job folder) */
    public function __construct(private array $options)
    {
    }

    public static function looks(string $file): bool
    {
        if (!class_exists(ZipArchive::class)) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return false;
        }
        $found = false;
        for ($i = 0; $i < $zip->numFiles && !$found; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $found = preg_match('~(^|/)page\d+\.html$~', $name) === 1 || preg_match('~(^|/)htaccess$~', $name) === 1;
        }
        $zip->close();
        return $found;
    }

    public function step(array &$state, float $deadline, callable $emit): bool
    {
        if (($state['phase'] ?? '') === '') {
            $this->unpack($state);
        }
        $limit = max(1, (int)($this->options['limit'] ?? 200));
        while ($state['files'] !== [] && microtime(true) < $deadline) {
            if ($state['emitted'] >= $limit) {
                $state['warnings'][] = 'Достигнут лимит страниц (' . $limit . '): ещё ' . count($state['files']) . ' файлов не взято.';
                $state['files'] = [];
                break;
            }
            $file = array_shift($state['files']);
            $alias = $state['aliases'][$file] ?? '/' . $file;
            $base = $state['origin'] . ($alias === '/' ? '/' : $alias);
            $html = (string)@file_get_contents($state['root'] . '/' . $file);
            $page = Tilda::parsePage($html, $base) ?? self::withBody($html, $file, $state['root'], $base) ?? HtmlPage::parse($html, $base, Profile::for('tilda'));
            $page['old_path'] = $alias;
            $page['source_url'] = $base;
            foreach ($state['more_aliases'][$file] ?? ($alias !== '/' . $file ? ['/' . $file] : []) as $more) {
                $page['aliases'][] = $more;
            }
            $page['aliases'] = array_values(array_unique(array_filter($page['aliases'], static fn(string $a): bool => $a !== $alias)));
            if (isset($state['noindex'][$file])) {
                $page['is_noindex'] = true;
            }
            if (isset($page['menus'])) {
                foreach (['header', 'footer'] as $m) {
                    if ($state['site']['menus'][$m] === [] && $page['menus'][$m] !== []) {
                        $state['site']['menus'][$m] = $this->localizeMenu($page['menus'][$m], $state);
                    }
                }
                unset($page['menus']);
            }
            $page = $this->localize($page, $state);
            $state['emitted']++;
            $emit($page);
        }
        return $state['files'] === [];
    }

    /**
     * A page that loads its records by script from files/page123body.html (the "body only" part of an export, or a
     * page rebuilt by hand around it): the records of that file with the meta fields of the page.
     *
     * @return array<string,mixed>|null
     */
    private static function withBody(string $html, string $file, string $root, string $base): ?array
    {
        $ids = [];
        if (preg_match('~^page(\d+)\.html$~', $file, $m) === 1) {
            $ids[] = $m[1];
        }
        if (preg_match_all('~(?:tilda-blocks-page|data-tilda-page-id=")(\d+)~', $html, $mm) > 0) {
            $ids = array_merge($ids, $mm[1]);
        }
        foreach (array_unique($ids) as $id) {
            $body = $root . '/files/page' . $id . 'body.html';
            if (!is_file($body)) {
                continue;
            }
            $head = preg_match('~^(.*?)<body\b~is', $html, $h) === 1 ? $h[1] : '<!DOCTYPE html><html><head></head>';
            $page = Tilda::parsePage($head . '<body>' . (string)file_get_contents($body) . '</body></html>', $base);
            if ($page !== null) {
                return $page;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $state */
    private function unpack(array &$state): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ImportException('Для архива Tilda нужно расширение PHP zip.');
        }
        $zip = new ZipArchive();
        if ($zip->open($this->options['file']) !== true) {
            throw new ImportException('Архив не открылся: файл повреждён или это не zip.');
        }
        $dir = rtrim($this->options['dir'], '/') . '/tilda';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ImportException('Не получилось создать папку для архива: ' . $dir);
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/') || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '__MACOSX') || preg_match(self::KEEP, $name) !== 1) {
                continue;
            }
            $total += (int)($stat['size'] ?? 0);
            if ($total > self::MAX_BYTES) {
                throw new ImportException('Архив больше 300 МБ в распакованном виде: разбейте проект или уберите лишние файлы.');
            }
            $clean = preg_replace('~[^A-Za-z0-9._/-]~', '_', $name) ?? '';
            if ($clean === '' || str_contains($clean, '..')) {
                continue;
            }
            $target = $dir . '/' . $clean;
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0700, true);
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $out = fopen($target, 'wb');
            if ($out !== false) {
                stream_copy_to_stream($stream, $out, 52428800);
                fclose($out);
            }
            fclose($stream);
        }
        $zip->close();

        $root = self::projectRoot($dir);
        $project = self::project($root);
        if ($project['files'] === []) {
            throw new ImportException('В архиве нет страниц Tilda (файлов page*.html).');
        }
        $origin = $project['origin'] ?? self::FAKE;
        $state = [
            'phase' => 'pages',
            'root' => $root,
            'files' => $project['files'],
            'aliases' => $project['aliases'],
            'more_aliases' => $project['more_aliases'],
            'noindex' => $project['noindex'],
            'origin' => $origin,
            'emitted' => 0,
            'detect' => ['platform' => 'tilda', 'name' => 'Tilda', 'confidence' => 1.0, 'evidence' => ['архив экспорта проекта Tilda (' . count($project['files']) . ' страниц)'], 'scores' => ['tilda' => 1.0]],
            'site' => ['source_url' => $origin === self::FAKE ? '' : $origin . '/', 'platform' => 'tilda', 'title' => '', 'menus' => ['header' => [], 'footer' => []]],
            'warnings' => array_merge($project['warnings'], $origin === self::FAKE ? ['В архиве нет sitemap.xml с адресом сайта: ссылки на внешний домен проекта не распознаются как внутренние.'] : []),
            'local_base' => $root,
        ];
    }

    /**
     * The project folder: the unpacked folder itself when it has htaccess or page*.html at the top, else the first
     * subfolder that has them (an archive of the folder). The files/ folder with page*body.html is never the root.
     */
    public static function projectRoot(string $dir): string
    {
        $marks = static fn(string $d): bool => is_file($d . '/htaccess') || is_file($d . '/.htaccess') || preg_grep('~/page\d+\.html$~', glob($d . '/page*.html') ?: []) !== [];
        if ($marks($dir)) {
            return $dir;
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (basename($sub) !== 'files' && $marks($sub)) {
                return $sub;
            }
        }
        return $dir;
    }

    /**
     * Pages of an unpacked project: which files to take, in what order, at what old addresses.
     *
     * - Every page*.html and other *.html at the top (a page added by hand), not 404.html, not the error page of
     *   htaccess, not files/page*body.html (bodies of the same pages).
     * - Two files of one Tilda page (index.html is a copy of the home page) give one page; the other name is an alias.
     * - Addresses come from htaccess (RewriteRule ^about$ page123.html, DirectoryIndex), all of them are kept.
     * - A page whose records are only the shared header or footer of other pages (Tilda "Footer" page) is left out.
     * - A page closed in robots.txt (Disallow: /page123.html) is marked noindex.
     *
     * @return array{files:list<string>,aliases:array<string,string>,more_aliases:array<string,list<string>>,noindex:array<string,bool>,origin:?string,warnings:list<string>}
     */
    public static function project(string $root): array
    {
        $warnings = [];
        $home = '';
        $rules = [];
        $errorPages = ['404.html' => true];
        foreach ([$root . '/htaccess', $root . '/.htaccess'] as $ht) {
            if (!is_file($ht)) {
                continue;
            }
            foreach (file($ht, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('~^\s*RewriteRule\s+\^([^\s$]*)\$?\s+([A-Za-z0-9_.-]+\.html)~i', $line, $m) === 1) {
                    $path = '/' . trim(stripslashes($m[1]), '/');
                    if ($path === '/') {
                        $home = $m[2];
                    } elseif (!in_array($path, $rules[$m[2]] ?? [], true)) {
                        $rules[$m[2]][] = $path;
                    }
                } elseif (preg_match('~^\s*DirectoryIndex\s+([A-Za-z0-9_.-]+\.html)~i', $line, $m) === 1) {
                    $home = $m[1];
                } elseif (preg_match('~^\s*ErrorDocument\s+40[34]\s+/?([A-Za-z0-9_.-]+\.html)~i', $line, $m) === 1) {
                    $errorPages[$m[1]] = true;
                }
            }
        }
        $candidates = [];
        foreach (glob($root . '/*.html') ?: [] as $f) {
            $name = basename($f);
            if (isset($errorPages[$name]) || preg_match('~body\.html$~', $name) === 1) {
                continue;
            }
            $candidates[$name] = (string)@file_get_contents($f, false, null, 0, 4194304);
        }
        if ($home !== '' && !isset($candidates[$home])) {
            $home = '';
        }
        if ($home === '' && isset($candidates['index.html'])) {
            $home = 'index.html';
        }
        // One page per Tilda page id: page123.html wins over a copy (index.html, a renamed file).
        $byId = [];
        $copies = [];
        $idOf = [];
        foreach ($candidates as $name => $html) {
            if (preg_match('~data-tilda-page-id="(\d+)"~', $html, $m) !== 1) {
                continue;
            }
            $idOf[$name] = $m[1];
            $main = $byId[$m[1]] ?? null;
            if ($main === null) {
                $byId[$m[1]] = $name;
            } elseif ($name === 'page' . $m[1] . '.html' || $name === $home) {
                $copies[$main] = $name;
                $byId[$m[1]] = $name;
            } else {
                $copies[$name] = $main;
            }
        }
        foreach ($copies as $copy => $main) {
            while (isset($copies[$main])) {
                $main = $copies[$main];
            }
            $copies[$copy] = $main;
        }
        // Records of the shared header and footer (the same record ids are in every page).
        $shared = [];
        foreach ($candidates as $html) {
            if (preg_match_all('~<(header|footer)\s[^>]*id="t-(?:header|footer)"[^>]*>(.*?)</\1>~s', $html, $mm) > 0) {
                foreach ($mm[2] as $part) {
                    preg_match_all('~\sid="rec(\d+)"~', $part, $ids);
                    foreach ($ids[1] as $id) {
                        $shared[$id] = true;
                    }
                }
            }
        }
        $robots = is_file($root . '/robots.txt') ? (string)file_get_contents($root . '/robots.txt') : '';
        $files = [];
        $aliases = [];
        $more = [];
        $noindex = [];
        foreach ($candidates as $name => $html) {
            if (isset($copies[$name])) {
                continue;
            }
            $own = preg_replace('~<(header|footer)\s[^>]*id="t-(?:header|footer)"[^>]*>.*?</\1>~s', '', $html) ?? $html;
            preg_match_all('~<div[^>]*\sid="rec(\d+)"~', $own, $ids);
            if ($ids[1] !== [] && $name !== $home && array_diff($ids[1], array_keys($shared)) === []) {
                $warnings[] = 'Страница ' . $name . ' не переносится: это общая шапка или подвал других страниц.';
                continue;
            }
            $names = array_merge([$name], array_keys($copies, $name, true));
            $paths = [];
            foreach ($names as $n) {
                $paths = array_merge($paths, $rules[$n] ?? [], isset($idOf[$n]) && $n !== 'page' . $idOf[$n] . '.html' ? $rules['page' . $idOf[$n] . '.html'] ?? [] : []);
            }
            $main = in_array($home, $names, true) ? '/' : ($paths[0] ?? '/' . $name);
            foreach ($names as $n) {
                $aliases[$n] = $main;
                $paths[] = '/' . $n;
            }
            $more[$name] = array_values(array_diff(array_unique($paths), [$main]));
            foreach (array_merge([$main], $more[$name]) as $p) {
                if ($p !== '/' && preg_match('~^\s*Disallow:\s*' . preg_quote($p, '~') . '/?\s*$~mi', $robots) === 1) {
                    $noindex[$name] = true;
                }
            }
            $files[] = $name;
        }
        // The home page first: its menu is the menu of the site.
        usort($files, static fn(string $a, string $b): int => [($aliases[$a] ?? '') === '/' ? 0 : 1, $a] <=> [($aliases[$b] ?? '') === '/' ? 0 : 1, $b]);
        $origin = null;
        if (is_file($root . '/sitemap.xml') && preg_match('~<loc>\s*(https?://[^/<\s]+)~i', (string)file_get_contents($root . '/sitemap.xml'), $m) === 1) {
            $origin = $m[1];
        } elseif ($home !== '' && preg_match('~<(?:meta\s+property="og:url"\s+content|link\s+rel="canonical"\s+href)="(https?://[^/"\s]+)~i', $candidates[$home], $m) === 1) {
            $origin = $m[1];
        }
        return ['files' => $files, 'aliases' => $aliases, 'more_aliases' => $more, 'noindex' => $noindex, 'origin' => $origin, 'warnings' => $warnings];
    }

    /**
     * Pictures and links of the archive: a picture that is in the folder becomes local:<path>, a link to pageN.html
     * becomes the address of that page.
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function localize(array $page, array $state): array
    {
        $fix = function (string $url) use ($state): string {
            if ($url === '' || !Url::sameSite($url, $state['origin'] . '/')) {
                return $url;
            }
            $path = ltrim((string)parse_url($url, PHP_URL_PATH), '/');
            if ($path !== '' && is_file($state['root'] . '/' . $path) && !str_ends_with($path, '.html')) {
                return 'local:' . $path;
            }
            if (isset($state['aliases'][$path])) {
                return $state['origin'] . $state['aliases'][$path];
            }
            return $url;
        };
        $fixLink = static function (string $url) use ($fix, $state): string {
            $url = $fix($url);
            // Without the real domain of the project, a link inside the archive is a path of the site.
            if ($state['origin'] === self::FAKE && str_starts_with($url, self::FAKE)) {
                return substr($url, strlen(self::FAKE)) ?: '/';
            }
            return $url;
        };
        $page['og_image'] = $fix((string)$page['og_image']);
        foreach ($page['elements'] as &$el) {
            foreach (['src', 'image'] as $k) {
                if (isset($el[$k]) && is_string($el[$k])) {
                    $el[$k] = $fix($el[$k]);
                }
            }
            foreach (['url', 'cta_url'] as $k) {
                if (isset($el[$k]) && is_string($el[$k])) {
                    $el[$k] = $fixLink($el[$k]);
                }
            }
            if (isset($el['images'])) {
                foreach ($el['images'] as &$img) {
                    $img['src'] = $fix($img['src']);
                }
                unset($img);
            }
            foreach (['html'] as $k) {
                if (isset($el[$k])) {
                    $el[$k] = preg_replace_callback('~(src|href)="([^"]+)"~', static fn(array $m): string => $m[1] . '="' . htmlspecialchars(($m[1] === 'src' ? $fix : $fixLink)(html_entity_decode($m[2], ENT_QUOTES)), ENT_QUOTES) . '"', (string)$el[$k]) ?? $el[$k];
                }
            }
            if (isset($el['items']) && is_array($el['items'])) {
                foreach ($el['items'] as &$item) {
                    if (is_string($item)) {
                        $item = preg_replace_callback('~href="([^"]+)"~', static fn(array $m): string => 'href="' . htmlspecialchars($fixLink(html_entity_decode($m[1], ENT_QUOTES)), ENT_QUOTES) . '"', $item) ?? $item;
                    }
                }
                unset($item);
            }
        }
        unset($el);
        return $page;
    }

    /** @param list<array{title:string,url:string}> $menu @param array<string,mixed> $state @return list<array{title:string,url:string}> */
    private function localizeMenu(array $menu, array $state): array
    {
        foreach ($menu as &$item) {
            $path = ltrim((string)parse_url($item['url'], PHP_URL_PATH), '/');
            if (Url::sameSite($item['url'], $state['origin'] . '/') && isset($state['aliases'][$path])) {
                $item['url'] = $state['origin'] . $state['aliases'][$path];
            }
            if ($state['origin'] === self::FAKE && str_starts_with($item['url'], self::FAKE)) {
                $item['url'] = substr($item['url'], strlen(self::FAKE)) ?: '/';
            }
        }
        unset($item);
        return $menu;
    }
}
