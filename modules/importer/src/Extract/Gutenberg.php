<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Html\Dom;
use AgeCMS\Modules\Importer\Html\ElementParser;
use AgeCMS\Modules\Importer\Neutral;
use AgeCMS\Modules\Importer\Url;

/**
 * WordPress post content: Gutenberg blocks (<!-- wp:name {attrs} --> ... <!-- /wp:name -->) or the classic editor
 * (plain HTML with paragraphs made from blank lines, shortcodes). The saved HTML of a block carries the same
 * wp-block-* classes as the rendered page, so most blocks are parsed as HTML with the Widgets hook; the comments tell
 * which blocks are dynamic (nothing saved, the server draws them) and which are reusable blocks to look up.
 */
final class Gutenberg
{
    private const TOKEN = '~<!--\s+(?P<closer>/)?wp:(?P<ns>[a-z][a-z0-9_-]*/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>\{(?:(?:[^}]+|\}+(?=\})|(?!\}\s+/?-->).)*+)?\}\s+)?(?P<void>/)?-->~s';
    /**
     * Classes of server-rendered blocks in rendered HTML (REST API, the page itself): a snapshot of lists WordPress builds
     * on every request (latest posts, archives, search, comments, the post's own title and date). They are dropped: the
     * snapshot gets stale at once, and the search and comment forms are not lead forms.
     */
    public const RENDERED_DYNAMIC = ['wp-block-latest-posts', 'wp-block-archives', 'wp-block-archives-dropdown', 'wp-block-categories', 'wp-block-categories-dropdown', 'wp-block-calendar', 'wp-block-tag-cloud', 'wp-block-rss', 'wp-block-search', 'wp-block-query', 'wp-block-latest-comments', 'wp-block-comments', 'wp-block-comments-query-loop', 'wp-block-post-comments-form', 'wp-block-loginout', 'wp-block-site-logo', 'wp-block-site-title', 'wp-block-site-tagline', 'wp-block-post-title', 'wp-block-post-date', 'wp-block-post-author', 'wp-block-post-author-name', 'wp-block-post-author-biography', 'wp-block-post-excerpt', 'wp-block-post-featured-image', 'wp-block-post-terms', 'wp-block-post-navigation-link', 'wp-block-read-more', 'wp-block-avatar', 'wp-block-query-pagination', 'wp-block-term-description', 'wp-block-query-title', 'comment-respond',
        // The same lists made by Elementor widgets (posts, products, loop grids, search, comments, sidebars).
        'elementor-widget-posts', 'elementor-widget-archive-posts', 'elementor-widget-loop-grid', 'elementor-widget-loop-carousel', 'elementor-widget-portfolio', 'elementor-widget-wc-products', 'elementor-widget-woocommerce-products', 'elementor-widget-products', 'elementor-widget-search-form', 'elementor-widget-search', 'elementor-widget-sidebar', 'elementor-widget-post-comments', 'elementor-widget-post-navigation', 'elementor-widget-share-buttons', 'elementor-widget-login', 'elementor-widget-author-box', 'elementor-widget-post-info', 'elementor-widget-wp-widget-recent-posts', 'elementor-widget-wp-widget-archives', 'elementor-widget-wp-widget-categories', 'elementor-widget-wp-widget-search', 'elementor-widget-wp-widget-recent-comments'];
    /** oEmbed providers whose address on a line of its own becomes a player in WordPress (classic editor). */
    private const OEMBED = '~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch|shorts|embed|live)|youtu\.be/|vimeo\.com/|rutube\.ru/|vk\.com/(?:video|clip)|vkvideo\.ru/|dzen\.ru/(?:video|embed)|ok\.ru/video|twitter\.com/|x\.com/|instagram\.com/|facebook\.com/|tiktok\.com/|soundcloud\.com/|wordpress\.tv/|t\.me/|music\.yandex\.ru/|ted\.com/talks|dailymotion\.com/|flickr\.com/|spotify\.com/)~i';
    /** Blocks the server renders: there is no HTML in the content to take. */
    private const DYNAMIC = ['latest-posts', 'archives', 'categories', 'calendar', 'tag-cloud', 'rss', 'search', 'navigation', 'query', 'post-template', 'page-list', 'latest-comments', 'site-logo', 'site-title', 'loginout', 'social-links', 'post-title', 'post-content', 'template-part', 'site-tagline', 'post-date', 'post-author', 'post-author-name', 'post-author-biography', 'post-excerpt', 'post-featured-image', 'post-terms', 'post-navigation-link', 'post-comments-form', 'comments', 'comments-query-loop', 'comment-template', 'latest-comments', 'avatar', 'read-more', 'query-pagination', 'query-title', 'query-no-results', 'term-description', 'home-link'];

    /**
     * @param array<int,string> $attachments attachment id => URL, for [gallery ids=...]
     * @param array<int,string> $reusable reusable block id => its content
     * @param list<array{what:string,why:string,source:string}> $notes filled with what could not be taken
     * @param array{post_id?:int,children?:array<int,list<int>>,pages?:list<array{id:int,parent:int,title:string,link:string,order:int}>} $ctx
     *        what an export knows besides the content: the post id, attachment ids by their parent post (for [gallery]
     *        without ids) and the pages (for the page list block)
     * @return list<array<string,mixed>>
     */
    public static function toElements(string $content, string $base, array $attachments = [], array $reusable = [], array &$notes = [], int $depth = 0, array $ctx = []): array
    {
        if (!str_contains($content, '<!-- wp:')) {
            return self::html(self::autop(self::shortcodes(self::embeds($content), $attachments, $notes, $ctx)), $base);
        }
        $out = [];
        foreach (self::parse($content) as $block) {
            $out = array_merge($out, self::block($block, $base, $attachments, $reusable, $notes, $depth, $ctx));
        }
        return ElementParser::tidy($out);
    }

    /**
     * Notes for the server-rendered blocks found in rendered HTML (they are dropped by the Widgets hook).
     *
     * @return list<array{what:string,why:string,source:string}>
     */
    public static function renderedNotes(string $html): array
    {
        $notes = [];
        if ($html === '' || !str_contains($html, 'wp-block-') && !str_contains($html, 'comment-respond')) {
            return [];
        }
        $names = implode('|', array_map(static fn(string $c): string => preg_quote($c, '~'), self::RENDERED_DYNAMIC));
        if (preg_match_all('~class=["\'](?:[^"\']*\s)?(' . $names . ')(?=[\s"\'])~', $html, $m) > 0) {
            foreach (array_unique($m[1]) as $class) {
                $notes[] = ['what' => match (true) {
                    $class === 'comment-respond' => 'Блок комментариев',
                    str_starts_with($class, 'elementor-widget-') => 'Виджет Elementor ' . substr($class, 17),
                    default => 'Блок core/' . substr($class, 9),
                }, 'why' => 'его WordPress собирает сам при каждом показе (списки записей, архивы, поиск, комментарии): не перенесён, для списка записей раздела есть блок child_pages', 'source' => $class];
            }
        }
        return $notes;
    }

    /**
     * Top of a blog post: its title as the h1 when the content opens with a cover (the theme prints the title above it,
     * so the cover is not the first screen of a post), then the featured image unless the content already shows it.
     *
     * @param list<array<string,mixed>> $els
     * @return list<array<string,mixed>>
     */
    public static function postTop(array $els, string $kind, string $title, string $featured, string $alt, string $source): array
    {
        if ($kind !== 'post') {
            return $els;
        }
        $top = [];
        if ($title !== '' && ($els[0]['type'] ?? '') === 'hero') {
            $top[] = Neutral::el('heading', ['level' => 1, 'text' => $title], 'заголовок записи');
        }
        if ($featured !== '') {
            $key = self::imageKey($featured);
            $shown = false;
            foreach ($els as $el) {
                $srcs = match ($el['type'] ?? '') {
                    'image' => [(string)$el['src']],
                    'hero' => [(string)($el['image'] ?? '')],
                    'gallery' => array_map(static fn(array $i): string => (string)$i['src'], (array)$el['images']),
                    default => [],
                };
                foreach ($srcs as $src) {
                    $shown = $shown || ($src !== '' && self::imageKey($src) === $key);
                }
            }
            if (!$shown) {
                $top[] = Neutral::el('image', ['src' => $featured, 'alt' => $alt !== '' ? $alt : $title, 'caption' => ''], $source);
            }
        }
        return array_merge($top, $els);
    }

    /** The same picture in any of the sizes WordPress cuts (photo-300x200.jpg, photo-scaled.jpg, photo.jpg?w=604). */
    private static function imageKey(string $src): string
    {
        $path = strtolower((string)(parse_url($src, PHP_URL_PATH) ?? $src));
        return preg_replace('~(-\d+x\d+|-scaled|-e\d{10,})+(\.[a-z0-9]+)$~', '$2', $path) ?? $path;
    }

    /**
     * A known video, post or music address on a line of its own: WordPress shows a player there (auto-embed of the classic
     * editor), so it becomes an embed instead of a bare address in the text.
     */
    public static function embeds(string $html): string
    {
        if (!str_contains($html, 'http')) {
            return $html;
        }
        return preg_replace_callback('~^[ \t]*(?:<p>)?[ \t]*(https?://[^\s<>"\']+)[ \t]*(?:</p>)?[ \t]*$~mi', static function (array $m): string {
            return preg_match(self::OEMBED, $m[1]) === 1 ? '<iframe src="' . htmlspecialchars($m[1], ENT_QUOTES) . '"></iframe>' : $m[0];
        }, $html) ?? $html;
    }

    /**
     * Block tree: each block is {name, attrs, parts}, parts are HTML strings and child blocks in order.
     * HTML between top-level blocks becomes a "freeform" block (classic content).
     *
     * @return list<array{name:string,attrs:array<string,mixed>,parts:list<mixed>}>
     */
    public static function parse(string $content): array
    {
        $root = ['name' => 'root', 'attrs' => [], 'parts' => []];
        $stack = [&$root];
        $offset = 0;
        preg_match_all(self::TOKEN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $start = $m[0][1];
            $text = substr($content, $offset, $start - $offset);
            $offset = $start + strlen($m[0][0]);
            $top = &$stack[count($stack) - 1];
            if ($text !== '') {
                $top['parts'][] = count($stack) === 1 ? (trim($text) === '' ? '' : ['name' => 'freeform', 'attrs' => [], 'parts' => [$text]]) : $text;
            }
            $name = ($m['ns'][0] ?? '') !== '' ? $m['ns'][0] . $m['name'][0] : 'core/' . $m['name'][0];
            $attrs = isset($m['attrs']) && $m['attrs'][1] >= 0 && $m['attrs'][0] !== '' ? (json_decode(trim($m['attrs'][0]), true) ?: []) : [];
            if (($m['closer'][1] ?? -1) >= 0 && $m['closer'][0] === '/') {
                if (count($stack) > 1) {
                    unset($top);
                    array_pop($stack);
                }
                continue;
            }
            $block = ['name' => $name, 'attrs' => $attrs, 'parts' => []];
            if (($m['void'][1] ?? -1) >= 0 && $m['void'][0] === '/') {
                $top['parts'][] = $block;
                unset($top);
                continue;
            }
            $top['parts'][] = $block;
            $stack[] = &$top['parts'][count($top['parts']) - 1];
            unset($top);
        }
        $rest = substr($content, $offset);
        if (trim($rest) !== '') {
            $stack[0]['parts'][] = ['name' => 'freeform', 'attrs' => [], 'parts' => [$rest]];
        }
        return array_values(array_filter($root['parts'], static fn($p): bool => is_array($p)));
    }

    /** The saved HTML of a block with its inner blocks. */
    public static function render(array $block): string
    {
        $html = '';
        foreach ($block['parts'] as $part) {
            $html .= is_array($part) ? self::render($part) : $part;
        }
        return $html;
    }

    /**
     * @param array{name:string,attrs:array<string,mixed>,parts:list<mixed>} $block
     * @param array<int,string> $attachments
     * @param array<int,string> $reusable
     * @param list<array{what:string,why:string,source:string}> $notes
     * @return list<array<string,mixed>>
     */
    private static function block(array $block, string $base, array $attachments, array $reusable, array &$notes, int $depth, array $ctx = []): array
    {
        $name = str_starts_with($block['name'], 'core/') ? substr($block['name'], 5) : $block['name'];
        $source = 'wp:' . $name;
        if (in_array($name, ['spacer', 'separator', 'more', 'nextpage'], true)) {
            return [];
        }
        if ($name === 'block') {
            $ref = (int)($block['attrs']['ref'] ?? 0);
            if (isset($reusable[$ref]) && $depth < 3) {
                return self::toElements($reusable[$ref], $base, $attachments, $reusable, $notes, $depth + 1, $ctx);
            }
            $notes[] = ['what' => 'Многоразовый блок #' . $ref, 'why' => 'его содержимое хранится отдельно и в выгрузку не попало', 'source' => $source];
            return [];
        }
        if ($name === 'page-list' && isset($ctx['pages'])) {
            $list = self::pageList($block, $ctx);
            if ($list !== null) {
                return [$list];
            }
        }
        if ($name === 'footnotes') {
            $items = [];
            foreach ((array)($ctx['footnotes'] ?? []) as $note) {
                if (is_array($note) && trim(strip_tags((string)($note['content'] ?? ''))) !== '') {
                    $items[] = (string)$note['content'];
                }
            }
            return $items === [] ? [] : self::html('<ol><li>' . implode('</li><li>', $items) . '</li></ol>', $base, $source);
        }
        if ($name === 'social-links') {
            $items = [];
            foreach ($block['parts'] as $part) {
                if (is_array($part) && ($url = (string)($part['attrs']['url'] ?? '')) !== '') {
                    $label = (string)($part['attrs']['label'] ?? '');
                    $service = (string)($part['attrs']['service'] ?? '');
                    $text = $label !== '' ? $label : ($service !== '' ? ucfirst($service) : $url);
                    $items[] = '<a href="' . htmlspecialchars(Url::resolve($base, $url), ENT_QUOTES) . '">' . htmlspecialchars($text, ENT_QUOTES) . '</a>';
                }
            }
            return $items === [] ? [] : [Neutral::el('list', ['ordered' => false, 'items' => $items], $source)];
        }
        if (in_array($name, self::DYNAMIC, true)) {
            $notes[] = ['what' => 'Блок ' . $block['name'], 'why' => 'его рисует сервер WordPress, текста в содержимом нет', 'source' => $source];
            return [];
        }
        if ($name === 'freeform' || $name === 'shortcode') {
            return self::html(self::autop(self::shortcodes(self::embeds(self::render($block)), $attachments, $notes, $ctx)), $base, $source);
        }
        if ($name === 'embed' || str_starts_with($name, 'core-embed/')) {
            $url = (string)($block['attrs']['url'] ?? '');
            if ($url === '') {
                $url = trim(strip_tags(self::render($block)));
            }
            return $url === '' ? [] : [Neutral::el('embed', ['src' => $url, 'kind' => ElementParser::embedKind($url)], $source)];
        }
        if ($name === 'gallery' && ($ids = $block['attrs']['ids'] ?? []) !== [] && !str_contains(self::render($block), '<img')) {
            $images = [];
            foreach ((array)$ids as $id) {
                if (isset($attachments[(int)$id])) {
                    $images[] = ['src' => $attachments[(int)$id], 'alt' => '', 'caption' => ''];
                }
            }
            return $images === [] ? [] : [Neutral::el('gallery', ['images' => $images], $source)];
        }
        $html = self::render($block);
        if ($name === 'html' && preg_match('~<script~i', $html) === 1) {
            $notes[] = ['what' => 'Свой HTML со скриптом', 'why' => 'скрипты на страницах не переносятся', 'source' => $source];
        }
        $els = self::html($html, $base, $source);
        // A button without a link (left empty in the editor) carries nothing to keep.
        if ($els === [] && trim(strip_tags($html)) !== '' && !in_array($name, ['buttons', 'button'], true)) {
            $els[] = Neutral::el('raw', ['html' => $html, 'note' => 'блок ' . $block['name'] . ' не разобран'], $source);
        }
        if (!str_starts_with($block['name'], 'core/') && !str_starts_with($block['name'], 'yoast') && !str_starts_with($block['name'], 'rank-math') && $els !== []) {
            $notes[] = ['what' => 'Блок ' . $block['name'], 'why' => 'блок стороннего плагина: перенесён текст и картинки, оформление нет', 'source' => $source];
        }
        return $els;
    }

    /**
     * The page list block of an export: links to the child pages of the page (or of parentPageID), as the site showed them.
     *
     * @param array{name:string,attrs:array<string,mixed>,parts:list<mixed>} $block
     * @param array{post_id?:int,pages?:list<array{id:int,parent:int,title:string,link:string,order:int}>} $ctx
     * @return array<string,mixed>|null
     */
    private static function pageList(array $block, array $ctx): ?array
    {
        $parent = (int)($block['attrs']['parentPageID'] ?? 0);
        $pages = array_values(array_filter((array)($ctx['pages'] ?? []), static fn(array $p): bool => $p['parent'] === $parent && $p['link'] !== '' && $p['title'] !== ''));
        if ($pages === []) {
            return null;
        }
        usort($pages, static fn(array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);
        $items = array_map(static fn(array $p): string => '<a href="' . htmlspecialchars($p['link'], ENT_QUOTES) . '">' . htmlspecialchars($p['title'], ENT_QUOTES) . '</a>', array_slice($pages, 0, 100));
        return Neutral::el('list', ['ordered' => false, 'items' => $items], 'wp:page-list');
    }

    /** @return list<array<string,mixed>> */
    private static function html(string $html, string $base, string $source = ''): array
    {
        if (trim($html) === '') {
            return [];
        }
        $doc = Dom::load($html);
        $body = Dom::first(Dom::xpath($doc), '//body');
        if ($body === null) {
            return [];
        }
        $els = (new ElementParser($base, Widgets::hook(...)))->parse($body);
        if ($source !== '') {
            foreach ($els as &$el) {
                $el['source'] = trim($source . ' ' . $el['source']);
            }
            unset($el);
        }
        return $els;
    }

    /** Paragraphs from blank lines, line breaks from single ones (what wpautop does for the classic editor). */
    public static function autop(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        if (trim($html) === '') {
            return '';
        }
        $blocks = 'p|div|h[1-6]|ul|ol|li|table|thead|tbody|tr|td|th|blockquote|figure|figcaption|pre|form|iframe|hr|section|article|dl|dt|dd|address|header|footer|nav|aside|details|summary|fieldset|video|audio|object|embed|script|style';
        $html = preg_replace('~(<(?:' . $blocks . ')[\s>/])~i', "\n\n$1", $html) ?? $html;
        $html = preg_replace('~(</(?:' . $blocks . ')>)~i', "$1\n\n", $html) ?? $html;
        $out = '';
        foreach (preg_split('~\n\s*\n~', $html) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (preg_match('~^</?(?:' . $blocks . ')[\s>/]~i', $chunk) === 1) {
                $out .= $chunk . "\n";
            } else {
                $out .= '<p>' . nl2br($chunk, false) . "</p>\n";
            }
        }
        return $out;
    }

    /**
     * Shortcodes of the classic editor: [caption], [gallery ids], [embed], [video] become HTML; contact forms become a form
     * marker; page builder shortcodes ([vc_*], [et_pb_*], [fusion_*]) are unwrapped to their text; the rest is dropped
     * with a note.
     *
     * @param array<int,string> $attachments
     * @param list<array{what:string,why:string,source:string}> $notes
     * @param array{post_id?:int,children?:array<int,list<int>>,media?:array<int,array{alt:string,caption:string}>} $ctx
     */
    public static function shortcodes(string $html, array $attachments, array &$notes, array $ctx = []): string
    {
        if (!str_contains($html, '[')) {
            return $html;
        }
        $html = preg_replace_callback('~\[caption([^\]]*)\](.*?)\[/caption\]~is', static function (array $m): string {
            $inner = $m[2];
            $img = '';
            if (preg_match('~(<a[^>]*>\s*)?<img[^>]*>(\s*</a>)?~i', $inner, $im) === 1) {
                $img = $im[0];
                $inner = str_replace($im[0], '', $inner);
            }
            // Old exports keep the text in the attribute: [caption caption="..."]<img>[/caption]
            if (trim(strip_tags($inner)) === '' && preg_match('~\scaption=(["\'])(.*?)\1~is', $m[1], $c) === 1) {
                $inner = $c[2];
            }
            return '<figure class="wp-caption">' . $img . (trim($inner) !== '' ? '<figcaption>' . trim($inner) . '</figcaption>' : '') . '</figure>';
        }, $html) ?? $html;
        $html = preg_replace_callback('~\[gallery([^\]]*)\]~i', static function (array $m) use ($attachments, $ctx): string {
            $list = [];
            if (preg_match('~(?:ids|include)=["\']?([\d,\s]+)~', $m[1], $ids) === 1) {
                $list = array_map('intval', explode(',', $ids[1]));
            } elseif (($ctx['post_id'] ?? 0) > 0) {
                // [gallery] without ids shows the pictures attached to the post.
                $list = (array)($ctx['children'][(int)$ctx['post_id']] ?? []);
                if (preg_match('~exclude=["\']?([\d,\s]+)~', $m[1], $ex) === 1) {
                    $list = array_values(array_diff($list, array_map('intval', explode(',', $ex[1]))));
                }
            }
            $imgs = '';
            foreach ($list as $id) {
                if (isset($attachments[(int)$id])) {
                    // The alt text and the caption of the attachment, when the export has them.
                    $alt = (string)($ctx['media'][(int)$id]['alt'] ?? '');
                    $caption = (string)($ctx['media'][(int)$id]['caption'] ?? '');
                    $imgs .= '<figure><img src="' . htmlspecialchars($attachments[(int)$id], ENT_QUOTES) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '">'
                        . ($caption !== '' ? '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES) . '</figcaption>' : '') . '</figure>';
                }
            }
            return $imgs === '' ? '' : '<figure class="wp-block-gallery">' . $imgs . '</figure>';
        }, $html) ?? $html;
        $html = preg_replace('~\[embed[^\]]*\](.*?)\[/embed\]~is', '<iframe src="$1"></iframe>', $html) ?? $html;
        $html = preg_replace('~\[video[^\]]*\s(?:src|mp4|webm|ogv|m4v)=["\']([^"\']+)["\'][^\]]*\](\[/video\])?~i', '<iframe src="$1"></iframe>', $html) ?? $html;
        // An audio file stays a link to the file (the media library keeps pictures only).
        $html = preg_replace_callback('~\[audio(?:[^\]]*\s(?:src|mp3|m4a|ogg|wav|wma|flac)=["\']([^"\']+)["\']|\s+(https?://[^\s\]]+))[^\]]*\](\[/audio\])?~i', static function (array $m): string {
            $file = ($m[1] ?? '') !== '' ? $m[1] : (string)($m[2] ?? '');
            return '<a href="' . htmlspecialchars($file, ENT_QUOTES) . '">' . htmlspecialchars(rawurldecode(basename((string)parse_url($file, PHP_URL_PATH))), ENT_QUOTES) . '</a>';
        }, $html) ?? $html;
        $html = preg_replace('~\[(video|audio)\s+(https?://[^\s\]]+)\](\[/\1\])?~i', '<iframe src="$2"></iframe>', $html) ?? $html;
        $html = preg_replace_callback('~\[(contact-form-7|contact-form|wpforms|gravityform|ninja_form|formidable|caldera_form|fluentform|cf7)[^\]]*\]~i', static function (array $m) use (&$notes): string {
            $notes[] = ['what' => 'Форма [' . $m[1] . ']', 'why' => 'поля формы плагина не переносятся, вместо неё ставится форма заявки сайта', 'source' => 'shortcode ' . $m[1]];
            return '<form data-ag-shortcode="' . htmlspecialchars($m[1], ENT_QUOTES) . '"><input type="text" name="name" placeholder="Имя"><input type="tel" name="phone" placeholder="Телефон"><textarea name="message"></textarea><button type="submit">Отправить</button></form>';
        }, $html) ?? $html;
        $builder = false;
        $html = preg_replace_callback('~\[/?(vc_|et_pb_|fusion_|av_|cs_|fl_|mk_|ux_|tatsu_|themify_|row|column|col|section|one_half|one_third|two_third|one_fourth|three_fourth)[a-z0-9_]*[^\]]*\]~i', static function () use (&$builder): string {
            $builder = true;
            return "\n\n";
        }, $html) ?? $html;
        if ($builder) {
            $notes[] = ['what' => 'Вёрстка конструктора страниц (шорткоды)', 'why' => 'колонки и оформление конструктора не переносятся, взят текст', 'source' => 'shortcode'];
        }
        $html = preg_replace_callback('~\[([a-z][a-z0-9_-]*)(\s[^\]]*)?\](?:(.*?)\[/\1\])?~is', static function (array $m) use (&$notes): string {
            $notes[] = ['what' => 'Шорткод [' . $m[1] . ']', 'why' => 'его рисует плагин WordPress, перенесён только текст внутри', 'source' => 'shortcode ' . $m[1]];
            return $m[3] ?? '';
        }, $html) ?? $html;
        return $html;
    }

    /** True when the content is made by a page builder whose markup in the content is not the page (Elementor, Divi, WPBakery...). */
    public static function isBuilder(string $content): bool
    {
        return preg_match('~\[(vc_row|vc_column|et_pb_section|et_pb_row|fusion_builder|fl_builder|av_section|cs_section|tatsu_section)|data-elementor-type|class="[^"]*\b(elementor|et_pb_section|vc_row|fl-builder|brxe-|tatsu-)~i', $content) === 1;
    }
}
