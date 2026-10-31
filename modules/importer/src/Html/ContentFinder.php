<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Html;

use AgeCMS\Modules\Importer\Extract\Profile;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Finds the main content of a page: drops the site frame (header, menus, footer, sidebars, search, cookie banners,
 * scripts), then takes the container of the CMS profile, then the usual containers (main, article, [role=main], #content),
 * and when none of them holds enough text, scores blocks by text density the way readability does.
 */
final class ContentFinder
{
    private const MIN_TEXT = 120;
    private const JUNK_CLASS = '~^(cookies?|cookie[-_].*|.*[-_]cookies?|gdpr.*|cc-window|cc-banner|breadcrumbs?|share|shares|sharing|share-buttons|social|socials|social-links|social-icons|sidebar|widget|widgets|widget-area|comments?|comment-respond|comments-area|related|related-posts|popup|modal|search-form|searchform|pagination|nav-links|post-navigation|skip-link|screen-reader-text|visually-hidden|sr-only|hidden|d-none|print-only|advert|ads|banner-ads|back-to-top|to-top|scrollup|subscribe|newsletter|overlay|loader|preloader|dialog|lightbox|skip-links?|bx-panel)$~i';
    private const SLIDER = '~(^|[-_])(slider|slides?|carousel|swiper|slick|owl|fotorama|modal|popup|lightbox)([-_]|$)~i';
    private const SIDE = '~^((left|right|side)[-_]?(col|column|bar|block|menu|nav|panel|area|part|content)s?|sidebar.*|leftcol|rightcol|(col|column)[-_]?(left|right|lt|rt)|aside|basket.*|mini[-_]?cart.*)$~i';
    /** Navigation by name; dropped when it is mostly links. */
    private const NAVISH = '~(^|[-_])(nav|navs|menu|menus|navigation|navbar|pager|pagenav|breadcrumbs?)([-_]|$)~i';
    /** Responsive classes that show an element hidden on phones (Tailwind "hidden md:flex", Bootstrap "d-none d-md-block"). */
    private const SHOWN = '~^((sm|md|lg|xl|2xl):(block|flex|grid|inline|inline-block|inline-flex|table|contents)|d-(sm|md|lg|xl|xxl)-(block|flex|grid|inline|inline-block|inline-flex|table)|visible-(xs|sm|md|lg))$~';
    private const NEGATIVE = '~(comment|meta|footer|footnote|sidebar|sponsor|ad-|advert|banner|menu|nav|widget|share|social|related|cookie|popup|modal|breadcrumb|pagination|header|copyright)~i';
    private const POSITIVE = '~(content|article|main|post|text|body|entry|page|story|description|detail|news)~i';

    /** h1 text of the page as found before the frame was removed (it often sits in a title banner outside the content). */
    public string $h1 = '';
    /**
     * True when that h1 is not a title to show: it was inside a part dropped as junk, or inside the content where the
     * parser left it out on purpose (the title of a product card in a loop widget, a sentence set as h1).
     */
    public bool $h1Dropped = false;

    /**
     * @return DOMElement|null the content root (a node of $doc, already cleaned of junk)
     */
    public function find(DOMDocument $doc, Profile $profile): ?DOMElement
    {
        $xp = Dom::xpath($doc);
        $h1 = self::mainH1($xp);
        $this->h1 = $h1 === null ? '' : Dom::text($h1);

        foreach ($profile->junk as $q) {
            foreach (Dom::all($xp, $q) as $n) {
                Dom::remove($n);
            }
        }
        $body = Dom::first($xp, '//body');
        self::removeJunk($body ?? $doc->documentElement, $xp);
        $bodyLen = $body === null ? 0 : mb_strlen(Dom::text($body));
        // A container holds the content when it has most of the text of the page or the page title.
        $covers = static function (DOMElement $n, int $min) use ($bodyLen, $h1): bool {
            $len = mb_strlen(Dom::text($n));
            if ($len < $min) {
                return false;
            }
            return $len >= 0.25 * $bodyLen || $len >= 1500 || ($h1 !== null && self::isInside($h1, $n));
        };

        $root = null;
        // Profile containers first: a CMS template is the most reliable hint.
        foreach ($profile->content as $q) {
            $best = $this->pick(Dom::all($xp, $q), $bodyLen, $body);
            if ($best !== null && $covers($best, 40)) {
                $root = $best;
                break;
            }
        }
        if ($root === null) {
            foreach (["//main", "//*[@role='main']", "//*[@id='content']", "//*[@id='main-content']", "//*[" . Dom::cls('entry-content') . "]", "//*[" . Dom::cls('post-content') . "]", "//*[" . Dom::cls('page-content') . "]", "//*[@id='main']", "//*[" . Dom::cls('content') . "]", "//*[" . Dom::cls('main') . "]"] as $q) {
                $best = $this->pick(Dom::all($xp, $q), $bodyLen, $body);
                if ($best !== null && $covers($best, self::MIN_TEXT)) {
                    $root = $best;
                    break;
                }
            }
        }
        if ($root === null) {
            $articles = Dom::all($xp, '//article');
            if (count($articles) === 1 && $covers($articles[0], self::MIN_TEXT)) {
                $root = $articles[0];
            }
        }
        if ($root === null && ($scored = $this->byDensity($xp)) !== null) {
            // A one-page site is a row of sections: climb until most of the text is in.
            $root = $scored;
            while ($root->parentNode instanceof DOMElement && $bodyLen > 0 && mb_strlen(Dom::text($root)) < 0.6 * $bodyLen && $root !== $body) {
                $root = $root->parentNode;
            }
        }
        $root ??= $body;
        // A small page (a stub, contacts) keeps everything that is not the frame.
        if ($root !== null && $body !== null && $root !== $body && $bodyLen < 1500 && mb_strlen(Dom::text($root)) < 0.5 * $bodyLen) {
            $root = $body;
        }
        if ($root !== null) {
            self::pruneColumns($root, $xp, $h1);
        }
        if ($h1 !== null) {
            $attached = false;
            for ($n = $h1; $n !== null; $n = $n->parentNode) {
                if ($n instanceof DOMDocument) {
                    $attached = true;
                    break;
                }
            }
            $this->h1Dropped = !$attached || $root !== null && self::isInside($h1, $root);
        }
        return $root;
    }

    /**
     * The h1 of the page: the first one outside the site frame and outside sliders and popups (a slider on the home
     * page often has an h1 per slide, a site header may hold the logo as h1). Null when there is none.
     */
    public static function mainH1(DOMXPath $xp): ?DOMElement
    {
        foreach (Dom::all($xp, '//h1') as $h) {
            if (Dom::text($h) === '') {
                continue;
            }
            $ok = true;
            for ($n = $h->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
                $tag = strtolower($n->nodeName);
                if ($tag === 'body') {
                    break;
                }
                if (in_array($tag, ['header', 'footer', 'nav', 'aside', 'dialog'], true) && Dom::first($xp, 'ancestor::main|ancestor::article', $n) === null
                    || in_array($n->getAttribute('role'), ['banner', 'contentinfo', 'navigation', 'dialog'], true)
                    || preg_match('~^SITE_(HEADER|FOOTER)$~', $n->getAttribute('id')) === 1
                    || Dom::classMatches($n, self::SLIDER)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $h;
            }
        }
        return null;
    }

    private static function isInside(DOMNode $inner, DOMNode $outer): bool
    {
        for ($n = $inner; $n !== null; $n = $n->parentNode) {
            if ($n === $outer) {
                return true;
            }
        }
        return false;
    }

    /**
     * Side columns inside the content root: category lists, baskets and "other items" blocks next to the main column
     * (Bitrix and MODX templates often wrap all three columns in one #content). A column goes when it does not hold the
     * page title and is mostly links or short.
     */
    private static function pruneColumns(DOMElement $root, DOMXPath $xp, ?DOMElement $h1): void
    {
        $drop = [];
        $rootLen = max(1, mb_strlen(Dom::text($root)));
        foreach (Dom::all($xp, './/*[@class or @id]', $root) as $n) {
            if (!Dom::classMatches($n, self::SIDE) && preg_match(self::SIDE, $n->getAttribute('id')) !== 1) {
                continue;
            }
            if ($h1 !== null && self::isInside($h1, $n)) {
                continue;
            }
            $len = mb_strlen(Dom::text($n));
            if ($len > 0.5 * $rootLen) {
                // Most of the text: the main column whatever its name (Aspro calls it "right_block").
                continue;
            }
            if ($len < 300 || self::linkDensity($n) >= 0.3 || Dom::first($xp, './/form|.//input', $n) !== null) {
                $drop[] = $n;
            }
        }
        foreach ($drop as $n) {
            if ($n->parentNode !== null) {
                Dom::remove($n);
            }
        }
    }

    /**
     * The container among the matches of one query. Usually the longest; when the class repeats on sibling sections of
     * a one-page site (several .content blocks, none with most of the text) their common ancestor holds the page.
     *
     * @param list<DOMElement> $nodes
     */
    private function pick(array $nodes, int $bodyLen, ?DOMElement $body): ?DOMElement
    {
        $best = $this->longest($nodes);
        if ($best === null || $bodyLen === 0 || mb_strlen(Dom::text($best)) >= 0.6 * $bodyLen) {
            return $best;
        }
        $outer = [];
        foreach ($nodes as $n) {
            if (mb_strlen(Dom::text($n)) < 100) {
                continue;
            }
            foreach ($nodes as $m) {
                if ($m !== $n && self::isInside($n, $m)) {
                    continue 2;
                }
            }
            $outer[] = $n;
        }
        if (count($outer) < 2) {
            return $best;
        }
        $common = $outer[0];
        foreach (array_slice($outer, 1) as $n) {
            while ($common !== null && !self::isInside($n, $common)) {
                $common = $common->parentNode instanceof DOMElement ? $common->parentNode : null;
            }
        }
        if ($common === null || in_array(strtolower($common->nodeName), ['html', 'body'], true)) {
            return $body ?? $best;
        }
        return $common;
    }

    /** @param list<DOMElement> $nodes */
    private function longest(array $nodes): ?DOMElement
    {
        $best = null;
        $bestLen = -1;
        foreach ($nodes as $n) {
            $len = mb_strlen(Dom::text($n));
            if ($len > $bestLen) {
                $best = $n;
                $bestLen = $len;
            }
        }
        return $best;
    }

    /** Drops the frame and the noise inside $root. */
    public static function removeJunk(?DOMElement $root, DOMXPath $xp): void
    {
        if ($root === null) {
            return;
        }
        $drop = [];
        foreach (Dom::all($xp, './/script|.//style|.//noscript|.//template|.//link|.//meta|.//nav|.//aside|.//footer|.//*[@role="navigation"]|.//*[@role="contentinfo"]|.//*[@role="search"]|.//*[@aria-hidden="true"]|.//*[@hidden]|.//dialog', $root) as $n) {
            $drop[] = $n;
        }
        // The site header: a <header> outside the article (one inside main/article is the page title block and stays).
        foreach (Dom::all($xp, './/header|.//*[@role="banner"]', $root) as $n) {
            if (Dom::first($xp, 'ancestor::main|ancestor::article|ancestor::*[@role="main"]', $n) === null || Dom::first($xp, './/nav', $n) !== null) {
                $drop[] = $n;
            }
        }
        foreach (Dom::all($xp, './/*[@class or @id]', $root) as $n) {
            if (Dom::classMatches($n, self::JUNK_CLASS) || preg_match(self::JUNK_CLASS, $n->getAttribute('id')) === 1) {
                if (!Dom::classMatches($n, self::SHOWN)) {
                    $drop[] = $n;
                }
            } elseif (preg_match('~display\s*:\s*none~i', $n->getAttribute('style')) === 1) {
                $drop[] = $n;
            } elseif ((Dom::classMatches($n, self::NAVISH) || preg_match(self::NAVISH, $n->getAttribute('id')) === 1) && Dom::first($xp, './/a', $n) !== null) {
                $len = mb_strlen(Dom::text($n));
                if ($len < 2000 && self::linkDensity($n) >= 0.5) {
                    $drop[] = $n;
                }
            }
        }
        // A footer drawn without the footer tag (div.footer, .site-footer, .footer_phone, a copyright line): the frame too.
        foreach (Dom::all($xp, './/*[@class or @id]', $root) as $n) {
            $name = $n->getAttribute('class') . ' ' . $n->getAttribute('id');
            if (preg_match('~(^|\s)(site[-_]?footer|page[-_]?footer|footer|footer[-_][\w-]+|[\w-]+[-_]footer|copyrights?|copyright[-_][\w-]+)(\s|$)~i', $name) !== 1
                || preg_match('~(^|\s)(card|panel|modal|table|entry|post|article|blockquote|figure|comment|widget|section|wp-block)[-_]footer~i', $name) === 1
                || Dom::first($xp, './/h1', $n) !== null) {
                continue;
            }
            $len = mb_strlen(Dom::text($n));
            $inArticle = Dom::first($xp, 'ancestor::main|ancestor::article|ancestor::*[@role="main"]', $n) !== null;
            if ($len < 3000 && (!$inArticle || $len < 400 && preg_match('~(©|&copy;|\(c\)\s*\d{4}|все права защищены)~iu', Dom::text($n)) === 1)) {
                $drop[] = $n;
            }
        }
        foreach (Dom::all($xp, './/p|.//div|.//span', $root) as $n) {
            // A copyright line on its own: "© 2004 - 2026 Клиника", "Все права защищены".
            $text = Dom::text($n);
            if (mb_strlen($text) < 160 && preg_match('~^\s*(©|\(c\)|copyright)|^[^.!?]{0,80}©\s*\d{4}|все права защищены~iu', $text) === 1 && Dom::first($xp, './/h1|.//h2|.//table|.//img', $n) === null) {
                $drop[] = $n;
            }
        }
        foreach (Dom::all($xp, './/form', $root) as $form) {
            if (Dom::first($xp, './/input[@type="search" or @name="s" or @name="q" or @name="search" or @name="query"]', $form) !== null && Dom::first($xp, './/textarea', $form) === null) {
                $drop[] = $form;
            }
        }
        foreach ($drop as $n) {
            if ($n->parentNode !== null && $n !== $root) {
                Dom::remove($n);
            }
        }
        foreach (Dom::all($xp, './/comment()', $root) as $c) {
            Dom::remove($c);
        }
    }

    /** Readability-style scoring: paragraphs give points to their parent and grandparent, links and bad class names take them away. */
    private function byDensity(DOMXPath $xp): ?DOMElement
    {
        $scores = [];
        $nodes = [];
        $add = static function (DOMElement $n, float $points) use (&$scores, &$nodes): void {
            $id = spl_object_id($n);
            $nodes[$id] = $n;
            $scores[$id] = ($scores[$id] ?? self::initial($n)) + $points;
        };
        foreach (Dom::all($xp, '//p|//td|//pre|//div[not(.//p) and not(.//div) and string-length(normalize-space(.)) > 80]') as $p) {
            $text = Dom::text($p);
            $len = mb_strlen($text);
            if ($len < 25) {
                continue;
            }
            $points = 1 + substr_count($text, ',') + min(3, intdiv($len, 100));
            $parent = $p->parentNode;
            if ($parent instanceof DOMElement) {
                $add($parent, $points);
                $grand = $parent->parentNode;
                if ($grand instanceof DOMElement) {
                    $add($grand, $points / 2);
                }
            }
        }
        $best = null;
        $bestScore = 0.0;
        foreach ($scores as $id => $raw) {
            $score = $raw * (1 - self::linkDensity($nodes[$id]));
            if ($score > $bestScore) {
                $best = $nodes[$id];
                $bestScore = $score;
            }
        }
        if (!$best instanceof DOMElement) {
            return null;
        }
        // Siblings with a good score belong to the same article (a text split into several columns).
        $parent = $best->parentNode;
        if ($parent instanceof DOMElement && $parent->nodeName !== 'body') {
            $good = 0;
            foreach ($parent->childNodes as $sibling) {
                if ($sibling !== $best && $sibling instanceof DOMElement && ($scores[spl_object_id($sibling)] ?? 0) > $bestScore * 0.3) {
                    $good++;
                }
            }
            if ($good > 0) {
                return $parent;
            }
        }
        return $best;
    }

    private static function initial(DOMElement $n): float
    {
        $score = match (strtolower($n->nodeName)) {
            'div', 'article', 'section', 'main' => 5.0,
            'pre', 'td', 'blockquote' => 3.0,
            'ul', 'ol', 'dl', 'form' => -3.0,
            'h1', 'h2', 'h3', 'h4', 'th' => -5.0,
            default => 0.0,
        };
        $name = $n->getAttribute('class') . ' ' . $n->getAttribute('id');
        if (preg_match(self::NEGATIVE, $name) === 1) {
            $score -= 25;
        }
        if (preg_match(self::POSITIVE, $name) === 1) {
            $score += 25;
        }
        return $score;
    }

    private static function linkDensity(DOMElement $n): float
    {
        $len = mb_strlen(Dom::text($n));
        if ($len === 0) {
            return 1.0;
        }
        $links = 0;
        foreach ($n->getElementsByTagName('a') as $a) {
            $links += mb_strlen(Dom::text($a));
        }
        return min(1.0, $links / $len);
    }
}
