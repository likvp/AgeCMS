<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\ImportException;
use AgeCMS\Modules\Importer\Neutral;
use AgeCMS\Modules\Importer\Url;
use DOMDocument;
use DOMElement;

/**
 * The WordPress export file (Tools > Export, WXR XML): published pages and posts with their raw Gutenberg or classic
 * content, attachments for featured images and galleries, reusable blocks, menus (nav_menu_item), Yoast / Rank Math /
 * AIOSEO fields from postmeta. Old addresses: link, ?p= and ?page_id=. Nothing goes to the network here.
 */
final class Wxr implements Extractor
{
    /** @param array{file:string,limit?:int} $options */
    public function __construct(private array $options)
    {
    }

    /** True when the text looks like a WordPress export. */
    public static function looks(string $xml): bool
    {
        $head = substr($xml, 0, 4000);
        return str_contains($head, '<rss') && (str_contains($head, 'wordpress.org/export') || str_contains($head, 'xmlns:wp='));
    }

    public function step(array &$state, float $deadline, callable $emit): bool
    {
        $xml = (string)@file_get_contents($this->options['file']);
        if (!self::looks($xml)) {
            throw new ImportException('Файл не похож на выгрузку WordPress (WXR): в WordPress это «Инструменты», «Экспорт», «Всё содержимое».');
        }
        $data = self::read($xml);
        $limit = max(1, (int)($this->options['limit'] ?? 200));
        $state['site'] = [
            'source_url' => $data['base'],
            'platform' => 'wordpress',
            'title' => $data['title'],
            'menus' => $data['menus'],
        ];
        $state['detect'] = ['platform' => 'wordpress', 'name' => 'WordPress', 'confidence' => 1.0, 'evidence' => ['файл выгрузки WordPress (WXR ' . $data['version'] . ')'], 'scores' => ['wordpress' => 1.0]];
        $state['warnings'] = $data['warnings'];
        $count = 0;
        foreach ($data['pages'] as $page) {
            if ($count >= $limit) {
                $state['warnings'][] = 'Достигнут лимит страниц (' . $limit . '), остальные записи не взяты.';
                break;
            }
            $emit($page);
            $count++;
        }
        $state['emitted'] = $count;
        return true;
    }

    /**
     * @return array{title:string,base:string,version:string,pages:list<array<string,mixed>>,menus:array{header:list<array{title:string,url:string}>,footer:list<array{title:string,url:string}>},warnings:list<string>}
     */
    public static function read(string $xml): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | (defined('LIBXML_PARSEHUGE') ? LIBXML_PARSEHUGE : 0));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new ImportException('Файл выгрузки WordPress повреждён: XML не читается.');
        }
        $channel = $doc->getElementsByTagName('channel')->item(0);
        if (!$channel instanceof DOMElement) {
            throw new ImportException('В файле нет раздела channel, это не выгрузка WordPress.');
        }
        $title = '';
        $base = '';
        $version = '';
        foreach ($channel->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            match ($c->nodeName) {
                'title' => $title = Neutral::line($c->textContent),
                'wp:base_blog_url' => $base = trim($c->textContent),
                'wp:base_site_url' => $base = $base === '' ? trim($c->textContent) : $base,
                'link' => $base = $base === '' ? trim($c->textContent) : $base,
                'wp:wxr_version' => $version = trim($c->textContent),
                default => null,
            };
        }
        $base = rtrim($base, '/') . '/';

        $items = [];
        foreach ($channel->getElementsByTagName('item') as $item) {
            $items[] = self::item($item);
        }
        $attachments = [];
        $reusable = [];
        $byId = [];
        $children = [];
        $pageList = [];
        $media = [];
        foreach ($items as $it) {
            if ($it['type'] === 'attachment' && $it['attachment_url'] !== '') {
                $attachments[$it['id']] = $it['attachment_url'];
                $media[$it['id']] = ['alt' => Neutral::line((string)($it['meta']['_wp_attachment_image_alt'] ?? '')), 'caption' => Neutral::line(strip_tags($it['excerpt']))];
                if ($it['parent'] > 0 && preg_match('~\.(jpe?g|png|gif|webp|avif)$~i', (string)parse_url($it['attachment_url'], PHP_URL_PATH)) === 1) {
                    $children[$it['parent']][] = ['id' => $it['id'], 'order' => $it['order']];
                }
            } elseif ($it['type'] === 'wp_block') {
                $reusable[$it['id']] = $it['content'];
            } elseif ($it['type'] === 'page' && $it['status'] === 'publish' && $it['password'] === '') {
                $pageList[] = ['id' => $it['id'], 'parent' => $it['parent'], 'title' => Neutral::line($it['title']), 'link' => $it['link'], 'order' => $it['order']];
            }
            $byId[$it['id']] = $it;
        }
        // [gallery] without ids shows the attached pictures in their menu order (then by id, as WordPress does).
        foreach ($children as &$list) {
            usort($list, static fn(array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
            $list = array_column($list, 'id');
        }
        unset($list);
        $ctx = ['children' => $children, 'pages' => $pageList, 'media' => $media];
        $skipped = [];
        $pages = [];
        foreach (['page', 'post'] as $type) {
            foreach ($items as $it) {
                if ($it['type'] !== $type) {
                    continue;
                }
                if ($it['status'] !== 'publish') {
                    $skipped[$it['status']] = ($skipped[$it['status']] ?? 0) + 1;
                    continue;
                }
                $footnotes = json_decode((string)($it['meta']['footnotes'] ?? ''), true);
                $pages[] = self::page($it, $byId, $attachments, $reusable, $base, $title, $ctx + ['post_id' => $it['id'], 'footnotes' => is_array($footnotes) ? $footnotes : []]);
            }
        }
        $warnings = [];
        foreach ($skipped as $status => $n) {
            $warnings[] = 'Не взяты записи со статусом «' . $status . '»: ' . $n . ' (переносятся только опубликованные).';
        }
        $other = [];
        foreach ($items as $it) {
            if (!in_array($it['type'], ['page', 'post', 'attachment', 'nav_menu_item', 'wp_block', 'revision', 'custom_css', 'customize_changeset', 'oembed_cache', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part', 'user_request', 'wpcf7_contact_form', 'elementor_library'], true)) {
                $other[$it['type']] = ($other[$it['type']] ?? 0) + 1;
            }
        }
        foreach ($other as $type => $n) {
            $warnings[] = 'В выгрузке есть записи типа «' . $type . '» (' . $n . '): этот тип не переносится.';
        }
        return ['title' => $title, 'base' => $base, 'version' => $version, 'pages' => $pages, 'menus' => self::menus($items, $byId, $base), 'warnings' => $warnings];
    }

    /** @return array<string,mixed> */
    private static function item(DOMElement $item): array
    {
        $out = ['id' => 0, 'type' => '', 'status' => '', 'password' => '', 'title' => '', 'link' => '', 'guid' => '', 'content' => '', 'excerpt' => '', 'name' => '', 'parent' => 0, 'date' => '', 'order' => 0, 'attachment_url' => '', 'meta' => [], 'terms' => []];
        foreach ($item->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            $v = $c->textContent;
            switch ($c->nodeName) {
                case 'title': $out['title'] = $v; break;
                case 'link': $out['link'] = trim($v); break;
                case 'guid': $out['guid'] = trim($v); break;
                case 'content:encoded': $out['content'] = $v; break;
                case 'excerpt:encoded': $out['excerpt'] = $v; break;
                case 'wp:post_id': $out['id'] = (int)$v; break;
                case 'wp:post_type': $out['type'] = trim($v); break;
                case 'wp:status': $out['status'] = trim($v); break;
                case 'wp:post_password': $out['password'] = $v; break;
                case 'wp:post_name': $out['name'] = rawurldecode(trim($v)); break;
                case 'wp:post_parent': $out['parent'] = (int)$v; break;
                case 'wp:post_date': $out['date'] = trim($v); break;
                case 'wp:menu_order': $out['order'] = (int)$v; break;
                case 'wp:attachment_url': $out['attachment_url'] = trim($v); break;
                case 'category':
                    $out['terms'][] = ['domain' => $c->getAttribute('domain'), 'slug' => $c->getAttribute('nicename'), 'name' => trim($v)];
                    break;
                case 'wp:postmeta':
                    $key = '';
                    $value = '';
                    foreach ($c->childNodes as $m) {
                        if ($m instanceof DOMElement && $m->nodeName === 'wp:meta_key') {
                            $key = trim($m->textContent);
                        } elseif ($m instanceof DOMElement && $m->nodeName === 'wp:meta_value') {
                            $value = $m->textContent;
                        }
                    }
                    if ($key !== '') {
                        $out['meta'][$key] = $value;
                    }
                    break;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $it
     * @param array<int,array<string,mixed>> $byId
     * @param array<int,string> $attachments
     * @param array<int,string> $reusable
     * @param array<string,mixed> $ctx see Gutenberg::toElements()
     * @return array<string,mixed>
     */
    private static function page(array $it, array $byId, array $attachments, array $reusable, string $base, string $siteTitle, array $ctx = []): array
    {
        $kind = $it['type'] === 'post' ? 'post' : 'page';
        $link = $it['link'] !== '' ? $it['link'] : $base . ($kind === 'post' ? '?p=' : '?page_id=') . $it['id'];
        $path = Url::oldPath($link);
        $aliases = [$kind === 'post' ? '/?p=' . $it['id'] : '/?page_id=' . $it['id']];
        if ($it['guid'] !== '' && str_contains($it['guid'], '?') && Url::sameSite($it['guid'], $base)) {
            $aliases[] = Url::oldPath($it['guid']);
        }
        $parentPath = '';
        if ($it['parent'] > 0 && isset($byId[$it['parent']]) && $byId[$it['parent']]['link'] !== '') {
            $parentPath = Url::oldPath($byId[$it['parent']]['link']);
        }
        $notes = [];
        $content = $it['content'];
        if ($it['password'] !== '') {
            $content = '';
            $notes[] = ['what' => 'Текст ' . ($kind === 'post' ? 'записи' : 'страницы'), 'why' => 'защищён паролем в WordPress: перенесён только заголовок, текст добавьте вручную', 'source' => $path];
        }
        $elements = Gutenberg::toElements($content, $link, $attachments, $reusable, $notes, 0, $ctx);
        if (Gutenberg::isBuilder($content) || isset($it['meta']['_elementor_data'])) {
            $notes[] = ['what' => 'Страница собрана конструктором', 'why' => 'в выгрузке нет готовой вёрстки конструктора: перенесён текст; для точного переноса импортируйте сайт по адресу', 'source' => $path];
        }
        $meta = $it['meta'];
        $title = Neutral::line($it['title']);
        $vars = static fn(string $s): string => Neutral::line(preg_replace('~%%?[a-z_]+%%?~i', '', strtr($s, [
            '%%title%%' => $title, '%%sitename%%' => $siteTitle, '%%sep%%' => '-', '%%page%%' => '', '%title%' => $title, '%sitename%' => $siteTitle, '%sep%' => '-',
        ])) ?? '');
        $metaTitle = $vars((string)($meta['_yoast_wpseo_title'] ?? $meta['rank_math_title'] ?? $meta['_aioseo_title'] ?? ''));
        $metaDesc = $vars((string)($meta['_yoast_wpseo_metadesc'] ?? $meta['rank_math_description'] ?? $meta['_aioseo_description'] ?? ''));
        if ($metaDesc === '' && $it['excerpt'] !== '' && $it['password'] === '') {
            $metaDesc = mb_substr(Neutral::line(strip_tags($it['excerpt'])), 0, 300);
        }
        if ($metaDesc === '' && $it['password'] === '') {
            $metaDesc = self::autoExcerpt($elements);
        }
        $noindex = ($meta['_yoast_wpseo_meta-robots-noindex'] ?? '') === '1' || str_contains((string)($meta['rank_math_robots'] ?? ''), 'noindex');
        $thumb = isset($meta['_thumbnail_id']) ? ($attachments[(int)$meta['_thumbnail_id']] ?? '') : '';
        $thumbAlt = isset($meta['_thumbnail_id']) ? Neutral::line((string)($byId[(int)$meta['_thumbnail_id']]['meta']['_wp_attachment_image_alt'] ?? '')) : '';
        $og = (string)($meta['_yoast_wpseo_opengraph-image'] ?? $meta['rank_math_facebook_image'] ?? '');
        $elements = Gutenberg::postTop($elements, $kind, $title, $thumb, $thumbAlt, '_thumbnail_id');
        return Neutral::page([
            'source_url' => $link,
            'old_path' => $path,
            'aliases' => $aliases,
            'suggested_slug' => $it['name'],
            'parent_old_path' => $parentPath,
            'kind' => $kind,
            'title' => $title,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDesc,
            'canonical' => (string)($meta['_yoast_wpseo_canonical'] ?? ''),
            'og_image' => $og !== '' ? $og : $thumb,
            'published_at' => $kind === 'post' ? $it['date'] : '',
            'is_noindex' => $noindex,
            'elements' => $elements,
            'notes' => $notes,
            'wp_id' => $it['id'],
        ]);
    }

    /**
     * The description WordPress shows when the excerpt is empty: the start of the text (the REST API gives it as
     * excerpt, the export does not), cut on a word under 300 characters.
     *
     * @param list<array<string,mixed>> $elements
     */
    private static function autoExcerpt(array $elements): string
    {
        $text = '';
        foreach ($elements as $el) {
            if (($el['type'] ?? '') === 'paragraph' || ($el['type'] ?? '') === 'list') {
                $text .= ' ' . Neutral::line(Neutral::text(($el['type'] === 'list') ? implode('. ', array_map('strval', (array)$el['items'])) : (string)$el['html']));
            }
            if (mb_strlen($text) >= 300) {
                break;
            }
        }
        $text = trim($text);
        if (mb_strlen($text) <= 300) {
            return $text;
        }
        $cut = mb_substr($text, 0, 300);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > 150 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
    }

    /**
     * Menus of nav_menu_item: header is the menu named like main/primary/header (or the biggest), footer the one named like footer.
     *
     * @param list<array<string,mixed>> $items
     * @param array<int,array<string,mixed>> $byId
     * @return array{header:list<array{title:string,url:string}>,footer:list<array{title:string,url:string}>}
     */
    private static function menus(array $items, array $byId, string $base): array
    {
        $menus = [];
        foreach ($items as $it) {
            if ($it['type'] !== 'nav_menu_item') {
                continue;
            }
            $menu = '';
            foreach ($it['terms'] as $t) {
                if ($t['domain'] === 'nav_menu') {
                    $menu = $t['name'] !== '' ? $t['name'] : $t['slug'];
                }
            }
            if ($menu === '' || (int)($it['meta']['_menu_item_menu_item_parent'] ?? 0) !== 0) {
                continue;
            }
            $type = (string)($it['meta']['_menu_item_type'] ?? '');
            $objectId = (int)($it['meta']['_menu_item_object_id'] ?? 0);
            $object = (string)($it['meta']['_menu_item_object'] ?? '');
            $title = Neutral::line($it['title']);
            $url = '';
            if ($type === 'custom') {
                $url = (string)($it['meta']['_menu_item_url'] ?? '');
            } elseif ($type === 'post_type' && isset($byId[$objectId])) {
                $url = $byId[$objectId]['link'];
                if ($title === '') {
                    $title = Neutral::line($byId[$objectId]['title']);
                }
            } elseif ($type === 'taxonomy') {
                $url = $base . ($object === 'category' ? 'category/' : $object . '/') . $objectId;
            }
            if ($url === '' || $title === '') {
                continue;
            }
            $menus[$menu][] = ['order' => $it['order'], 'title' => mb_substr($title, 0, 60), 'url' => Url::resolve($base, $url)];
        }
        if ($menus === []) {
            return ['header' => [], 'footer' => []];
        }
        foreach ($menus as &$list) {
            usort($list, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
            $list = array_map(static fn(array $i): array => ['title' => $i['title'], 'url' => $i['url']], array_slice($list, 0, 30));
        }
        unset($list);
        $header = null;
        $footer = null;
        foreach (array_keys($menus) as $name) {
            if ($header === null && preg_match('~(main|primary|header|top|главн|основн|верх|шапк)~iu', (string)$name) === 1) {
                $header = $name;
            } elseif ($footer === null && preg_match('~(footer|bottom|подвал|низ|футер)~iu', (string)$name) === 1) {
                $footer = $name;
            }
        }
        if ($header === null) {
            $sizes = array_map('count', $menus);
            unset($sizes[$footer ?? '']);
            arsort($sizes);
            $header = array_key_first($sizes);
        }
        return ['header' => $header === null ? [] : $menus[$header], 'footer' => $footer === null || $footer === $header ? [] : $menus[$footer]];
    }
}
