<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Html\ContentFinder;
use AgeCMS\Modules\Importer\Html\Dom;
use AgeCMS\Modules\Importer\Html\ElementParser;
use AgeCMS\Modules\Importer\Neutral;
use AgeCMS\Modules\Importer\Url;
use DOMDocument;
use DOMXPath;

/** One rendered HTML page of any site into a neutral page: meta fields, breadcrumbs, date and the main content. */
final class HtmlPage
{
    /** @param (callable(\DOMElement, ElementParser): ?list<array<string,mixed>>)|null $hook */
    public static function parse(string $html, string $url, Profile $profile, ?callable $hook = null): array
    {
        $doc = Dom::load($html);
        $xp = Dom::xpath($doc);
        $page = self::meta($doc, $xp, $url, $profile);

        $finder = new ContentFinder();
        $root = $finder->find($doc, $profile);
        $elements = $root === null ? [] : (new ElementParser(self::base($xp, $url), $hook ?? Widgets::hook(...)))->parse($root);
        $hasH1 = false;
        foreach ($elements as $el) {
            if (($el['type'] === 'heading' && $el['level'] === 1) || $el['type'] === 'hero') {
                $hasH1 = true;
                break;
            }
        }
        if (!$hasH1 && $finder->h1 !== '' && !$finder->h1Dropped) {
            array_unshift($elements, Neutral::el('heading', ['level' => 1, 'text' => $finder->h1], 'h1'));
        } elseif ($finder->h1Dropped && $page['title'] === $finder->h1) {
            // The h1 of the document belonged to a dropped part: the title comes from og:title or <title>.
            $page['title'] = self::stripSiteName(Dom::clean(Dom::meta($xp, 'og:title'))) ?: self::stripSiteName((string)$page['meta_title']);
        }
        if ($page['kind'] === 'post') {
            // The date of a post shown as a line of its own near the title ("23.01.2020 г.", "16 сентября 2013"): it is
            // the date of the post (when the page had no other) and not a paragraph of its text.
            foreach (array_slice($elements, 0, 4) as $i => $el) {
                $text = $el['type'] === 'paragraph' ? Neutral::text((string)$el['html']) : '';
                if ($text !== '' && mb_strlen($text) <= 30 && preg_match('~^[\d\s.:,а-яё-]+$~iu', $text) === 1 && ($date = self::date($text)) !== '') {
                    if ($page['published_at'] === '' || $page['published_at'] === $date) {
                        $page['published_at'] = $date;
                        array_splice($elements, $i, 1);
                    }
                    break;
                }
            }
        }
        $page['elements'] = $elements;
        if ($page['title'] === '' && $finder->h1 !== '' && !$finder->h1Dropped) {
            $page['title'] = $finder->h1;
        }
        return Neutral::page($page);
    }

    /**
     * Meta fields of a page: title (h1, og:title or the <title> without the site name), SEO fields, date, breadcrumbs.
     *
     * @return array<string,mixed>
     */
    public static function meta(DOMDocument $doc, DOMXPath $xp, string $url, Profile $profile): array
    {
        $titleTag = Dom::clean(Neutral::line(Dom::first($xp, '//title')?->textContent ?? ''));
        $h1 = ContentFinder::mainH1($xp);
        $title = $h1 !== null ? Dom::text($h1) : '';
        if (mb_strlen($title) > 200) {
            $title = '';
        }
        if ($title === '') {
            $title = self::stripSiteName(Dom::clean(Dom::meta($xp, 'og:title')));
        }
        if ($title === '') {
            $title = self::stripSiteName($titleTag);
        }
        $canonical = Dom::first($xp, "//link[translate(@rel,'CANONIL','canonil')='canonical']");
        $robots = strtolower(Dom::meta($xp, 'robots'));
        $og = Dom::meta($xp, 'og:image');
        $path = Url::oldPath($url);
        $date = '';
        foreach ($profile->date as $q) {
            $nodes = @$xp->query($q);
            if ($nodes !== false && $nodes->length > 0) {
                $date = self::date(Neutral::line($nodes->item(0)->textContent));
                if ($date !== '') {
                    break;
                }
            }
        }
        $kind = 'page';
        if ($profile->isPostPath(Url::oldPath($url, false)) || Dom::meta($xp, 'og:type') === 'article' && $date !== '') {
            $kind = 'post';
        }
        // WordPress says it in the body class; SEO plugins put og:type "article" on pages too.
        $bodyClass = ' ' . (Dom::first($xp, '//body')?->getAttribute('class') ?? '') . ' ';
        if (preg_match('~\s(page-id-\d+|page-template(-[\w-]+)?)\s~', $bodyClass) === 1) {
            $kind = 'page';
        } elseif (str_contains($bodyClass, ' single-post ')) {
            $kind = 'post';
        }
        return [
            'source_url' => $url,
            'old_path' => $path,
            'title' => $title,
            'meta_title' => $titleTag,
            'meta_description' => Dom::clean(Dom::meta($xp, 'description') ?: Dom::meta($xp, 'og:description')),
            'canonical' => $canonical === null ? '' : Dom::encodeUrl(Url::resolve($url, $canonical->getAttribute('href'))),
            'og_image' => $og === '' ? '' : Dom::encodeUrl(Url::resolve($url, $og)),
            'is_noindex' => str_contains($robots, 'noindex'),
            'published_at' => $kind === 'post' ? $date : '',
            'kind' => $kind,
            'parent_old_path' => self::breadcrumbParent($xp, $url, $profile),
        ];
    }

    /** Address relative links of the page resolve against: <base href> (MODX templates always set it) or the page itself. */
    public static function base(DOMXPath $xp, string $url): string
    {
        $tag = Dom::first($xp, '//base[@href]');
        if ($tag !== null) {
            $base = Url::resolve($url, $tag->getAttribute('href'));
            if ($base !== '' && preg_match('~^https?://~i', $base) === 1) {
                return $base;
            }
        }
        return $url;
    }

    private const MONTHS = ['янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'мая' => 5, 'май' => 5, 'июн' => 6, 'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12];

    /** ISO date from what a page shows: "2013-09-16T10:00", "16.09.2013", "16 сентября 2013" (with words around), or ''. */
    public static function date(string $s): string
    {
        $s = Dom::clean($s);
        if (preg_match('~(\d{1,2})\s+(янв|фев|мар|апр|мая|май|июн|июл|авг|сен|окт|ноя|дек)[а-яё.]*\s+(\d{4})~iu', $s, $m) === 1) {
            $month = self::MONTHS[mb_strtolower($m[2])];
            return checkdate($month, (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $month, $m[1]) : '';
        }
        if (preg_match('~(?<!\d)(\d{1,2})\.(\d{1,2})\.(\d{4})(?!\d)~', $s, $m) === 1) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
        }
        if (preg_match('~(?<!\d)(\d{4})-(\d{2})-(\d{2})~', $s, $m) === 1) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : '';
        }
        return mb_strlen($s) <= 40 ? Neutral::date($s) : '';
    }

    /** "О компании | Рога и копыта" gives "О компании". */
    public static function stripSiteName(string $title): string
    {
        foreach ([' | ', " \u{2014} ", " \u{2013} ", ' :: ', ' / ', ' - ', " \u{00BB} "] as $sep) {
            if (str_contains($title, $sep)) {
                $first = trim(explode($sep, $title)[0]);
                if (mb_strlen($first) >= 3) {
                    return $first;
                }
            }
        }
        return $title;
    }

    /** The old path of the parent from the breadcrumbs: the last crumb link before the current page, on the same site. */
    private static function breadcrumbParent(DOMXPath $xp, string $url, Profile $profile): string
    {
        $self = Url::key(Url::oldPath($url));
        $base = self::base($xp, $url);
        foreach ($profile->breadcrumbs as $q) {
            $box = Dom::first($xp, $q);
            if ($box === null) {
                continue;
            }
            $parent = '';
            foreach (Dom::all($xp, './/a[@href]', $box) as $a) {
                $abs = Dom::encodeUrl(Url::resolve($base, $a->getAttribute('href')));
                if ($abs === '' || !Url::sameSite($abs, $url)) {
                    continue;
                }
                $path = Url::oldPath($abs);
                if (Url::key($path) === $self) {
                    break;
                }
                if ($path !== '/') {
                    $parent = $path;
                }
            }
            if ($parent !== '') {
                return $parent;
            }
        }
        return '';
    }
}
