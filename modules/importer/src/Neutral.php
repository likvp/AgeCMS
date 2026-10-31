<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

/**
 * The neutral format every extractor returns (described in README.md). Plain arrays, so a job can keep them as JSON.
 * Site {source_url, platform, title, pages[], menus{header[], footer[]}, assets[]}
 * Page {source_url, old_path, aliases[], suggested_slug, parent_old_path, kind, title, meta_title, meta_description,
 *       canonical, og_image, published_at, is_noindex, elements[], notes[]}
 * Element {type, ..., source}
 */
final class Neutral
{
    public const TYPES = ['heading', 'paragraph', 'list', 'image', 'gallery', 'quote', 'table', 'button', 'hero', 'faq', 'form', 'embed', 'raw'];

    /** @param array<string,mixed> $fields */
    public static function page(array $fields): array
    {
        $page = $fields + [
            'source_url' => '',
            'old_path' => '/',
            'aliases' => [],
            'suggested_slug' => '',
            'parent_old_path' => '',
            'kind' => 'page',
            'title' => '',
            'meta_title' => '',
            'meta_description' => '',
            'canonical' => '',
            'og_image' => '',
            'published_at' => '',
            'is_noindex' => false,
            'elements' => [],
            'notes' => [],
        ];
        $page['title'] = self::line((string)$page['title']);
        $page['meta_title'] = self::line((string)$page['meta_title']);
        $page['meta_description'] = self::line((string)$page['meta_description']);
        $page['kind'] = $page['kind'] === 'post' ? 'post' : 'page';
        $page['published_at'] = self::date((string)$page['published_at']);
        $page['aliases'] = array_values(array_unique(array_filter(array_map('strval', (array)$page['aliases']), static fn(string $a): bool => $a !== '' && $a !== $page['old_path'])));
        if ($page['title'] === '') {
            foreach ($page['elements'] as $el) {
                if (($el['type'] ?? '') === 'hero' && ($el['h1'] ?? '') !== '') {
                    $page['title'] = self::line((string)$el['h1']);
                    break;
                }
                if (($el['type'] ?? '') === 'heading') {
                    $page['title'] = self::line((string)$el['text']);
                    break;
                }
            }
        }
        return $page;
    }

    /** @param array<string,mixed> $fields */
    public static function site(array $fields): array
    {
        return $fields + ['source_url' => '', 'platform' => 'generic', 'title' => '', 'pages' => [], 'menus' => ['header' => [], 'footer' => []], 'assets' => []];
    }

    /** One element; unknown keys are kept, "source" always present. @param array<string,mixed> $fields */
    public static function el(string $type, array $fields, string $source = ''): array
    {
        return ['type' => $type] + $fields + ['source' => $source];
    }

    /** Collapses whitespace of a one-line value. */
    public static function line(string $s): string
    {
        $s = str_replace("\u{00A0}", ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim(preg_replace('~\s+~u', ' ', $s) ?? $s);
    }

    /** ISO date (YYYY-MM-DD or full) from what a CMS gives, or ''. */
    public static function date(string $s): string
    {
        $s = trim($s);
        if ($s === '' || str_starts_with($s, '0000')) {
            return '';
        }
        if (preg_match('~^(\d{2})\.(\d{2})\.(\d{4})~', $s, $m) === 1) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        $t = strtotime($s);
        if ($t === false || $t < 0) {
            return '';
        }
        return gmdate('Y-m-d', $t);
    }

    /** Plain text of an HTML fragment, paragraphs separated by blank lines. */
    public static function text(string $html): string
    {
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</(p|div|li|h[1-6]|tr|blockquote|figcaption|pre)>~i', "$0\n\n", $html) ?? $html;
        $html = preg_replace('~<li[^>]*>~i', '- ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('~[ \t]+~u', ' ', $text) ?? $text;
        $text = preg_replace('~ *\n *~u', "\n", $text) ?? $text;
        $text = preg_replace('~\n{3,}~u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** Short human description of an element for reports. */
    public static function describe(array $el): string
    {
        $cut = static fn(string $s, int $n = 60): string => mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
        return match ($el['type'] ?? '') {
            'heading' => 'заголовок «' . $cut((string)$el['text']) . '»',
            'paragraph' => 'абзац «' . $cut(self::text((string)$el['html'])) . '»',
            'list' => 'список из ' . count((array)$el['items']),
            'image' => 'картинка ' . $cut((string)$el['src'], 80),
            'gallery' => 'галерея из ' . count((array)$el['images']),
            'quote' => 'цитата',
            'table' => 'таблица',
            'button' => 'кнопка «' . $cut((string)$el['text']) . '»',
            'hero' => 'обложка «' . $cut((string)$el['h1']) . '»',
            'faq' => 'вопросы и ответы (' . count((array)$el['items']) . ')',
            'form' => 'форма',
            'embed' => ($el['kind'] ?? '') === 'video' ? 'видео ' . $cut((string)$el['src'], 80) : (($el['kind'] ?? '') === 'map' ? 'карта' : 'вставка ' . $cut((string)$el['src'], 80)),
            'raw' => 'не разобранный фрагмент',
            default => (string)($el['type'] ?? '?'),
        };
    }
}
