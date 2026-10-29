<?php
declare(strict_types=1);

namespace AgeCMS;

/** Turns a page document into HTML with the theme's layout and block templates. */
final class Renderer
{
    /** Read by ag_edit() while a block template is being rendered. @var array{editing:bool,slug:string,block:string,csrf:string} */
    public static array $context = ['editing' => false, 'slug' => '', 'block' => '', 'csrf' => ''];

    /** Settings of the page being rendered, for ag_setting() in block templates. @var array<string,string> */
    public static array $settings = [];

    /**
     * Renders a stored page. Returns null when the page does not exist or, outside edit mode and preview, is unpublished.
     *
     * @return array{status:int,html:string,tags:list<string>}|null
     */
    public static function renderSlug(string $slug, bool $editing, string $csrf = '', bool $preview = false): ?array
    {
        $row = Pages::row($slug);
        if ($row === null || ((int)$row['is_published'] !== 1 && !$editing && !$preview)) {
            return null;
        }
        return self::renderDoc($slug, Pages::toDoc($row), $editing, $csrf);
    }

    /**
     * @param array<string,mixed> $doc
     * @return array{status:int,html:string,tags:list<string>}
     */
    public static function renderDoc(string $slug, array $doc, bool $editing, string $csrf = ''): array
    {
        $theme = $doc['theme'] !== '' ? $doc['theme'] : Theme::active();
        $themeDir = Theme::dir($theme);

        self::$context = ['editing' => $editing, 'slug' => $slug, 'block' => '', 'csrf' => $csrf];
        self::$settings = Seo::settings();
        $content = '';
        foreach ($doc['blocks'] as $block) {
            $file = "{$themeDir}/blocks/{$block['type']}.php";
            if (!is_file($file)) {
                continue;
            }
            self::$context['block'] = $block['id'];
            $html = self::include($file, ['data' => $block['data'], 'id' => $block['id'], 'slug' => $slug, 'editing' => $editing]);
            // In edit mode every block sits in a wrapper, so editor.js knows where a block starts and ends. The script makes it
            // display: contents (the page policy forbids inline style attributes), so the theme looks the same.
            $content .= $editing
                ? '<div data-ag-wrap="' . ag_e($block['id']) . '" data-ag-type="' . ag_e($block['type']) . '">' . $html . '</div>'
                : $html;
        }
        self::$context['block'] = '';
        if ($editing) {
            $content .= '<div data-ag-end></div>' . BlockEditor::payload($slug, $theme, $doc, $csrf);
        }

        $tags = ['page:' . $slug, 'settings', 'theme:' . $theme, 'menu:header', 'menu:footer'];
        foreach (Pages::ancestors($slug) as $ancestor) {
            $tags[] = 'page:' . $ancestor;
        }
        foreach (Theme::cachePages($theme) as $shared) {
            $tags[] = 'page:' . $shared;
        }
        $tags = array_values(array_unique($tags));

        $settings = self::$settings;
        $html = self::layout($theme, [
            'doc' => $doc,
            'slug' => $slug,
            'settings' => $settings,
            'head' => Seo::head($slug, $doc, $settings),
            'content' => $content,
            'menus' => ['header' => self::menu('header'), 'footer' => self::menu('footer')],
            'editing' => $editing,
            'csrf' => self::$context['csrf'],
        ]);
        return ['status' => 200, 'html' => $html, 'tags' => $tags];
    }

    /**
     * A page around ready-made HTML: 404, search results, errors. Never cached.
     *
     * @param array{slug:string,description:string}|null $seo set only for a page of an indexable module route: it is offered to search engines
     */
    public static function shell(string $title, string $contentHtml, bool $editing = false, ?array $seo = null): string
    {
        $theme = Theme::active();
        $settings = self::$settings = Seo::settings();
        $doc = ['title' => $title, 'meta_title' => '', 'meta_description' => '', 'canonical_url' => '', 'og_image' => '', 'is_noindex' => 1, 'is_published' => 1, 'schema_type' => 'WebPage', 'blocks' => []];
        if ($seo !== null) {
            $doc['is_noindex'] = 0;
            $doc['meta_description'] = $seo['description'];
            $head = Seo::head($seo['slug'], $doc, $settings);
        } else {
            $head = '<title>' . ag_e($title . ($settings['site_name'] ?? '' ? ' - ' . $settings['site_name'] : '')) . '</title>' . "\n    " . '<meta name="robots" content="noindex, nofollow">';
        }
        return self::layout($theme, [
            'doc' => $doc,
            'slug' => '',
            'settings' => $settings,
            'head' => $head,
            'content' => $contentHtml,
            'menus' => ['header' => self::menu('header'), 'footer' => self::menu('footer')],
            'editing' => false,
            'csrf' => '',
        ]);
    }

    /** @return list<array{title:string,url:string}> */
    private static function menu(string $name): array
    {
        $json = Database::value('SELECT items_json FROM menus WHERE name = ?', [$name]);
        return $json === null ? [] : json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The theme's layout.php. Besides the page it gets $themeSettings: the values of its own settings (theme.json "settings"),
     * ['header' => [...], 'footer' => [...]], see Theme::settingValues.
     *
     * @param array<string,mixed> $vars
     */
    private static function layout(string $theme, array $vars): string
    {
        $vars['themeSettings'] = Theme::settingValues($theme, $vars['settings']);
        $html = self::include(Theme::dir($theme) . '/layout.php', $vars);
        return $vars['editing'] ? $html : self::withCookieNotice($html, $vars['settings']);
    }

    /**
     * The notice about cookies at the bottom of every page, unless the owner switched it off (tab «Сайт») or the theme shows
     * its own (an element with data-ag-cookie). It is in the cached page, hidden; assets/cookie.js shows it to whoever has
     * not closed it yet. No inline script or style: the page policy allows only files of the site.
     *
     * @param array<string,string> $settings
     */
    public static function withCookieNotice(string $html, array $settings): string
    {
        if (!SiteSettings::cookieOn($settings) || str_contains($html, 'data-ag-cookie') || ($body = strripos($html, '</body>')) === false) {
            return $html;
        }
        $text = trim($settings['cookie_text'] ?? '') !== '' ? $settings['cookie_text'] : SiteSettings::COOKIE_TEXT;
        $policy = trim($settings['cookie_policy_url'] ?? '');
        $asset = static fn(string $file): string => ag_e($file . '?v=' . (is_file(AGECMS_ROOT . $file) ? filemtime(AGECMS_ROOT . $file) : 1));
        $notice = '<div class="ag-cookie" data-ag-cookie hidden role="region" aria-label="Файлы cookie"><p>' . ag_e($text)
            . ($policy !== '' ? ' <a href="' . ag_e($policy) . '">Подробнее</a>' : '') . '</p>'
            . '<button type="button" data-ag-cookie-ok>Понятно</button></div>'
            . '<script src="' . $asset('/assets/cookie.js') . '" defer></script>';
        $html = substr($html, 0, $body) . $notice . "\n" . substr($html, $body);
        $css = '<link rel="stylesheet" href="' . $asset('/assets/cookie.css') . '">';
        $head = stripos($html, '</head>');
        return $head === false ? $html : substr($html, 0, $head) . $css . "\n" . substr($html, $head);
    }

    /** @param array<string,mixed> $vars */
    private static function include(string $file, array $vars): string
    {
        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
                return (string)ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };
        return $render($file, $vars);
    }
}
