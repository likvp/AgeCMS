<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** DOM helpers on top of ext-dom (libxml HTML parser, works on PHP 8.1). */
final class Dom
{
    /** Parses an HTML page or fragment (UTF-8). A fragment is wrapped in <body>. */
    public static function load(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // libxml stops at a NUL byte and chokes on other C0 controls (Wix pages carry them inside attributes).
        $html = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', '', $html) ?? $html;
        // Everything outside ASCII as entities: libxml then never guesses the encoding.
        $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        if (stripos(substr($encoded, 0, 2000), '<body') === false && stripos(substr($encoded, 0, 2000), '<html') === false) {
            $encoded = '<!DOCTYPE html><html><head></head><body>' . $encoded . '</body></html>';
        }
        $doc->loadHTML($encoded, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING | (defined('LIBXML_PARSEHUGE') ? LIBXML_PARSEHUGE : 0));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        return new DOMXPath($doc);
    }

    /** XPath condition for "has class $c" on the context node. */
    public static function cls(string $c): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$c} ')";
    }

    public static function hasClass(DOMNode $node, string $class): bool
    {
        return $node instanceof DOMElement && in_array($class, self::classes($node), true);
    }

    /** @return list<string> */
    public static function classes(DOMElement $node): array
    {
        $c = trim($node->getAttribute('class'));
        return $c === '' ? [] : (preg_split('~\s+~', $c) ?: []);
    }

    /** True when some class of the node matches the regex. */
    public static function classMatches(DOMNode $node, string $regex): bool
    {
        if (!$node instanceof DOMElement) {
            return false;
        }
        foreach (self::classes($node) as $c) {
            if (preg_match($regex, $c) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Visible text, whitespace collapsed, zero-width characters dropped. */
    public static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        return self::clean($node->textContent);
    }

    /** Collapses whitespace and drops zero-width characters and soft hyphens of a text. */
    public static function clean(string $t): string
    {
        $t = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}", "\u{00AD}"], [' ', '', '', '', '', '', ''], $t);
        return trim(preg_replace('~\s+~u', ' ', $t) ?? $t);
    }

    /** Text a reader sees: like text(), without scripts, styles and SVG inside the node (icons of menu links carry CSS). */
    public static function visibleText(DOMNode $node): string
    {
        if (!$node instanceof DOMElement) {
            return self::clean($node->textContent);
        }
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (in_array(strtolower($child->nodeName), ['script', 'style', 'svg', 'noscript', 'template'], true)) {
                    continue;
                }
                $out .= ' ' . self::visibleText($child) . ' ';
            } elseif ($child instanceof \DOMText) {
                $out .= $child->data;
            }
        }
        return self::clean($out);
    }

    /** Percent-encodes bytes outside ASCII and spaces of an address: parse_url() mangles raw UTF-8 (bytes 0x80-0x9F). */
    public static function encodeUrl(string $url): string
    {
        return preg_replace_callback('~[^\x21-\x7E]+~', static fn(array $m): string => rawurlencode($m[0]), trim($url)) ?? $url;
    }

    /** Text with <br> and block ends kept as line breaks. */
    public static function multilineText(DOMNode $node): string
    {
        return \AgeCMS\Modules\Importer\Neutral::text(self::innerHtml($node));
    }

    public static function innerHtml(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $node->ownerDocument?->saveHTML($child) ?? '';
        }
        return $out;
    }

    public static function outerHtml(DOMNode $node): string
    {
        return $node->ownerDocument?->saveHTML($node) ?? '';
    }

    public static function first(DOMXPath $xp, string $query, ?DOMNode $context = null): ?DOMElement
    {
        $list = $context === null ? @$xp->query($query) : @$xp->query($query, $context);
        if ($list === false) {
            return null;
        }
        foreach ($list as $n) {
            if ($n instanceof DOMElement) {
                return $n;
            }
        }
        return null;
    }

    /** @return list<DOMElement> */
    public static function all(DOMXPath $xp, string $query, ?DOMNode $context = null): array
    {
        $list = $context === null ? @$xp->query($query) : @$xp->query($query, $context);
        $out = [];
        if ($list !== false) {
            foreach ($list as $n) {
                if ($n instanceof DOMElement) {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }

    public static function meta(DOMXPath $xp, string $name): string
    {
        $n = self::first($xp, "//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$name}' or translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$name}']");
        return $n === null ? '' : trim($n->getAttribute('content'));
    }

    public static function remove(DOMNode $node): void
    {
        $node->parentNode?->removeChild($node);
    }

    /** Short label of where a node sits, for reports: "main > div.entry-content > p". */
    public static function label(DOMNode $node, int $depth = 3): string
    {
        $parts = [];
        for ($n = $node; $n instanceof DOMElement && $depth > 0 && $n->nodeName !== 'body' && $n->nodeName !== 'html'; $n = $n->parentNode, $depth--) {
            $p = strtolower($n->nodeName);
            if ($n->getAttribute('id') !== '' && strlen($n->getAttribute('id')) < 30) {
                $p .= '#' . $n->getAttribute('id');
            } else {
                $c = self::classes($n);
                if ($c !== []) {
                    $p .= '.' . $c[0];
                }
            }
            array_unshift($parts, $p);
        }
        return implode(' > ', $parts);
    }

    /**
     * The best address of an image element: lazy-load attributes first (data-src, data-original, data-lazy-src),
     * then the largest srcset candidate, then src. Placeholders (data: URIs, 1x1 gifs) are skipped. Inside <picture> the
     * <source> candidates count too, and the one with the biggest size in its address wins (Bitrix resize_cache keeps
     * a small thumbnail in src). Wix media addresses lose their resize part (/v1/fill/...) to give the original file.
     */
    public static function imageSrc(DOMElement $img): string
    {
        $src = self::ownImageSrc($img);
        $picture = null;
        for ($n = $img->parentNode, $i = 0; $n instanceof DOMElement && $i < 4; $n = $n->parentNode, $i++) {
            if (strtolower($n->nodeName) === 'picture') {
                $picture = $n;
                break;
            }
        }
        if ($picture !== null) {
            $best = $src;
            $bestArea = self::sizeHint($src);
            foreach ($picture->getElementsByTagName('source') as $source) {
                foreach (['srcset', 'data-srcset', 'data-lazyload', 'data-src'] as $attr) {
                    $cand = self::largestFromSrcset($source->getAttribute($attr));
                    if ($cand === '') {
                        continue;
                    }
                    $area = self::sizeHint($cand);
                    if ($best === '' || $area > $bestArea) {
                        $best = $cand;
                        $bestArea = $area;
                    }
                }
            }
            $src = $best;
        }
        return self::originalImage($src);
    }

    private static function ownImageSrc(DOMElement $img): string
    {
        foreach (['data-original', 'data-src', 'data-lazy-src', 'data-lazy', 'data-lazyload', 'data-full-url', 'data-orig-file', 'data-large-file', 'data-img-src'] as $attr) {
            $v = trim($img->getAttribute($attr));
            if ($v !== '' && !str_starts_with($v, 'data:')) {
                return $v;
            }
        }
        foreach (['srcset', 'data-srcset', 'data-lazy-srcset'] as $attr) {
            $best = self::largestFromSrcset($img->getAttribute($attr));
            if ($best !== '') {
                return $best;
            }
        }
        $src = trim($img->getAttribute('src'));
        if ($src === '' || str_starts_with($src, 'data:') || preg_match('~(blank|spacer|pixel|1x1|transparent)\.(gif|png)~i', $src) === 1) {
            return '';
        }
        return $src;
    }

    /** Original file behind a resized copy of a hosted builder: Wix keeps it at /media/<id> without the /v1/... tail. */
    public static function originalImage(string $src): string
    {
        if (preg_match('~^(https?://static\.wixstatic\.com/media/[^/?#]+\.(?:jpe?g|png|webp|gif))/v1/~i', $src, $m) === 1) {
            return $m[1];
        }
        return $src;
    }

    /**
     * Width x height an address tells about itself, 0 when it tells nothing: /resize_cache/.../1400_700_2/ (Bitrix),
     * /v1/fill/w_328,h_229 (Wix), name-300x200.jpg (WordPress and phpThumb).
     */
    public static function sizeHint(string $src): int
    {
        $path = (string)parse_url(self::encodeUrl($src), PHP_URL_PATH);
        if (preg_match('~/resize_cache/.*/(\d{1,4})_(\d{1,4})_\d+[^/]*/[^/]+$~', $path, $m) === 1
            || preg_match('~/w_(\d{1,4}),h_(\d{1,4})~', $path, $m) === 1
            || preg_match('~-(\d{2,4})x(\d{2,4})(-[a-z0-9]{1,6})?\.(jpe?g|png|webp|gif|avif)$~i', $path, $m) === 1) {
            return (int)$m[1] * (int)$m[2];
        }
        return 0;
    }

    /**
     * The largest candidate of a srcset. Parsed the way browsers do: an address runs to the next whitespace (Wix
     * addresses carry commas: /v1/fill/w_656,h_458,al_c/...), then an optional descriptor, then a comma.
     */
    public static function largestFromSrcset(string $srcset): string
    {
        $best = '';
        $bestW = -1.0;
        $len = strlen($srcset);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && (ctype_space($srcset[$i]) || $srcset[$i] === ',')) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $start = $i;
            while ($i < $len && !ctype_space($srcset[$i])) {
                $i++;
            }
            $url = substr($srcset, $start, $i - $start);
            $descriptor = '';
            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
            } else {
                $start = $i;
                while ($i < $len && $srcset[$i] !== ',') {
                    $i++;
                }
                $descriptor = trim(substr($srcset, $start, $i - $start));
            }
            if ($url === '' || str_starts_with($url, 'data:')) {
                continue;
            }
            $w = 1.0;
            if (preg_match('~^([\d.]+)[wx]$~', $descriptor, $m) === 1) {
                $w = (float)$m[1];
            }
            if ($w > $bestW) {
                $bestW = $w;
                $best = $url;
            }
        }
        return $best;
    }

    /** URL inside a style="background-image: url(...)" attribute. */
    public static function backgroundImage(DOMElement $node): string
    {
        if (preg_match('~url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)~i', $node->getAttribute('style'), $m) === 1 && !str_starts_with($m[1], 'data:')) {
            return trim($m[1]);
        }
        return '';
    }

    /** True for tiny images that are icons, counters or slider thumbnails (by attributes or by the size in the address). */
    public static function isTinyImage(DOMElement $img): bool
    {
        $w = (int)$img->getAttribute('width');
        $h = (int)$img->getAttribute('height');
        if (($w > 0 && $w < 40) || ($h > 0 && $h < 40)) {
            return true;
        }
        $hint = self::sizeHint(self::imageSrc($img));
        return $hint > 0 && $hint < 80 * 80;
    }
}
