<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Html;

use AgeCMS\Modules\Importer\Neutral;
use AgeCMS\Modules\Importer\Url;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Turns a content node into neutral elements in document order: headings, paragraphs, lists, images, galleries,
 * quotes, tables, buttons, FAQ (details, dl, accordions, schema.org FAQPage), forms, embeds. Text that sits loose in
 * containers becomes paragraphs. A hook can take over a node first (page builders, Gutenberg classes, Tilda).
 */
final class ElementParser
{
    private const BLOCK = ['p', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'table', 'blockquote', 'figure', 'img', 'picture', 'iframe', 'video', 'form', 'details', 'pre', 'hr', 'center', 'address', 'fieldset', 'object', 'embed'];
    private const INLINE = ['a', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'span', 'code', 'small', 'big', 'sup', 'sub', 'mark', 'abbr', 'time', 'label', 'font', 'q', 'cite', 'kbd', 'br', 'tt', 'var', 'dfn', 'nobr', 'wbr'];
    private const SKIP = ['script', 'style', 'noscript', 'template', 'svg', 'canvas', 'button', 'input', 'select', 'textarea', 'map', 'link', 'meta', 'hr', 'dialog'];

    /** @var list<array<string,mixed>> */
    private array $out = [];
    private string $inline = '';
    private ?DOMNode $inlineFrom = null;
    private DOMXPath $xp;
    /** @var (callable(DOMElement, ElementParser): ?list<array<string,mixed>>)|null */
    private $hook;

    /** @param (callable(DOMElement, ElementParser): ?list<array<string,mixed>>)|null $hook returns elements for a node it handles, null otherwise */
    public function __construct(private string $base, ?callable $hook = null)
    {
        $this->hook = $hook;
    }

    public function base(): string
    {
        return $this->base;
    }

    /** @return list<array<string,mixed>> */
    public function parse(DOMElement $root): array
    {
        $this->out = [];
        $this->inline = '';
        $this->xp = Dom::xpath($root->ownerDocument);
        if ($this->hook !== null && ($handled = ($this->hook)($root, $this)) !== null) {
            return self::tidy($handled);
        }
        $this->children($root);
        $this->flush();
        return self::tidy($this->out);
    }

    /** Elements of the node itself (not only its children): a form, a table, a figure. @return list<array<string,mixed>> */
    public function parseNode(DOMElement $node): array
    {
        $this->out = [];
        $this->inline = '';
        $this->xp = Dom::xpath($node->ownerDocument);
        $this->visit($node);
        $this->flush();
        return self::tidy($this->out);
    }

    /** Elements of the children of a node, for hooks that handle a wrapper and want the inside parsed as usual. @return list<array<string,mixed>> */
    public function parseChildren(DOMElement $node): array
    {
        $sub = new self($this->base, $this->hook);
        $sub->xp = Dom::xpath($node->ownerDocument);
        $sub->children($node);
        $sub->flush();
        return $sub->out;
    }

    private function children(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->visit($child);
        }
    }

    private function visit(DOMNode $n): void
    {
        if ($n instanceof DOMText) {
            if (trim(str_replace("\u{00A0}", ' ', $n->data)) !== '' || $this->inline !== '') {
                $this->inline .= htmlspecialchars($n->data, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                $this->inlineFrom ??= $n->parentNode;
            }
            return;
        }
        if (!$n instanceof DOMElement) {
            return;
        }
        $tag = strtolower($n->nodeName);
        if (in_array($tag, self::SKIP, true)) {
            $this->flush();
            return;
        }
        if ($this->hook !== null) {
            $handled = ($this->hook)($n, $this);
            if ($handled !== null) {
                $this->flush();
                foreach ($handled as $el) {
                    $this->out[] = $el;
                }
                return;
            }
        }
        $src = Dom::label($n);
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $this->flush();
                $text = Dom::text($n);
                // A paragraph styled as a heading (site builders pick h5 for body text): a long text, a sentence with a
                // full stop, a long line in the smallest levels.
                $words = count(preg_split('~\s+~u', $text) ?: []);
                if (mb_strlen($text) > 200 || (str_ends_with($text, '.') && $words >= 3 && !str_ends_with($text, '...')) || ((int)$tag[1] >= 5 && mb_strlen($text) > 60)) {
                    $this->paragraph($n);
                    return;
                }
                if ($text !== '') {
                    $this->out[] = Neutral::el('heading', ['level' => (int)$tag[1], 'text' => $text], $src);
                }
                foreach (Dom::all($this->xp, './/img', $n) as $img) {
                    $this->image($img);
                }
                return;
            case 'p':
                $this->flush();
                $this->paragraph($n);
                return;
            case 'ul': case 'ol':
                $this->flush();
                $this->list($n, $tag === 'ol');
                return;
            case 'img':
                $this->flush();
                $this->image($n);
                return;
            case 'picture':
                $this->flush();
                if (($img = Dom::first($this->xp, './/img', $n)) !== null) {
                    $this->image($img);
                }
                return;
            case 'figure':
                $this->flush();
                $this->figure($n);
                return;
            case 'blockquote':
                $this->flush();
                $cite = Dom::first($this->xp, './/cite|.//footer', $n);
                // The signature goes to "cite" only, not twice (in the text and under it).
                $body = $n->cloneNode(true);
                if ($cite !== null && $body instanceof DOMElement) {
                    foreach ((new \DOMXPath($n->ownerDocument))->query('.//cite|.//footer', $body) ?: [] as $c) {
                        $c->parentNode?->removeChild($c);
                        break;
                    }
                }
                $html = Sanitizer::inline(Dom::innerHtml($body), $this->base);
                if (trim(strip_tags($html)) !== '') {
                    $this->out[] = Neutral::el('quote', ['html' => $html, 'cite' => $cite === null ? '' : Dom::text($cite)], $src);
                }
                return;
            case 'table':
                $this->flush();
                if (self::isLayoutTable($n, $this->xp)) {
                    $this->children($n);
                    $this->flush();
                } else {
                    $html = Sanitizer::rich(Dom::outerHtml($n), $this->base);
                    if (trim(strip_tags($html)) !== '') {
                        $this->out[] = Neutral::el('table', ['html' => $html], $src);
                    }
                }
                return;
            case 'tbody': case 'thead': case 'tr': case 'td': case 'th':
                $this->children($n);
                $this->flush();
                return;
            case 'iframe': case 'video': case 'object': case 'embed':
                $this->flush();
                $this->embed($n);
                return;
            case 'details':
                $this->flush();
                $summary = Dom::first($this->xp, './summary', $n);
                $q = $summary === null ? '' : Dom::text($summary);
                if ($summary !== null) {
                    $summary->parentNode?->removeChild($summary);
                }
                $a = Sanitizer::rich(Dom::innerHtml($n), $this->base);
                if ($q !== '' && trim(strip_tags($a)) !== '') {
                    $this->out[] = Neutral::el('faq', ['items' => [['q' => $q, 'a' => $a]]], $src);
                }
                return;
            case 'dl':
                $this->flush();
                $this->definitionList($n);
                return;
            case 'form':
                $this->flush();
                $this->form($n);
                return;
            case 'pre':
                $this->flush();
                $text = $n->textContent;
                if (trim($text) !== '') {
                    $this->out[] = Neutral::el('paragraph', ['html' => '<pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</pre>'], $src);
                }
                return;
            case 'br':
                if ($this->inline !== '') {
                    $this->inline .= '<br>';
                }
                return;
            case 'a':
                if (($button = $this->button($n)) !== null) {
                    $this->flush();
                    $this->out[] = $button;
                    return;
                }
                if ($this->hasBlock($n)) {
                    $this->flush();
                    $this->children($n);
                    $this->flush();
                    return;
                }
                $this->inline .= Dom::outerHtml($n);
                $this->inlineFrom ??= $n;
                return;
        }
        if (in_array($tag, self::INLINE, true) && !$this->hasBlock($n)) {
            $this->inline .= Dom::outerHtml($n);
            $this->inlineFrom ??= $n;
            return;
        }
        // A container: a gallery, a schema.org FAQ, an accordion, a text box or just a wrapper.
        $this->flush();
        if (($level = self::headingClass($n)) > 0 && !$this->hasBlock($n)) {
            $text = Dom::text($n);
            if ($text !== '' && mb_strlen($text) <= 120) {
                $this->out[] = Neutral::el('heading', ['level' => $level, 'text' => $text], $src);
                return;
            }
        }
        if (($faq = $this->schemaFaq($n)) !== null || ($faq = $this->accordion($n)) !== null) {
            $this->out[] = $faq;
            return;
        }
        if (($gallery = $this->gallery($n)) !== null) {
            $this->out[] = $gallery;
            return;
        }
        if (($table = $this->divTable($n)) !== null || ($table = $this->priceRows($n)) !== null) {
            $this->out[] = $table;
            return;
        }
        if (!$this->hasBlock($n)) {
            $links = Dom::all($this->xp, './/a[@href]', $n);
            if (count($links) === 1 && Dom::text($links[0]) === Dom::text($n) && ($button = $this->button($links[0])) !== null) {
                $this->out[] = $button;
                return;
            }
            $this->inline = Dom::innerHtml($n);
            $this->inlineFrom = $n;
            $this->flush();
            return;
        }
        $this->children($n);
        $this->flush();
    }

    /** Loose inline content collected so far becomes one paragraph. */
    private function flush(): void
    {
        if ($this->inline === '') {
            return;
        }
        $html = Sanitizer::inline($this->inline, $this->base);
        $html = preg_replace('~^(<br>\s*)+|(<br>\s*)+$~', '', trim($html)) ?? $html;
        $from = $this->inlineFrom;
        $this->inline = '';
        $this->inlineFrom = null;
        if (trim(strip_tags($html)) !== '') {
            $this->out[] = Neutral::el('paragraph', ['html' => trim($html)], $from instanceof DOMElement ? Dom::label($from) : '');
        }
    }

    private function hasBlock(DOMElement $n): bool
    {
        foreach ($n->getElementsByTagName('*') as $d) {
            if (in_array(strtolower($d->nodeName), self::BLOCK, true)) {
                return true;
            }
        }
        return false;
    }

    private function paragraph(DOMElement $p): void
    {
        $imgs = Dom::all($this->xp, './/img', $p);
        $text = Dom::text($p);
        $links = Dom::all($this->xp, './/a', $p);
        if (count($links) === 1 && $text !== '' && Dom::text($links[0]) === $text && ($button = $this->button($links[0])) !== null) {
            $this->out[] = $button;
            return;
        }
        if ($text !== '') {
            $html = Sanitizer::inline(Dom::innerHtml($p), $this->base);
            if (trim(strip_tags($html)) !== '') {
                $this->out[] = Neutral::el('paragraph', ['html' => $html], Dom::label($p));
            }
        }
        foreach ($imgs as $img) {
            $this->image($img);
        }
    }

    private function list(DOMElement $list, bool $ordered): void
    {
        if (self::isAnchorMenu($list, $this->xp)) {
            return;
        }
        $items = [];
        foreach (Dom::all($this->xp, './li', $list) as $li) {
            $html = Sanitizer::inline(Dom::innerHtml($li), $this->base);
            $html = preg_replace('~(<br>\s*)+$~', '', trim($html)) ?? $html;
            if (trim(strip_tags($html)) !== '') {
                $items[] = $html;
            }
            foreach (Dom::all($this->xp, './/img', $li) as $img) {
                if (Dom::text($li) === '') {
                    $this->image($img);
                }
            }
        }
        if ($items !== []) {
            $this->out[] = Neutral::el('list', ['ordered' => $ordered, 'items' => $items], Dom::label($list));
        }
    }

    /** One image; returns the element as well for callers that need it. */
    public function imageElement(DOMElement $img, string $caption = ''): ?array
    {
        $src = Dom::imageSrc($img);
        $parent = $img->parentNode;
        // A thumbnail that links to the full picture: take the full one.
        if ($parent instanceof DOMElement && strtolower($parent->nodeName) === 'a' && Url::isImage($parent->getAttribute('href'))) {
            $src = $parent->getAttribute('href');
        }
        if ($src === '' || Dom::isTinyImage($img)) {
            return null;
        }
        $src = Url::resolve($this->base, $src);
        $path = strtolower((string)parse_url($src, PHP_URL_PATH));
        // A folder instead of a file (an empty image field of the template) or an SVG file is not a picture to import.
        if ($src === '' || !Sanitizer::safeUrl($src, false) || str_ends_with($path, '.svg') || str_ends_with($path, '/')) {
            return null;
        }
        $alt = Neutral::line($img->getAttribute('alt'));
        if ($alt === '') {
            $alt = Neutral::line($img->getAttribute('title'));
        }
        $el = Neutral::el('image', ['src' => $src, 'alt' => $alt, 'caption' => $caption], Dom::label($img));
        // The size the page shows it at, when the markup says (a 150x150 badge must not be stretched to the column).
        $w = (int)$img->getAttribute('width');
        $h = (int)$img->getAttribute('height');
        if ($w > 0 && $w < 5000 && $src === Url::resolve($this->base, Dom::imageSrc($img))) {
            $el['width'] = $w;
            if ($h > 0 && $h < 5000) {
                $el['height'] = $h;
            }
        }
        return $el;
    }

    private function image(DOMElement $img, string $caption = ''): void
    {
        $el = $this->imageElement($img, $caption);
        if ($el !== null) {
            $this->out[] = $el;
        }
    }

    private function figure(DOMElement $fig): void
    {
        $captionNode = Dom::first($this->xp, './/figcaption', $fig);
        $caption = $captionNode === null ? '' : Dom::text($captionNode);
        $imgs = Dom::all($this->xp, './/img', $fig);
        if (($table = Dom::first($this->xp, './/table', $fig)) !== null) {
            $this->visit($table);
            return;
        }
        if (($frame = Dom::first($this->xp, './/iframe|.//video', $fig)) !== null) {
            $this->embed($frame);
            return;
        }
        if (($quote = Dom::first($this->xp, './/blockquote', $fig)) !== null) {
            $this->visit($quote);
            return;
        }
        if (count($imgs) > 1) {
            $images = [];
            foreach ($imgs as $img) {
                if (($el = $this->imageElement($img)) !== null) {
                    $images[] = ['src' => $el['src'], 'alt' => $el['alt'], 'caption' => ''];
                }
            }
            if ($images !== []) {
                $this->out[] = Neutral::el('gallery', ['images' => $images], Dom::label($fig));
            }
            return;
        }
        if ($imgs !== []) {
            $this->image($imgs[0], $caption);
            return;
        }
        $this->children($fig);
        $this->flush();
    }

    private function embed(DOMElement $n): void
    {
        $src = $n->getAttribute('src') ?: $n->getAttribute('data-src') ?: $n->getAttribute('data');
        if ($src === '' && ($source = Dom::first($this->xp, './/source', $n)) !== null) {
            $src = $source->getAttribute('src');
        }
        $src = Url::resolve($this->base, $src);
        if ($src === '') {
            return;
        }
        $this->out[] = Neutral::el('embed', ['src' => $src, 'kind' => self::embedKind($src, strtolower($n->nodeName))], Dom::label($n));
    }

    public static function embedKind(string $src, string $tag = 'iframe'): string
    {
        if ($tag === 'video' || preg_match('~(youtube\.com|youtu\.be|youtube-nocookie\.com|vimeo\.com|rutube\.ru|vk\.com/video|vkvideo\.ru|dzen\.ru/(video|embed)|ok\.ru/video|\.mp4($|\?)|\.webm($|\?))~i', $src) === 1) {
            return 'video';
        }
        if (preg_match('~(yandex\.[a-z]+/map-widget|api-maps\.yandex|yandex\.[a-z]+/maps|google\.[a-z.]+/maps|maps\.google|openstreetmap\.org|2gis\.)~i', $src) === 1) {
            return 'map';
        }
        return 'other';
    }

    /** A link styled as a button becomes a button element. */
    public function button(DOMElement $a): ?array
    {
        $isButton = $a->getAttribute('role') === 'button' || Dom::classMatches($a, '~(^|[-_])(btn|button|cta)([-_]|$)~i') || Dom::classMatches($a, '~^(t-btn|wp-block-button__link|elementor-button|et_pb_button|vc_btn3?|vc_general)~');
        if (!$isButton) {
            return null;
        }
        $text = Dom::visibleText($a);
        if (preg_match('~^(.{2,60}?)\s+\1$~u', $text, $m) === 1) {
            // The label twice for a hover effect.
            $text = $m[1];
        }
        $href = Url::resolve($this->base, $a->getAttribute('href'));
        $href = preg_match('~^https?://~i', $href) === 1 ? Dom::encodeUrl($href) : $href;
        if ($text === '' || $href === '') {
            return null;
        }
        return Neutral::el('button', ['text' => $text, 'url' => $href], Dom::label($a));
    }

    private function definitionList(DOMElement $dl): void
    {
        $items = [];
        $q = null;
        foreach ($dl->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($c->nodeName);
            if ($tag === 'dt') {
                $q = Dom::text($c);
            } elseif ($tag === 'dd' && $q !== null && $q !== '') {
                $a = Sanitizer::rich(Dom::innerHtml($c), $this->base);
                if (trim(strip_tags($a)) !== '') {
                    $items[] = ['q' => $q, 'a' => $a];
                }
                $q = null;
            }
        }
        if ($items !== []) {
            $this->out[] = Neutral::el('faq', ['items' => $items], Dom::label($dl));
        }
    }

    private function form(DOMElement $form): void
    {
        $fields = [];
        foreach (Dom::all($this->xp, './/input|.//textarea|.//select', $form) as $f) {
            $type = strtolower($f->getAttribute('type') ?: $f->nodeName);
            if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'image', 'search'], true)) {
                continue;
            }
            $label = '';
            if (($id = $f->getAttribute('id')) !== '' && ($l = Dom::first($this->xp, "//label[@for='" . str_replace("'", '', $id) . "']")) !== null) {
                $label = Dom::text($l);
            }
            $label = $label !== '' ? $label : Neutral::line($f->getAttribute('placeholder') ?: $f->getAttribute('aria-label') ?: $f->getAttribute('data-tilda-req-name') ?: $f->getAttribute('name'));
            $fields[] = ['name' => $f->getAttribute('name'), 'label' => mb_substr($label, 0, 100), 'type' => $type === 'textarea' ? 'textarea' : ($f->nodeName === 'select' ? 'select' : $type)];
        }
        // A form without a field to type into is a filter or a switcher (select of a service, sorting), not a form to send.
        $typed = array_filter($fields, static fn(array $f): bool => in_array($f['type'], ['text', 'email', 'tel', 'textarea', 'number', 'date', 'url'], true));
        if ($typed === []) {
            return;
        }
        // A filter or a search over a list (doctors by name, products by price) is not a form to send either.
        $parent = $form->parentNode;
        if (Dom::classMatches($form, '~(filter|search|sort)~i') || ($parent instanceof DOMElement && Dom::classMatches($parent, '~(^|[-_])(filter|filters|search|sort)([-_]|$)~i'))) {
            return;
        }
        $submit = Dom::first($this->xp, './/button[@type="submit" or not(@type)]|.//input[@type="submit"]', $form);
        $submitText = $submit === null ? '' : ($submit->nodeName === 'input' ? $submit->getAttribute('value') : Dom::text($submit));
        $this->out[] = Neutral::el('form', ['fields' => $fields, 'submit_text' => Neutral::line($submitText)], Dom::label($form));
    }

    /** schema.org FAQPage markup: Question with name and acceptedAnswer text. */
    private function schemaFaq(DOMElement $n): ?array
    {
        if (!str_contains($n->getAttribute('itemtype'), 'FAQPage')) {
            return null;
        }
        $items = [];
        foreach (Dom::all($this->xp, './/*[contains(@itemtype,"Question")]', $n) as $q) {
            $name = Dom::first($this->xp, './/*[@itemprop="name"]', $q);
            $text = Dom::first($this->xp, './/*[@itemprop="acceptedAnswer"]//*[@itemprop="text"]', $q);
            if ($name !== null && $text !== null) {
                $items[] = ['q' => Dom::text($name), 'a' => Sanitizer::rich(Dom::innerHtml($text), $this->base)];
            }
        }
        return $items === [] ? null : Neutral::el('faq', ['items' => $items], Dom::label($n));
    }

    /** An accordion of a theme or page builder: title/content pairs inside a container named accordion, faq, toggle or spoiler. */
    private function accordion(DOMElement $n): ?array
    {
        if (!Dom::classMatches($n, '~(accordion|faq|toggles?$|spoilers?$|questions)~i') && !preg_match('~(accordion|faq)~i', $n->getAttribute('id'))) {
            return null;
        }
        $titles = Dom::all($this->xp, './/*[@class][not(self::a)]', $n);
        $items = [];
        $pendingQ = null;
        foreach ($titles as $el) {
            $isTitle = Dom::classMatches($el, '~(^|[-_])(title|question|header|heading|head|summary|toggle_title|tab-title|panel-title|q)$~i') || Dom::classMatches($el, '~__(title|question|header)$~i');
            $isBody = Dom::classMatches($el, '~(^|[-_])(content|answer|body|text|panel-body|toggle_content|tab-content|collapse|a)$~i') || Dom::classMatches($el, '~__(content|answer|body|text|textwrapper)$~i');
            if ($isTitle && Dom::text($el) !== '' && ($pendingQ === null || !$this->contains($pendingQ, $el))) {
                $pendingQ = $el;
            } elseif ($isBody && $pendingQ !== null && !$this->contains($pendingQ, $el) && !$this->contains($el, $pendingQ)) {
                $a = Sanitizer::rich(Dom::innerHtml($el), $this->base);
                if (trim(strip_tags($a)) !== '') {
                    $items[] = ['q' => Dom::text($pendingQ), 'a' => $a];
                }
                $pendingQ = null;
            }
        }
        return count($items) >= 1 ? Neutral::el('faq', ['items' => $items], Dom::label($n)) : null;
    }

    private function contains(DOMNode $outer, DOMNode $inner): bool
    {
        for ($n = $inner->parentNode; $n !== null; $n = $n->parentNode) {
            if ($n === $outer) {
                return true;
            }
        }
        return false;
    }

    /** A gallery or slider: several pictures and almost no text. */
    private function gallery(DOMElement $n): ?array
    {
        if (!Dom::classMatches($n, '~(gallery|slider|carousel|swiper|slick|fotorama|lightbox|photos|owl-)~i')) {
            return null;
        }
        $imgs = Dom::all($this->xp, './/img', $n);
        if (count($imgs) < 2 || mb_strlen(Dom::text($n)) > 40 * count($imgs)) {
            return null;
        }
        $images = [];
        $seen = [];
        foreach ($imgs as $img) {
            $el = $this->imageElement($img);
            if ($el !== null && !isset($seen[$el['src']])) {
                $seen[$el['src']] = true;
                $images[] = ['src' => $el['src'], 'alt' => $el['alt'], 'caption' => ''];
            }
        }
        return count($images) >= 2 ? Neutral::el('gallery', ['images' => $images], Dom::label($n)) : null;
    }

    /**
     * A table drawn with divs (price lists, schedules, tariff comparisons): a box named "...table" whose children are
     * rows ("...row") of cells. It becomes a real table; each cell keeps its inline text.
     */
    private function divTable(DOMElement $n): ?array
    {
        if (!Dom::classMatches($n, '~(^|[-_])table([-_]|$)|__table$~i') || in_array(strtolower($n->nodeName), ['table', 'ul', 'ol'], true)) {
            return null;
        }
        $isRow = static fn(DOMElement $c): bool => Dom::classMatches($c, '~(^|[-_])(row|tr)([-_]|$)|__(row|tr)$~i');
        $isHead = static fn(DOMElement $c): bool => Dom::classMatches($c, '~(^|[-_])(head|header|thead)([-_]|$)|__(head|header)$~i');
        $rows = [];
        $head = null;
        $others = 0;
        foreach (self::elementChildren($n) as $child) {
            if ($isRow($child)) {
                $rows[] = $child;
            } elseif ($head === null && $rows === [] && $isHead($child)) {
                $head = $child;
            } elseif (Dom::classMatches($child, '~(^|[-_])(body|tbody|list|items|rows)([-_]|$)|__(body|list|items|rows)$~i')
                && ($inner = array_values(array_filter(self::elementChildren($child), $isRow))) !== []) {
                // Rows inside a body wrapper (price-table > price-table__body > price-table__row).
                array_push($rows, ...$inner);
            } elseif (Dom::text($child) !== '') {
                $others++;
            }
        }
        if (count($rows) + ($head !== null ? 1 : 0) < 2 || $others > count($rows) / 3) {
            return null;
        }
        $html = '';
        $width = [];
        if ($head !== null) {
            $cells = self::elementChildren($head);
            if (count($cells) >= 2) {
                $html .= '<tr>' . implode('', array_map(fn(DOMElement $c): string => '<th>' . $this->cellHtml($c) . '</th>', $cells)) . '</tr>';
            }
        }
        foreach ($rows as $row) {
            $cells = self::elementChildren($row);
            $width[count($cells)] = ($width[count($cells)] ?? 0) + 1;
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= '<td>' . $this->cellHtml($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        arsort($width);
        if ((int)array_key_first($width) < 2) {
            return null;
        }
        $html = Sanitizer::rich('<table><tbody>' . $html . '</tbody></table>', $this->base);
        return trim(strip_tags($html)) === '' ? null : Neutral::el('table', ['html' => $html], Dom::label($n));
    }

    /**
     * A price list drawn as a row of the same boxes (WPBakery and Elementor grids, theme price lists without "table" in
     * the class): three or more children of one kind, each going down through single wrappers to 2-4 cells with text,
     * the same number in every row, and a price in most rows. It becomes a table.
     */
    private function priceRows(DOMElement $n): ?array
    {
        $children = self::elementChildren($n);
        if (count($children) < 3) {
            return null;
        }
        $sig = static fn(DOMElement $c): string => strtolower($c->nodeName) . '.' . (preg_split('~\s+~', trim($c->getAttribute('class')))[0] ?? '');
        $first = $sig($children[0]);
        $rows = [];
        $priced = 0;
        $price = '~\d[\d\s.,]*\s*(₽|руб|р\.|rub|\$|€|тыс)|^(от\s*)?\d[\d\s]{2,}([.,]\d+)?\s*$~iu';
        foreach ($children as $child) {
            if ($sig($child) !== $first || mb_strlen(Dom::text($child)) > 400) {
                return null;
            }
            $box = $child;
            for ($depth = 0; $depth < 6; $depth++) {
                $inner = array_values(array_filter(self::elementChildren($box), static fn(DOMElement $c): bool => Dom::text($c) !== ''));
                if (count($inner) !== 1) {
                    break;
                }
                $box = $inner[0];
            }
            $cells = array_values(array_filter(self::elementChildren($box), static fn(DOMElement $c): bool => Dom::text($c) !== ''));
            if (count($cells) < 2 || count($cells) > 4 || Dom::first($this->xp, './/img|.//ul|.//ol|.//table|.//form', $box) !== null) {
                return null;
            }
            foreach ($cells as $c) {
                if (preg_match($price, Dom::text($c)) === 1) {
                    $priced++;
                    break;
                }
            }
            $rows[] = $cells;
        }
        $widths = array_unique(array_map('count', $rows));
        if (count($widths) !== 1 || $priced < 0.6 * count($rows)) {
            return null;
        }
        $html = '';
        foreach ($rows as $cells) {
            $html .= '<tr>' . implode('', array_map(fn(DOMElement $c): string => '<td>' . $this->cellHtml($c) . '</td>', $cells)) . '</tr>';
        }
        $html = Sanitizer::rich('<table><tbody>' . $html . '</tbody></table>', $this->base);
        return trim(strip_tags($html)) === '' ? null : Neutral::el('table', ['html' => $html], Dom::label($n));
    }

    /** Inline HTML of a table cell; parts in blocks of their own (a name and a note under it) are kept on separate lines. */
    private function cellHtml(DOMElement $cell): string
    {
        $blocks = array_values(array_filter(self::elementChildren($cell), fn(DOMElement $c): bool => in_array(strtolower($c->nodeName), ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) && Dom::text($c) !== ''));
        if (count($blocks) < 2) {
            return Neutral::line(Sanitizer::inline(Dom::innerHtml($cell), $this->base));
        }
        $short = array_filter($blocks, static fn(DOMElement $c): bool => mb_strlen(Dom::text($c)) > 24) === [];
        return implode($short ? ' ' : '<br>', array_map(fn(DOMElement $c): string => Neutral::line(Sanitizer::inline(Dom::innerHtml($c), $this->base)), $blocks));
    }

    /** @return list<DOMElement> element children without scripts and styles */
    private static function elementChildren(DOMElement $n): array
    {
        $out = [];
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMElement && !in_array(strtolower($c->nodeName), ['script', 'style', 'template', 'noscript'], true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** A table used for page layout (old sites): nested tables or cells holding headings, lists or forms. */
    public static function isLayoutTable(DOMElement $t, DOMXPath $xp): bool
    {
        if (Dom::first($xp, './/table', $t) !== null) {
            return true;
        }
        if (Dom::first($xp, './/td//h1|.//td//h2|.//td//form|.//td//iframe', $t) !== null) {
            return true;
        }
        // Rows of the same width with short cells are data (a price list) even when a cell holds a hint in a <div><p>.
        $rows = Dom::all($xp, './tr|./tbody/tr|./thead/tr', $t);
        $widths = [];
        $cells = 0;
        $chars = 0;
        foreach ($rows as $tr) {
            $tds = Dom::all($xp, './td|./th', $tr);
            $widths[count($tds)] = ($widths[count($tds)] ?? 0) + 1;
            foreach ($tds as $td) {
                $cells++;
                $chars += mb_strlen(Dom::text($td));
            }
        }
        if (count($rows) >= 2 && $cells > 0) {
            arsort($widths);
            $common = (int)array_key_first($widths);
            if ($common >= 2 && $widths[$common] >= 0.7 * count($rows) && $chars / $cells < 300) {
                return false;
            }
        }
        if (Dom::first($xp, './/td//ul|.//td//div[.//p]', $t) !== null && Dom::first($xp, './/th', $t) === null) {
            return true;
        }
        return false;
    }

    /**
     * Heading level of a block styled as a heading by class: "h2", "h3" give their level, a title of a card or a
     * section ("item_title", "spec__name", "section-title") gives 3. 0 when it is not one.
     */
    private static function headingClass(DOMElement $n): int
    {
        if (!in_array(strtolower($n->nodeName), ['div', 'span', 'strong', 'b', 'p'], true)) {
            return 0;
        }
        foreach (Dom::classes($n) as $c) {
            if (preg_match('~^h([1-6])$~', $c, $m) === 1) {
                return max(2, (int)$m[1]);
            }
        }
        if (strtolower($n->nodeName) === 'div' && Dom::classMatches($n, '~(^|[-_])(title|heading|header|name|caption|subtitle)$|__(title|heading|header|name|subtitle)$~i')) {
            $text = Dom::text($n);
            if (mb_strlen($text) >= 3 && mb_strlen($text) <= 100 && preg_match('~[.;,]$~u', $text) !== 1 && Dom::first(Dom::xpath($n->ownerDocument), './/a', $n) === null) {
                return 3;
            }
        }
        return 0;
    }

    /** A list of links to anchors of the same page only: the in-page menu of a one-page site. */
    private static function isAnchorMenu(DOMElement $list, DOMXPath $xp): bool
    {
        $items = Dom::all($xp, './li', $list);
        if (count($items) < 2) {
            return false;
        }
        foreach ($items as $li) {
            $links = Dom::all($xp, './/a[@href]', $li);
            if (count($links) !== 1 || !str_starts_with(trim($links[0]->getAttribute('href')), '#') || Dom::text($links[0]) !== Dom::text($li)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Cleanup: neighbouring FAQ elements join, empty elements go, the same picture twice in a row once.
     *
     * @param list<array<string,mixed>> $els
     * @return list<array<string,mixed>>
     */
    public static function tidy(array $els): array
    {
        $out = [];
        $images = [];
        foreach ($els as $el) {
            if (($el['type'] ?? '') === 'image') {
                // Decorative pictures: a file named as an icon or a divider, or the same picture again further down.
                $file = strtolower(basename((string)parse_url((string)$el['src'], PHP_URL_PATH)));
                if (isset($images[$el['src']]) || preg_match('~^(icon|ico|arrow|bullet|marker|separator|divider|delimiter|title-icon)([-_.\d]|$)~', $file) === 1) {
                    continue;
                }
                $images[$el['src']] = true;
            }
            $prev = $out === [] ? null : $out[count($out) - 1];
            if (($el['type'] ?? '') === 'faq' && $prev !== null && $prev['type'] === 'faq') {
                $out[count($out) - 1]['items'] = array_merge($prev['items'], $el['items']);
                continue;
            }
            if (($el['type'] ?? '') === 'image' && $prev !== null && $prev['type'] === 'image' && $prev['src'] === $el['src']) {
                continue;
            }
            if (($el['type'] ?? '') === 'paragraph' && trim(strip_tags((string)$el['html'])) === '') {
                continue;
            }
            $out[] = $el;
        }
        return self::bulletLists($out);
    }

    /**
     * Paragraphs written as list items ("- консультации;", "• выезд") in a row of two or more become one list: old
     * sites and page builders type lists by hand.
     *
     * @param list<array<string,mixed>> $els
     * @return list<array<string,mixed>>
     */
    private static function bulletLists(array $els): array
    {
        $bullet = '~^\s*(?:(?:[-\x{2013}\x{2014}\x{2022}\x{00B7}\x{25CF}\x{25AA}\x{2219}*]|\d{1,2}[.)])\s+|\d{1,2}[.)](?=\p{Lu}))~u';
        $out = [];
        $run = [];
        $flush = static function () use (&$out, &$run, $bullet): void {
            if (count($run) >= 2) {
                $ordered = preg_match('~^\s*\d~', html_entity_decode(strip_tags((string)$run[0]['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1;
                $items = [];
                foreach ($run as $p) {
                    $items[] = preg_replace($bullet, '', (string)$p['html'], 1) ?? (string)$p['html'];
                }
                $out[] = Neutral::el('list', ['ordered' => $ordered, 'items' => $items], (string)$run[0]['source']);
            } else {
                foreach ($run as $p) {
                    $out[] = $p;
                }
            }
            $run = [];
        };
        foreach ($els as $el) {
            $html = (string)($el['html'] ?? '');
            if (($el['type'] ?? '') === 'paragraph' && !str_contains($html, '<br') && preg_match($bullet, html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1 && mb_strlen(strip_tags($html)) < 600) {
                $run[] = $el;
                continue;
            }
            $flush();
            $out[] = $el;
        }
        $flush();
        return $out;
    }
}
