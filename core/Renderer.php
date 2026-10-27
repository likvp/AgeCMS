<?php
declare(strict_types=1);

namespace AgeCMS;

/** Turns a page document into HTML with the theme's layout and block templates. */
final class Renderer
{
    /** Read by ag_edit() while a block template is being rendered. @var array{editing:bool,slug:string,block:string,csrf:string} */
    public static array $context = ['editing' => false, 'slug' => '', 'block' => '', 'csrf' => ''];

    /**
     * Renders a stored page. Returns null when the page does not exist or, outside edit mode, is unpublished.
     *
     * @return array{status:int,html:string,tags:list<string>}|null
     */
    public static function renderSlug(string $slug, bool $editing, string $csrf = ''): ?array
    {
        $row = Pages::row($slug);
        if ($row === null || ((int)$row['is_published'] !== 1 && !$editing)) {
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
        $content = '';
        foreach ($doc['blocks'] as $block) {
            $file = "{$themeDir}/blocks/{$block['type']}.php";
            if (!is_file($file)) {
                continue;
            }
            self::$context['block'] = $block['id'];
            $content .= self::include($file, ['data' => $block['data'], 'id' => $block['id'], 'slug' => $slug, 'editing' => $editing]);
        }
        self::$context['block'] = '';

        $tags = ['page:' . $slug, 'settings', 'theme:' . $theme, 'menu:header', 'menu:footer'];
        foreach (Pages::ancestors($slug) as $ancestor) {
            $tags[] = 'page:' . $ancestor;
        }
        foreach (Theme::cachePages($theme) as $shared) {
            $tags[] = 'page:' . $shared;
        }
        $tags = array_values(array_unique($tags));

        $settings = Seo::settings();
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

    /** A page around ready-made HTML: 404, search results, errors. Never cached. */
    public static function shell(string $title, string $contentHtml, bool $editing = false): string
    {
        $theme = Theme::active();
        $settings = Seo::settings();
        $doc = ['title' => $title, 'meta_title' => '', 'meta_description' => '', 'canonical_url' => '', 'og_image' => '', 'is_noindex' => 1, 'is_published' => 1, 'schema_type' => 'WebPage', 'blocks' => []];
        $head = '<title>' . ag_e($title . ($settings['site_name'] ?? '' ? ' - ' . $settings['site_name'] : '')) . '</title>' . "\n    " . '<meta name="robots" content="noindex, nofollow">';
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

    /** @param array<string,mixed> $vars */
    private static function layout(string $theme, array $vars): string
    {
        return self::include(Theme::dir($theme) . '/layout.php', $vars);
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
