<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Html\Dom;
use AgeCMS\Modules\Importer\Html\ElementParser;
use AgeCMS\Modules\Importer\Html\Sanitizer;
use AgeCMS\Modules\Importer\Neutral;
use AgeCMS\Modules\Importer\Url;
use DOMElement;

/**
 * Rendered widgets of WordPress (Gutenberg block classes, Elementor, Yoast and Rank Math FAQ) that mean more than their
 * markup: a cover is a first screen, a row of buttons is buttons, an accordion is FAQ. Used as the ElementParser hook.
 */
final class Widgets
{
    /** @return list<array<string,mixed>>|null */
    public static function hook(DOMElement $n, ElementParser $p): ?array
    {
        $xp = Dom::xpath($n->ownerDocument);
        $base = $p->base();
        $src = Dom::label($n);
        $tag = strtolower($n->nodeName);

        // Lists WordPress renders on every request (latest posts, archives, search, comments): a stale snapshot, dropped.
        if (array_intersect(Dom::classes($n), Gutenberg::RENDERED_DYNAMIC) !== [] || $n->getAttribute('id') === 'respond') {
            return [];
        }
        // wpautop wraps a caption figure, an iframe or a player in <p>: the paragraph would swallow them, parse its parts.
        if ($tag === 'p' && Dom::first($xp, './/figure|.//div|.//table|.//ul|.//ol|.//blockquote|.//iframe|.//video|.//audio|.//object|.//embed', $n) !== null) {
            return $p->parseChildren($n);
        }
        if (Dom::hasClass($n, 'wp-caption') && $tag !== 'figure' || $tag === 'figure' && Dom::hasClass($n, 'wp-caption') && Dom::first($xp, './/figcaption', $n) === null) {
            $imgs = Dom::all($xp, './/img', $n);
            if (count($imgs) === 1) {
                $cap = Dom::first($xp, './/*[contains(concat(" ", normalize-space(@class), " "), " wp-caption-text ")]|.//figcaption', $n);
                $el = $p->imageElement($imgs[0], $cap === null ? '' : Dom::text($cap));
                return $el === null ? [] : [$el];
            }
        }
        if ($tag === 'audio' || Dom::hasClass($n, 'wp-block-audio') || Dom::hasClass($n, 'wp-audio-shortcode')) {
            return self::audio($n, $xp, $base, $src);
        }
        // A video file of the media library ([video] shortcode): the address without the ?_=1 counter WordPress adds.
        if (Dom::hasClass($n, 'wp-video') || ($tag === 'video' && Dom::hasClass($n, 'wp-video-shortcode'))) {
            $video = $tag === 'video' ? $n : Dom::first($xp, './/video', $n);
            $file = $video?->getAttribute('src') ?? '';
            if ($file === '' && $video !== null && ($source = Dom::first($xp, './/source[@src]', $video)) !== null) {
                $file = $source->getAttribute('src');
            }
            $file = Url::resolve($base, preg_replace('~\?_=\d+$~', '', $file) ?? $file);
            return $file === '' ? [] : [Neutral::el('embed', ['src' => $file, 'kind' => 'video'], $src)];
        }

        if (Dom::hasClass($n, 'wp-block-cover') || Dom::hasClass($n, 'wp-block-cover-image')) {
            $hero = self::cover($n, $p);
            $out = [$hero];
            // The first button is the call to action of the first screen, the others stay as buttons under it.
            foreach (Dom::all($xp, './/a[@href][contains(@class,"wp-block-button__link") or contains(@class,"button") or contains(@class,"btn")]', $n) as $i => $a) {
                $text = Dom::text($a);
                $href = Url::resolve($base, $a->getAttribute('href'));
                if ($i > 0 && $text !== '' && $href !== '' && $text !== $hero['cta_text']) {
                    $out[] = Neutral::el('button', ['text' => $text, 'url' => $href], Dom::label($a));
                }
            }
            return $out;
        }
        if (Dom::hasClass($n, 'wp-block-gallery') || Dom::hasClass($n, 'blocks-gallery-grid') || (Dom::hasClass($n, 'gallery') && str_starts_with($n->getAttribute('id'), 'gallery-')) || Dom::hasClass($n, 'elementor-image-gallery')) {
            $images = [];
            foreach (Dom::all($xp, './/img', $n) as $img) {
                $el = $p->imageElement($img);
                if ($el !== null) {
                    $cap = Dom::first($xp, 'ancestor::figure[1]/figcaption|ancestor::*[contains(@class,"gallery-item")][1]//*[contains(@class,"gallery-caption")]', $img);
                    $images[] = ['src' => $el['src'], 'alt' => $el['alt'], 'caption' => $cap === null ? '' : Dom::text($cap)];
                }
            }
            return $images === [] ? [] : [Neutral::el('gallery', ['images' => $images], $src)];
        }
        if (Dom::hasClass($n, 'wp-block-buttons') || Dom::hasClass($n, 'elementor-widget-button')) {
            $out = [];
            foreach (Dom::all($xp, './/a[@href]', $n) as $a) {
                $text = Dom::text($a);
                $href = $a->getAttribute('href');
                // An Elementor button that opens a popup (usually the request form): the link stays on the page.
                $href = str_contains(rawurldecode($href), 'elementor-action') ? Url::stripFragment($base) . '#' : Url::resolve($base, $href);
                if ($text !== '' && $href !== '') {
                    $out[] = Neutral::el('button', ['text' => $text, 'url' => $href], Dom::label($a));
                }
            }
            return $out;
        }
        if (Dom::hasClass($n, 'wp-block-media-text')) {
            $out = [];
            if (($img = Dom::first($xp, './/*[contains(@class,"wp-block-media-text__media")]//img', $n)) !== null && ($el = $p->imageElement($img)) !== null) {
                $out[] = $el;
            }
            if (($content = Dom::first($xp, './/*[contains(@class,"wp-block-media-text__content")]', $n)) !== null) {
                $out = array_merge($out, $p->parseChildren($content));
            }
            return $out;
        }
        if (Dom::hasClass($n, 'wp-block-embed') || Dom::hasClass($n, 'elementor-widget-video')) {
            $url = '';
            if (($frame = Dom::first($xp, './/iframe', $n)) !== null) {
                $url = $frame->getAttribute('src') ?: $frame->getAttribute('data-src') ?: $frame->getAttribute('data-lazy-src');
            } elseif (($wrap = Dom::first($xp, './/*[contains(@class,"wp-block-embed__wrapper")]', $n)) !== null) {
                $url = Dom::text($wrap);
                // A rendered post of a social network (Twitter, Instagram, TikTok): the address of the post, not its text.
                if (preg_match('~^https?://\S+$~', $url) !== 1 && ($quote = Dom::first($xp, './/blockquote', $wrap)) !== null) {
                    $url = $quote->getAttribute('cite') ?: $quote->getAttribute('data-instgrm-permalink');
                    if ($url === '') {
                        $links = Dom::all($xp, './/a[starts-with(@href,"http")]', $quote);
                        $url = $links === [] ? '' : $links[count($links) - 1]->getAttribute('href');
                    }
                }
            } elseif (($settings = json_decode(html_entity_decode($n->getAttribute('data-settings')), true)) && is_array($settings)) {
                $url = (string)($settings['youtube_url'] ?? $settings['vimeo_url'] ?? $settings['dailymotion_url'] ?? '');
            }
            $url = Url::resolve($base, $url);
            if ($url === '') {
                return [];
            }
            return [Neutral::el('embed', ['src' => $url, 'kind' => ElementParser::embedKind($url)], $src)];
        }
        if (Dom::hasClass($n, 'schema-faq') || Dom::hasClass($n, 'wp-block-yoast-faq-block')) {
            return self::pairs($n, $xp, './/*[contains(@class,"schema-faq-section")]', './/*[contains(@class,"schema-faq-question")]', './/*[contains(@class,"schema-faq-answer")]', $base, $src);
        }
        if (Dom::hasClass($n, 'rank-math-faq-block') || $n->getAttribute('id') === 'rank-math-faq') {
            return self::pairs($n, $xp, './/*[contains(@class,"rank-math-faq-item") or contains(@class,"rank-math-list-item")]', './/*[contains(@class,"rank-math-question")]', './/*[contains(@class,"rank-math-answer")]', $base, $src);
        }
        if (Dom::hasClass($n, 'elementor-accordion') || Dom::hasClass($n, 'elementor-toggle')) {
            return self::pairs($n, $xp, './/*[contains(@class,"elementor-accordion-item") or contains(@class,"elementor-toggle-item")]', './/*[contains(@class,"elementor-tab-title")]', './/*[contains(@class,"elementor-tab-content")]', $base, $src);
        }
        if (Dom::hasClass($n, 'wp-block-details')) {
            return null;
        }
        if (Dom::hasClass($n, 'elementor-widget-heading')) {
            $h = Dom::first($xp, './/*[contains(@class,"elementor-heading-title")]', $n);
            if ($h !== null && Dom::text($h) !== '') {
                $level = preg_match('~^h([1-6])$~', strtolower($h->nodeName), $m) === 1 ? (int)$m[1] : 2;
                return [Neutral::el('heading', ['level' => $level, 'text' => Dom::text($h)], $src)];
            }
        }
        return null;
    }

    /**
     * An audio player (audio block, [audio] shortcode): the file stays a link to the old site, the media library keeps
     * pictures only.
     *
     * @return list<array<string,mixed>>
     */
    private static function audio(DOMElement $n, \DOMXPath $xp, string $base, string $src): array
    {
        $audio = strtolower($n->nodeName) === 'audio' ? $n : Dom::first($xp, './/audio', $n);
        $file = '';
        if ($audio !== null) {
            $file = $audio->getAttribute('src');
            if ($file === '' && ($source = Dom::first($xp, './/source[@src]', $audio)) !== null) {
                $file = $source->getAttribute('src');
            }
        }
        if ($file === '' && ($a = Dom::first($xp, './/a[@href]', $n)) !== null) {
            $file = $a->getAttribute('href');
        }
        $file = Url::resolve($base, preg_replace('~\?_=\d+$~', '', $file) ?? $file);
        if ($file === '') {
            return [];
        }
        $cap = Dom::first($xp, './/figcaption', $n);
        $name = $cap !== null && Dom::text($cap) !== '' ? Dom::text($cap) : rawurldecode(basename((string)parse_url($file, PHP_URL_PATH)));
        return [Neutral::el('paragraph', ['html' => '<a href="' . htmlspecialchars($file, ENT_QUOTES) . '">' . htmlspecialchars($name, ENT_QUOTES) . '</a>'], $src)];
    }

    /** First screen out of a Gutenberg cover: the heading, the text under it, the first button and the background. */
    public static function cover(DOMElement $n, ElementParser $p): array
    {
        $xp = Dom::xpath($n->ownerDocument);
        $image = '';
        if (($img = Dom::first($xp, './/img[contains(@class,"wp-block-cover__image-background")]|.//img', $n)) !== null && ($el = $p->imageElement($img)) !== null) {
            $image = $el['src'];
        } elseif (($bg = Dom::backgroundImage($n)) !== '' || (($inner = Dom::first($xp, './/*[@style]', $n)) !== null && ($bg = Dom::backgroundImage($inner)) !== '')) {
            $image = Url::resolve($p->base(), $bg);
        }
        $h = Dom::first($xp, './/h1|.//h2|.//h3', $n);
        $h1 = $h === null ? '' : Dom::text($h);
        $texts = [];
        foreach (Dom::all($xp, './/p', $n) as $para) {
            if (Dom::first($xp, './/a[contains(@class,"button")]', $para) === null && Dom::text($para) !== '') {
                $texts[] = Dom::text($para);
            }
        }
        if ($h1 === '' && $texts !== []) {
            $h1 = array_shift($texts);
        }
        $cta = Dom::first($xp, './/a[contains(@class,"wp-block-button__link") or contains(@class,"button") or contains(@class,"btn")]', $n);
        return Neutral::el('hero', [
            'h1' => $h1,
            'subtitle' => implode("\n", $texts),
            'cta_text' => $cta === null ? '' : Dom::text($cta),
            'cta_url' => $cta === null ? '' : Url::resolve($p->base(), $cta->getAttribute('href')),
            'image' => $image,
        ], Dom::label($n));
    }

    /** @return list<array<string,mixed>> */
    private static function pairs(DOMElement $n, \DOMXPath $xp, string $itemQ, string $qQ, string $aQ, string $base, string $src): array
    {
        $items = [];
        foreach (Dom::all($xp, $itemQ, $n) as $item) {
            $q = Dom::first($xp, $qQ, $item);
            $a = Dom::first($xp, $aQ, $item);
            if ($q !== null && $a !== null && Dom::text($q) !== '') {
                $html = Sanitizer::rich(Dom::innerHtml($a), $base);
                if (trim(strip_tags($html)) !== '') {
                    $items[] = ['q' => Dom::text($q), 'a' => $html];
                }
            }
        }
        return $items === [] ? [] : [Neutral::el('faq', ['items' => $items], $src)];
    }
}
