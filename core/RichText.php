<?php
declare(strict_types=1);

namespace AgeCMS;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * HTML of a "richtext" field: an article body, a post imported from another CMS. Everything goes through a white list
 * of tags and attributes, the rest is unwrapped to its text; script, style, iframe, svg and the like go away with their
 * content. The result is rebuilt from scratch, never patched, so nothing the list does not know can slip through.
 * Plain text without tags becomes paragraphs (an empty line starts a new one). Sanitizing twice gives the same string.
 */
final class RichText
{
    /** Tag => attributes it may keep. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'a' => ['href', 'title', 'rel'], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'figure' => [], 'figcaption' => [],
        'img' => ['src', 'alt', 'width', 'height'], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'], 'hr' => [], 'code' => [], 'pre' => [],
    ];
    /** Removed together with everything inside. */
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'noembed', 'noframes', 'template', 'svg', 'math',
        'head', 'title', 'meta', 'link', 'base', 'form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea', 'datalist', 'output',
        'canvas', 'audio', 'video', 'source', 'track', 'picture', 'map', 'area', 'dialog', 'slot', 'portal', 'xml', 'param',
    ];
    /** Renamed: the page has its own h1, a smaller heading keeps its meaning. */
    private const RENAME = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4', 'strike' => 's', 'del' => 's', 'ins' => 'u', 'tt' => 'code', 'kbd' => 'code', 'samp' => 'code'];
    /** Containers of other CMS (div of Tilda, section of WordPress blocks): a paragraph when they hold only text, unwrapped otherwise. */
    private const BLOCKISH = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'address', 'center', 'details', 'summary', 'fieldset', 'legend',
        'dl', 'dt', 'dd', 'caption', 'hgroup',
    ];
    /** Tags that start a new block. */
    private const BLOCKS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'pre'];
    /** Tags that may hold only text and inline tags. */
    private const INLINE_ONLY = ['p', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'code', 'figcaption', 'pre'];
    /** Tags that stay even when empty. */
    private const KEEP_EMPTY = ['br', 'hr', 'img', 'td', 'th'];
    private const REL = ['nofollow', 'noopener', 'noreferrer', 'ugc', 'sponsored'];
    private const MAX_DEPTH = 40;
    private const MAX_URL = 2000;

    /** Clean HTML for a richtext field. Empty string when nothing worth keeping is left. */
    public static function sanitize(string $html): string
    {
        $html = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], mb_scrub($html, 'UTF-8'));
        if (trim($html) === '') {
            return '';
        }
        if (!class_exists(DOMDocument::class)) {
            // Without the dom extension (php-xml) the safe answer is the text alone.
            return self::fromText(preg_match('~<[a-zA-Z!/?]~', $html) === 1 ? html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $html);
        }
        if (preg_match('~<[a-zA-Z!/?]~', $html) !== 1) {
            // Plain text goes through the same serializer below, so a second pass gives the same string.
            $html = self::fromText($html);
        }

        // A "<" that cannot open a tag is text in HTML5; the libxml parser would cut the rest of the text there.
        $html = preg_replace('~<(?![a-zA-Z/!?])~', '&lt;', $html) ?? '';
        // Void tags the HTML4 parser of libxml does not know as empty (embed, source, ...) would swallow all the text after them;
        // closing them explicitly keeps the rest. The attribute part skips quoted values, so a ">" inside one does not end the tag.
        $html = preg_replace('~<(embed|source|track|wbr|param|keygen)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)\s*/?>~i', '<$1$2 />', $html) ?? '';
        // PARSEHUGE lifts the nesting limit of 256 levels, past which libxml would drop the text; walk() flattens deep nesting itself.
        $in = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $in->loadHTML('<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $out = new DOMDocument('1.0', 'UTF-8');
        $root = $out->createElement('div');
        $out->appendChild($root);
        $body = $in->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            self::walk($body, $root, 0);
        }
        self::settle($root);
        self::wrapInline($root);
        self::dropEmpty($root);

        $parts = [];
        foreach ($root->childNodes as $node) {
            $parts[] = $out->saveHTML($node);
        }
        return trim(implode("\n", $parts));
    }

    /** Plain text: paragraphs split by an empty line, single line breaks kept as <br>. */
    private static function fromText(string $text): string
    {
        $out = [];
        foreach (preg_split('/\n[ \t\x{00A0}]*\n\s*/u', trim($text)) ?: [] as $para) {
            $para = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $para) ?? '');
            if ($para !== '') {
                $out[] = '<p>' . str_replace("\n", '<br>', htmlspecialchars($para, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
            }
        }
        return implode("\n", $out);
    }

    /** Copies the allowed part of $from's children into $to. */
    private static function walk(DOMNode $from, DOMElement $to, int $depth): void
    {
        foreach ($from->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                self::text($to, (string)$node->nodeValue);
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue; // comments, processing instructions
            }
            $tag = strtolower($node->nodeName);
            if (in_array($tag, self::DROP, true) || str_contains($tag, ':')) {
                continue;
            }
            $tag = self::RENAME[$tag] ?? $tag;
            // A pasted Google Docs text sits in <b style="font-weight:normal">: that is not bold.
            if ($tag === 'b' && preg_match('/font-weight\s*:\s*(normal|400)/i', $node->getAttribute('style')) === 1) {
                $tag = 'span';
            }
            if ($depth >= self::MAX_DEPTH || !self::fits($tag, $to)) {
                // A list item or a cell out of place still is a block of its own, not text glued to its neighbour.
                if ($depth < self::MAX_DEPTH && in_array($tag, ['li', 'figcaption', 'tr', 'th', 'td', 'thead', 'tbody'], true)
                    && !in_array($to->tagName, array_merge(self::INLINE_ONLY, ['table', 'thead', 'tbody', 'tr', 'ul', 'ol']), true)) {
                    $el = $to->ownerDocument->createElement('div');
                    $to->appendChild($el);
                    self::walk($node, $el, $depth + 1);
                    continue;
                }
                self::walk($node, $to, $depth + 1);
                continue;
            }
            if (isset(self::ALLOWED[$tag])) {
                $el = $to->ownerDocument->createElement($tag);
                if (!self::attributes($node, $el, $tag)) {
                    // A link without a usable address stays as text, a picture without one goes away.
                    if ($tag === 'a') {
                        self::walk($node, $to, $depth + 1);
                    }
                    continue;
                }
                $to->appendChild($el);
                if ($tag !== 'img' && $tag !== 'br' && $tag !== 'hr') {
                    self::walk($node, $el, $depth + 1);
                }
                continue;
            }
            if (in_array($tag, self::BLOCKISH, true) && !in_array($to->tagName, self::INLINE_ONLY, true)) {
                // Becomes a paragraph or is unwrapped in settle(), when its content is known.
                $el = $to->ownerDocument->createElement('div');
                $to->appendChild($el);
                self::walk($node, $el, $depth + 1);
                continue;
            }
            self::walk($node, $to, $depth + 1);
        }
    }

    /** Whether $tag may stand inside $parent; otherwise its content goes to the parent. */
    private static function fits(string $tag, DOMElement $parent): bool
    {
        $in = $parent->tagName;
        if ($tag === 'li') {
            return $in === 'ul' || $in === 'ol';
        }
        if ($tag === 'figcaption') {
            return $in === 'figure';
        }
        if ($tag === 'thead' || $tag === 'tbody') {
            return $in === 'table';
        }
        if ($tag === 'tr') {
            return in_array($in, ['table', 'thead', 'tbody'], true);
        }
        if ($tag === 'th' || $tag === 'td') {
            return $in === 'tr';
        }
        if (in_array($in, ['table', 'thead', 'tbody', 'tr'], true)) {
            return false; // only rows and cells there, see the checks above
        }
        if (($in === 'ul' || $in === 'ol') && $tag !== 'ul' && $tag !== 'ol') {
            return false; // a list holds items (and nested lists in pasted HTML)
        }
        if ($tag === 'a' && self::inside($parent, 'a')) {
            return false;
        }
        return !(in_array($in, self::INLINE_ONLY, true) && in_array($tag, self::BLOCKS, true));
    }

    private static function inside(DOMElement $el, string $tag): bool
    {
        for ($n = $el; $n instanceof DOMElement; $n = $n->parentNode) {
            if ($n->tagName === $tag) {
                return true;
            }
        }
        return false;
    }

    private static function text(DOMElement $to, string $text): void
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        if ($text === '') {
            return;
        }
        // Text has no place between rows and cells, and a list holds only items.
        if (in_array($to->tagName, ['table', 'thead', 'tbody', 'tr'], true)) {
            return;
        }
        if (($to->tagName === 'ul' || $to->tagName === 'ol')) {
            if (trim($text, " \t\n\u{00A0}") === '') {
                return;
            }
            $li = $to->ownerDocument->createElement('li');
            $li->appendChild($to->ownerDocument->createTextNode($text));
            $to->appendChild($li);
            return;
        }
        $to->appendChild($to->ownerDocument->createTextNode($text));
    }

    /** Copies the allowed attributes. False when a required one (href, src) is missing or unsafe. */
    private static function attributes(DOMElement $from, DOMElement $to, string $tag): bool
    {
        if ($tag === 'a') {
            $href = self::href($from->getAttribute('href'));
            if ($href === null) {
                return false;
            }
            $to->setAttribute('href', $href);
            $title = self::plain($from->getAttribute('title'), 300);
            if ($title !== '') {
                $to->setAttribute('title', $title);
            }
            $rel = array_values(array_intersect(self::REL, preg_split('/\s+/', strtolower($from->getAttribute('rel'))) ?: []));
            if ($rel !== []) {
                $to->setAttribute('rel', implode(' ', $rel));
            }
            return true;
        }
        if ($tag === 'img') {
            $src = self::src($from->getAttribute('src'));
            if ($src === null) {
                return false;
            }
            $to->setAttribute('src', $src);
            $to->setAttribute('alt', self::plain($from->getAttribute('alt'), 500));
            foreach (['width', 'height'] as $size) {
                $value = trim($from->getAttribute($size));
                if (preg_match('/^[1-9][0-9]{0,4}$/', $value) === 1) {
                    $to->setAttribute($size, $value);
                }
            }
            return true;
        }
        foreach (self::ALLOWED[$tag] as $name) {
            $value = trim($from->getAttribute($name));
            if (($name === 'colspan' || $name === 'rowspan') && preg_match('/^[1-9][0-9]?$/', $value) === 1 && $value !== '1') {
                $to->setAttribute($name, $value);
            }
        }
        return true;
    }

    /**
     * An address a link may have: http(s), mailto:, tel:, a path on the site, a relative path, ?query or #anchor.
     * Browsers drop tabs and line breaks inside a URL, so "java\tscript:" is checked as "javascript:".
     */
    public static function href(string $value): ?string
    {
        $url = self::url($value);
        if ($url === null) {
            return null;
        }
        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $url, $m) === 1) {
            $scheme = strtolower($m[1]);
            if ($scheme === 'http' || $scheme === 'https') {
                return preg_match('~^https?://[^/?#\s]+~i', $url) === 1 ? $url : null;
            }
            if ($scheme === 'mailto') {
                return preg_match('~^mailto:[^\s<>"]+$~i', $url) === 1 ? $url : null;
            }
            if ($scheme === 'tel') {
                $url = str_replace('%20', '', $url);
                return preg_match('~^tel:[+0-9()\-.]{2,40}$~i', $url) === 1 ? $url : null;
            }
            return null;
        }
        return $url;
    }

    /** A picture: https or a path on the site. data: and http: are refused (the page policy would block them anyway). */
    public static function src(string $value): ?string
    {
        $url = self::url($value);
        if ($url === null) {
            return null;
        }
        return preg_match('~^https://[^/?#\s]+~i', $url) === 1 || ($url[0] === '/' && preg_match('~^/[^/]~', $url) === 1) ? $url : null;
    }

    private static function url(string $value): ?string
    {
        $url = trim($value, " \t\n\r\0\x0B\x0C\u{00A0}");
        $url = preg_replace('/[\t\n\r]/', '', $url) ?? '';
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\x00-\x1F\x7F<>"\\\\]/', $url) === 1) {
            return null;
        }
        // //host would leave the site without saying so, and /\host is read the same way by browsers.
        if (str_starts_with($url, '//')) {
            return null;
        }
        return str_replace(' ', '%20', $url);
    }

    private static function plain(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, $max);
    }

    /** Containers from other CMS: a paragraph when they hold only inline content, otherwise their children take their place. */
    private static function settle(DOMElement $el): void
    {
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::settle($child);
            }
        }
        foreach (iterator_to_array($el->childNodes) as $child) {
            if (!$child instanceof DOMElement || $child->tagName !== 'div') {
                continue;
            }
            $block = false;
            foreach ($child->childNodes as $grand) {
                if ($grand instanceof DOMElement && in_array($grand->tagName, self::BLOCKS, true)) {
                    $block = true;
                    break;
                }
            }
            if ($block || $el->tagName === 'li' || $el->tagName === 'td' || $el->tagName === 'th' || $el->tagName === 'blockquote' || $el->tagName === 'figure') {
                // Inside an item or a cell a separate paragraph per container is too much: keep the text, break the line.
                $inline = !$block;
                while ($child->firstChild !== null) {
                    $el->insertBefore($child->firstChild, $child);
                }
                if ($inline && $child->nextSibling !== null) {
                    $el->insertBefore($el->ownerDocument->createElement('br'), $child);
                }
                $el->removeChild($child);
                continue;
            }
            $p = $el->ownerDocument->createElement('p');
            while ($child->firstChild !== null) {
                $p->appendChild($child->firstChild);
            }
            $el->replaceChild($p, $child);
        }
    }

    /** Text and inline tags right at the top level go into paragraphs. */
    private static function wrapInline(DOMElement $root): void
    {
        $run = null;
        foreach (iterator_to_array($root->childNodes) as $node) {
            $block = $node instanceof DOMElement && in_array($node->tagName, self::BLOCKS, true);
            if ($block) {
                $run = null;
                continue;
            }
            if ($run === null) {
                if ($node->nodeType === XML_TEXT_NODE && trim((string)$node->nodeValue, " \t\n\u{00A0}") === '') {
                    $root->removeChild($node);
                    continue;
                }
                $run = $root->ownerDocument->createElement('p');
                $root->insertBefore($run, $node);
            }
            $run->appendChild($node);
        }
    }

    /** Removes elements with nothing visible inside (a <p>&nbsp;</p> of WordPress, <p><br></p> of an editor), then empty rows and tables. */
    private static function dropEmpty(DOMElement $el): void
    {
        foreach (iterator_to_array($el->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            self::dropEmpty($child);
            if (in_array($child->tagName, self::KEEP_EMPTY, true)) {
                continue;
            }
            if (self::blank($child)) {
                $el->removeChild($child);
            }
        }
        // Whitespace between the blocks of a list, a table or the top level carries nothing.
        if (in_array($el->tagName, ['div', 'ul', 'ol', 'table', 'thead', 'tbody', 'tr'], true)) {
            foreach (iterator_to_array($el->childNodes) as $child) {
                if ($child->nodeType === XML_TEXT_NODE && trim((string)$child->nodeValue, " \t\n\u{00A0}") === '') {
                    $el->removeChild($child);
                }
            }
        }
    }

    private static function blank(DOMElement $el): bool
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($child->tagName !== 'br') {
                    return false;
                }
            } elseif (trim((string)$child->nodeValue, " \t\n\u{00A0}") !== '') {
                return false;
            }
        }
        return true;
    }
}
