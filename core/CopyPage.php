<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * A page that is an exact copy of a page of another site (made by the importer, mode "exact copy").
 *
 * The document has one block of the system type "site_copy": the markup of the original page (head, body, the attributes of
 * <html> and <body>), the folder of its files and the visible text for search. The page keeps everything a page of this site
 * has: address, SEO fields, publication, history, redirects, sitemap. It is drawn without the theme: the original markup is the page.
 *
 * Security. The markup and the scripts of the original are foreign code. They get into the site only through the importer
 * (permission system.manage) or by editing the page in the admin, and they run under their own Content-Security-Policy
 * (csp()): own files and inline code are allowed, but no script, style, font or frame from another host, no
 * form sent elsewhere, no plugins. Every other page of the site keeps the strict policy of security-headers.php. A page of this
 * kind is never put into the shadow cache (assets/cache): the web server would send it with the strict policy of the site. It is
 * answered by PHP with the header X-AgeCMS-Policy: copy, which .htaccess and nginx.vhost.example turn into the policy of copies.
 */
final class CopyPage
{
    public const BLOCK_ID = 'copy';
    public const POLICY_HEADER = 'X-AgeCMS-Policy';
    /** The part of the address where files of copies live (inside the uploads folder, so updates and backups know it). */
    public const FILES = 'copy';

    /** Hosts of embeds that may be shown in frames on a copied page (video, maps). Must match tools/capture/lib/trackers.mjs. */
    public const FRAME_SRC = "'self' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://rutube.ru/play/embed/ https://vk.com/video_ext.php https://vk.ru/video_ext.php https://vkvideo.ru/video_ext.php https://yandex.ru/map-widget/ https://yandex.com/map-widget/ https://www.google.com/maps/embed https://www.openstreetmap.org/export/embed.html https://widgets.2gis.com/widget https://dzen.ru/embed/ https://mc.yandex.ru";

    /** @return array<string,mixed> the schema of the system block (same shape as a block of theme.json) */
    public static function schema(): array
    {
        $code = static fn(int $max, string $label): array => ['type' => 'text', 'max' => $max, 'label' => $label];
        return [
            'title' => 'Копия страницы',
            'system' => true,
            'fields' => [
                'copy_html_attrs' => $code(4000, 'Атрибуты тега html'),
                'copy_body_attrs' => $code(4000, 'Атрибуты тега body'),
                'copy_head' => $code(3000000, 'Содержимое head (стили, мета-теги)'),
                'copy_body' => $code(8000000, 'Содержимое body (разметка страницы)'),
                'copy_m_html_attrs' => $code(4000, 'Атрибуты тега html для телефона'),
                'copy_m_body_attrs' => $code(4000, 'Атрибуты тега body для телефона'),
                'copy_m_head' => $code(3000000, 'Содержимое head для телефона'),
                'copy_m_body' => $code(8000000, 'Содержимое body для телефона'),
                'copy_text' => $code(300000, 'Текст страницы для поиска'),
                'copy_source' => ['type' => 'string', 'max' => 500, 'label' => 'Адрес источника'],
                'copy_mode' => ['type' => 'enum', 'values' => ['source', 'dom'], 'label' => 'Как получена страница', 'labels' => ['source' => 'исходный код', 'dom' => 'итоговая страница из браузера']],
                'copy_key' => ['type' => 'string', 'max' => 40, 'label' => 'Папка файлов копии'],
                'copy_eval' => ['type' => 'bool', 'label' => 'Разрешить скриптам страницы строить код на лету'],
            ],
        ];
    }

    /** @param array<string,mixed> $doc */
    public static function is(array $doc): bool
    {
        $b = $doc['blocks'] ?? [];
        return is_array($b) && count($b) === 1 && ($b[0]['type'] ?? '') === Theme::COPY_BLOCK;
    }

    /** @param array<string,mixed> $doc @return array<string,mixed> */
    public static function data(array $doc): array
    {
        return self::is($doc) ? (array)$doc['blocks'][0]['data'] : [];
    }

    /** Whether the page with this slug is a copy (without rendering it). */
    public static function isSlug(string $slug): bool
    {
        $row = Pages::row($slug);
        return $row !== null && str_contains((string)$row['blocks_json'], '"type":"' . Theme::COPY_BLOCK . '"') && self::is(Pages::toDoc($row));
    }

    public static function keyValid(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9]{0,39}$/', $key) === 1;
    }

    /** Folder of the files of copies. */
    public static function root(): string
    {
        return Media::dir() . '/' . self::FILES;
    }

    public static function dir(string $key): string
    {
        return self::root() . '/' . $key;
    }

    /** Address prefix of the files of one copy, with the slash at the end. */
    public static function url(string $key): string
    {
        return '/assets/uploads/' . self::FILES . '/' . $key . '/';
    }

    /** Removes the files of one copy. @return int number of files removed */
    public static function removeFiles(string $key): int
    {
        if (!self::keyValid($key) || !is_dir(self::dir($key))) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::dir($key), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
                $n++;
            }
        }
        @rmdir(self::dir($key));
        return $n;
    }

    /** Keys of the copies that have files. @return list<string> */
    public static function keys(): array
    {
        $out = [];
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (self::keyValid(basename($d))) {
                $out[] = basename($d);
            }
        }
        return $out;
    }

    /** Whether a page of the site (not in the trash) uses the files of the key. */
    public static function keyLive(string $key): bool
    {
        return Database::value('SELECT 1 FROM pages WHERE blocks_json LIKE ? LIMIT 1', ['%"copy_key":"' . $key . '"%']) !== null;
    }

    /** Whether a live page or a page in the trash (it can come back) uses the files of the key. */
    public static function keyInUse(string $key): bool
    {
        $needle = '%"copy_key":"' . $key . '"%';
        if (self::keyLive($key)) {
            return true;
        }
        foreach (Pages::deleted() as $row) {
            $r = Database::row('SELECT 1 FROM revisions WHERE page_slug = ? AND (snapshot_json LIKE ? OR patch_json LIKE ?) LIMIT 1', [$row['slug'], $needle, $needle]);
            if ($r !== null) {
                return true;
            }
        }
        return false;
    }

    /** Removes the files of copies that no page uses any more (after a page is removed for good). @return list<string> keys removed */
    public static function collect(): array
    {
        $gone = [];
        foreach (self::keys() as $key) {
            if (!self::keyInUse($key)) {
                self::removeFiles($key);
                $gone[] = $key;
            }
        }
        return $gone;
    }

    /** The policy of copied pages. $eval allows eval() for pages whose scripts need it (set per page by the administrator). */
    public static function csp(bool $eval = false): string
    {
        return "default-src 'self'; img-src 'self' data: blob: https:; media-src 'self' data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'"
            . ($eval ? " 'unsafe-eval'" : '') . " https://mc.yandex.ru; connect-src 'self' https://mc.yandex.ru https://mc.yandex.com wss://mc.yandex.ru; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-src "
            . self::FRAME_SRC . "; frame-ancestors 'self'";
    }

    /** Headers that replace the strict ones for a copied page. @param array<string,mixed> $data @return array<string,string> */
    public static function headers(array $data): array
    {
        return [self::POLICY_HEADER => 'copy', 'Content-Security-Policy' => self::csp(!empty($data['copy_eval']))];
    }

    /** An attribute list for <html> or <body>: valid attributes or nothing. */
    public static function attrs(string $attrs): string
    {
        $attrs = trim(preg_replace('/\s+/', ' ', $attrs) ?? '');
        if ($attrs === '') {
            return '';
        }
        $one = '[A-Za-z_:][-A-Za-z0-9_:.]*(?:=(?:"[^"<>]*"|\'[^\'<>]*\'|[^\s"\'<>=`]+))?';
        return preg_match('/^' . $one . '(?: ' . $one . ')*$/', $attrs) === 1 ? ' ' . $attrs : '';
    }

    /**
     * The whole page. $variant 'm' is the phone version of a page that was copied with a separate layout for phones.
     * $editCsrf is not empty when a person with the right to edit has switched the editing mode on: the page then carries the marks and the
     * script of the text editor (CopyEdit, assets/copy-edit.js). $signedIn without editing only adds a link into that mode.
     *
     * @param array<string,mixed> $doc
     * @return array{status:int,html:string,tags:list<string>,policy:string}
     */
    public static function render(string $slug, array $doc, string $variant = '', string $editCsrf = '', bool $signedIn = false): array
    {
        $d = self::data($doc);
        $settings = Seo::settings();
        Renderer::$settings = $settings;
        $mobile = $variant === 'm' && (string)($d['copy_m_body'] ?? '') !== '';
        $p = $mobile ? 'copy_m_' : 'copy_';
        $seo = Seo::head($slug, $doc, $settings);
        if ($mobile) {
            $seo = str_replace('content="index, follow"', 'content="noindex, follow"', $seo);
        }
        $head = (string)($d[$p . 'head'] ?? '');
        $body = (string)($d[$p . 'body'] ?? '');
        $viewport = preg_match('/<meta[^>]+name=["\']?viewport/i', $head) === 1 ? '' : "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        // A page with its own phone layout sends narrow screens to it before anything is drawn.
        $switch = !$mobile && (string)($d['copy_m_body'] ?? '') !== ''
            ? "<script>if(Math.min(window.innerWidth,(window.screen&&screen.width)||9999)<768&&location.search.indexOf('ag_view=')<0){location.replace(location.pathname+(location.search?location.search+'&':'?')+'ag_view=m'+location.hash)}</script>\n"
            : '';
        $canEdit = ($editCsrf !== '' || $signedIn) && (Auth::actor()?->can('content.write') ?? false);
        $editHead = '';
        $editTail = '';
        if ($canEdit) {
            $asset = static fn(string $f): string => '/assets/' . $f . '?v=' . (is_file(AGECMS_ROOT . '/assets/' . $f) ? filemtime(AGECMS_ROOT . '/assets/' . $f) : 1);
            $editHead = '<link rel="stylesheet" href="' . ag_e($asset('copy-edit.css')) . "\">\n";
            $to = '/' . ($slug === 'index' ? '' : $slug) . ($mobile ? '?ag_view=m' : '');
            if ($editCsrf !== '') {
                $base = CopyEdit::base($body);
                $body = CopyEdit::mark($body);
                $editTail = '<script src="' . ag_e($asset('copy-edit.js')) . '" defer data-slug="' . ag_e($slug) . '" data-variant="' . ($mobile ? 'm' : '') . '" data-base="' . $base . '" data-csrf="' . ag_e($editCsrf)
                    . '" data-exit="' . ag_e('/_/edit?on=0&to=' . rawurlencode($to)) . "\"></script>\n";
            } else {
                $editTail = '<a class="ag-copy-edit-link" href="' . ag_e('/_/edit?on=1&to=' . rawurlencode($to)) . "\">Править текст</a>\n";
            }
        }
        $tail = $editTail . (str_contains($body, 'data-ag-form') ? '<script src="' . ag_e('/assets/copy-forms.js?v=' . (is_file(AGECMS_ROOT . '/assets/copy-forms.js') ? filemtime(AGECMS_ROOT . '/assets/copy-forms.js') : 1)) . "\" defer></script>\n" : '');
        $html = "<!DOCTYPE html>\n<html" . self::attrs((string)($d[$p . 'html_attrs'] ?? '')) . ">\n<head>\n<meta charset=\"utf-8\">\n" . $viewport . $switch . $seo . "\n" . $head . "\n" . $editHead
            . "\n</head>\n<body" . self::attrs((string)($d[$p . 'body_attrs'] ?? '')) . ">\n" . $body . "\n" . $tail . "</body>\n</html>\n";
        $tags = ['page:' . $slug, 'settings'];
        return ['status' => 200, 'html' => $html, 'tags' => $tags, 'policy' => 'copy'];
    }
}
