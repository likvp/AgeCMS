<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Html;

use AgeCMS\Modules\Importer\Url;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * White-list cleaner for the HTML the importer carries. It produces the same subset the core's richtext field takes
 * (RichText::sanitize), so a page looks the same whether the core sanitizer is there yet or not. Tags outside the list are
 * unwrapped (their text stays), dangerous ones are dropped with their content, attributes are reduced to href, src, alt,
 * colspan, rowspan and start, links are made absolute against the page they came from.
 */
final class Sanitizer
{
    public const RICH = ['p', 'br', 'h2', 'h3', 'h4', 'strong', 'em', 'u', 's', 'a', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'code', 'pre'];
    public const INLINE = ['strong', 'em', 'u', 's', 'a', 'br', 'code'];
    private const RENAME = ['b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's', 'ins' => 'u', 'h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4', 'tfoot' => 'tbody', 'kbd' => 'code', 'samp' => 'code', 'tt' => 'code'];
    private const DROP = ['script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed', 'svg', 'canvas', 'form', 'input', 'button', 'select', 'textarea', 'video', 'audio', 'map', 'area', 'head', 'title', 'meta', 'link', 'source', 'picture-source'];
    private const VOID = ['br', 'hr', 'img'];
    private const KEEP_EMPTY = ['br', 'hr', 'img', 'td', 'th'];

    /** Block-level HTML for an article body. */
    public static function rich(string $html, string $base = ''): string
    {
        return self::clean($html, self::RICH, $base);
    }

    /** Inline HTML for a paragraph or a list item. */
    public static function inline(string $html, string $base = ''): string
    {
        return self::clean($html, self::INLINE, $base);
    }

    /** @param list<string> $allowed */
    private static function clean(string $html, array $allowed, string $base): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = Dom::load('<div id="ag-import-root">' . $html . '</div>');
        $root = $doc->getElementById('ag-import-root');
        if ($root === null) {
            return '';
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= self::node($child, $allowed, $base);
        }
        $out = preg_replace('~(<br>\s*){3,}~', '<br><br>', $out) ?? $out;
        if (!in_array('p', $allowed, true)) {
            $out = preg_replace('~^(\s*<br>)+|(<br>\s*)+$~', '', trim($out)) ?? $out;
        }
        return trim($out);
    }

    /** @param list<string> $allowed */
    private static function node(DOMNode $node, array $allowed, string $base): string
    {
        if ($node instanceof DOMText) {
            $text = str_replace("\u{00A0}", ' ', $node->data);
            // Source indentation and line breaks inside a text mean nothing outside <pre>.
            $inPre = false;
            for ($p = $node->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                if (strtolower($p->nodeName) === 'pre') {
                    $inPre = true;
                    break;
                }
            }
            if (!$inPre) {
                $text = preg_replace('~[ \t\r\n]+~', ' ', $text) ?? $text;
            }
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (!$node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->nodeName);
        if ($tag === 'picture') {
            $img = null;
            foreach ($node->getElementsByTagName('img') as $i) {
                $img = $i;
                break;
            }
            return $img instanceof DOMElement ? self::node($img, $allowed, $base) : '';
        }
        if (in_array($tag, self::DROP, true)) {
            return '';
        }
        $tag = self::RENAME[$tag] ?? $tag;
        $inner = '';
        if (!in_array($tag, self::VOID, true)) {
            foreach ($node->childNodes as $child) {
                $inner .= self::node($child, $allowed, $base);
            }
        }
        if (!in_array($tag, $allowed, true)) {
            // Unknown container: keep its content. A block container in inline mode becomes a line break.
            if (in_array($tag, ['div', 'section', 'article', 'header', 'footer', 'aside', 'main', 'p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'tr'], true) && in_array('br', $allowed, true) && !in_array('p', $allowed, true)) {
                return trim($inner) === '' ? '' : $inner . '<br>';
            }
            if ($tag === 'img' && !in_array('img', $allowed, true)) {
                return '';
            }
            return $inner;
        }
        $attrs = self::attributes($node, $tag, $base);
        if ($attrs === null) {
            return $inner;
        }
        if (in_array($tag, self::VOID, true)) {
            return "<{$tag}{$attrs}>";
        }
        if (trim(strip_tags($inner, '<img><br><hr>')) === '' && !in_array($tag, self::KEEP_EMPTY, true) && !str_contains($inner, '<img')) {
            return '';
        }
        return "<{$tag}{$attrs}>{$inner}</{$tag}>";
    }

    /** Allowed attributes as a string, or null when the tag must be unwrapped (a link without a usable address). */
    private static function attributes(DOMElement $node, string $tag, string $base): ?string
    {
        $out = '';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            $href = $base !== '' && $href !== '' && !str_starts_with($href, '#') ? Url::resolve($base, $href) : $href;
            $href = preg_match('~^(https?:)?/~i', $href) === 1 ? Dom::encodeUrl($href) : $href;
            if (stripos($href, 'tel:') === 0) {
                $href = 'tel:' . preg_replace('~[^+0-9]~', '', substr($href, 4));
            }
            if ($href === '' || !self::safeUrl($href, true)) {
                return null;
            }
            $out .= ' href="' . self::attr($href) . '"';
            if (($title = trim($node->getAttribute('title'))) !== '') {
                $out .= ' title="' . self::attr(mb_substr($title, 0, 200)) . '"';
            }
        } elseif ($tag === 'img') {
            $src = Dom::imageSrc($node);
            $src = $base !== '' && $src !== '' ? Url::resolve($base, $src) : $src;
            $src = preg_match('~^(https?:)?/~i', $src) === 1 ? Dom::encodeUrl($src) : $src;
            // SVG files do not go to the media library (they can carry scripts): such an icon gives its alt text or nothing.
            if ($src === '' || !self::safeUrl($src, false) || Dom::isTinyImage($node) || preg_match('~(\.svgz?|/)$~i', (string)parse_url($src, PHP_URL_PATH)) === 1) {
                return null;
            }
            $out .= ' src="' . self::attr($src) . '" alt="' . self::attr(mb_substr(trim($node->getAttribute('alt')), 0, 200)) . '"';
            // The size a small picture is shown at (set by the importer for icons and thumbnails) stays.
            foreach (['width', 'height'] as $size) {
                $v = trim($node->getAttribute($size));
                if (preg_match('/^[1-9][0-9]{0,3}$/', $v) === 1) {
                    $out .= " {$size}=\"{$v}\"";
                }
            }
        } elseif ($tag === 'td' || $tag === 'th') {
            foreach (['colspan', 'rowspan'] as $a) {
                $v = (int)$node->getAttribute($a);
                if ($v > 1 && $v < 100) {
                    $out .= " {$a}=\"{$v}\"";
                }
            }
        } elseif ($tag === 'ol') {
            $v = (int)$node->getAttribute('start');
            if ($v > 1) {
                $out .= " start=\"{$v}\"";
            }
        }
        return $out;
    }

    /** Links may be http(s), site paths, anchors, mailto: and tel:; images only http(s), site paths and local: export files. */
    public static function safeUrl(string $url, bool $link): bool
    {
        if (preg_match('~[\s<>"\'\\\\]~', $url) === 1) {
            return false;
        }
        if (preg_match('~^https?://~i', $url) === 1 || (str_starts_with($url, '/') && !str_starts_with($url, '//'))) {
            return true;
        }
        if (!$link) {
            return str_starts_with($url, 'local:');
        }
        return str_starts_with($url, '#') || preg_match('~^(mailto:[^\s]+|tel:[+0-9()\-]+)$~i', $url) === 1;
    }

    private static function attr(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
