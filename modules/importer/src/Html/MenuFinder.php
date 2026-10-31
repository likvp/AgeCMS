<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Html;

use AgeCMS\Modules\Importer\Neutral;
use AgeCMS\Modules\Importer\Url;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Site menus from the home page: the biggest navigation in the header, and the one in the footer. Sub-menus (a menu
 * inside another candidate) do not compete with their menu, and copies for phones (burger, off-canvas) lose to the
 * desktop one. Where a menu sits is judged by its ancestors below <body>: themes put "header-v2 footer-v2" on <body>.
 */
final class MenuFinder
{
    private const CANDIDATES = "//nav|//*[@role='navigation']|//ul[contains(@class,'menu') or contains(@class,'nav')]|//*[contains(@class,'t-menu') or contains(@class,'t228__list') or contains(@class,'t451__list') or contains(@class,'t456__list')]";
    private const FOOTER = '~(^|[-_])footer([-_]|$)|^footer~i';
    private const HEADER = '~(^|[-_])(header|site-header|page-header|masthead|navbar|topbar|top-bar|top-menu|topmenu|main-menu|mainmenu|mega-menu)([-_]|$)|^t228|^t-menu~i';
    private const BOX = '~^(menu|main-?menu|top-?menu|header-?menu|menu-?popup|menu-?main|site-?menu|navigation|navbar|nav|main-?nav|topnav|site-?nav)$~i';
    private const MOBILE = '~(mobile|burger|offcanvas|off-canvas|mmenu|drawer|sidenav|slideout|fixed-menu|sticky-menu|hamburger)~i';

    /** @return array{header:list<array{title:string,url:string}>,footer:list<array{title:string,url:string}>} */
    public static function find(DOMDocument $doc, string $base): array
    {
        $xp = Dom::xpath($doc);
        $baseTag = Dom::first($xp, '//base[@href]');
        if ($baseTag !== null && ($b = Url::resolve($base, $baseTag->getAttribute('href'))) !== '') {
            $base = $b;
        }
        $all = Dom::all($xp, self::CANDIDATES);
        // Menus built from divs (popup menus, page builders) and lists named by id.
        foreach (Dom::all($xp, '//div[@class or @id]|//ul[@id]') as $n) {
            if (Dom::classMatches($n, self::BOX) || preg_match(self::BOX, $n->getAttribute('id')) === 1) {
                $all[] = $n;
            }
        }
        $ids = [];
        foreach ($all as $n) {
            $ids[spl_object_id($n)] = true;
        }
        $best = ['header' => [null, -INF], 'footer' => [null, -INF], 'any' => [null, -INF]];
        foreach ($all as $nav) {
            // Only the outermost candidate: a list inside a nav is that nav's (sub-)menu.
            $inner = false;
            for ($p = $nav->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                if (isset($ids[spl_object_id($p)])) {
                    $inner = true;
                    break;
                }
            }
            if ($inner) {
                continue;
            }
            $count = count(self::links($nav, $xp, $base));
            if ($count < 2) {
                continue;
            }
            $place = self::place($nav);
            $score = $count - (self::isMobile($nav) ? 100 : 0);
            $slot = $place === 'footer' ? 'footer' : ($place === 'header' ? 'header' : 'any');
            if ($score > $best[$slot][1]) {
                $best[$slot] = [$nav, $score];
            }
        }
        $header = $best['header'][0] ?? $best['any'][0];
        $footer = $best['footer'][0];
        if ($footer === null) {
            // No navigation in the footer: its link columns (OpenCart "Information", plain lists) make the footer menu.
            foreach (Dom::all($xp, "//footer|//*[@id='footer']|//*[@role='contentinfo']") as $box) {
                if (count(self::links($box, $xp, $base)) >= 3 && ($header === null || Dom::first($xp, 'ancestor-or-self::*', $header) !== $box)) {
                    $footer = $box;
                    break;
                }
            }
        }
        $headerLinks = $header === null ? [] : self::links($header, $xp, $base);
        $footerLinks = $footer === null || $footer === $header ? [] : self::links($footer, $xp, $base);
        if ($footerLinks !== [] && array_column($footerLinks, 'url') === array_column($headerLinks, 'url')) {
            $footerLinks = [];
        }
        return ['header' => $headerLinks, 'footer' => $footerLinks];
    }

    /** "header", "footer" or "" by the node and its ancestors below <body>. */
    private static function place(DOMElement $nav): string
    {
        for ($n = $nav; $n instanceof DOMElement; $n = $n->parentNode) {
            $tag = strtolower($n->nodeName);
            if ($tag === 'body' || $tag === 'html') {
                break;
            }
            $role = $n->getAttribute('role');
            if ($tag === 'footer' || $role === 'contentinfo' || Dom::classMatches($n, self::FOOTER) || preg_match(self::FOOTER, $n->getAttribute('id')) === 1) {
                return 'footer';
            }
            if ($tag === 'header' || $role === 'banner' || Dom::classMatches($n, self::HEADER) || preg_match(self::HEADER, $n->getAttribute('id')) === 1) {
                return 'header';
            }
        }
        return '';
    }

    private static function isMobile(DOMElement $nav): bool
    {
        for ($n = $nav, $i = 0; $n instanceof DOMElement && $i < 8; $n = $n->parentNode, $i++) {
            if (in_array(strtolower($n->nodeName), ['body', 'html'], true)) {
                break;
            }
            if (Dom::classMatches($n, self::MOBILE) || preg_match(self::MOBILE, $n->getAttribute('id')) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Top-level links of a menu. Sub-menus are left out when the top level is enough, otherwise taken in order.
     *
     * @return list<array{title:string,url:string}>
     */
    public static function links(DOMElement $nav, DOMXPath $xp, string $base): array
    {
        $all = Dom::all($xp, './/a[@href]', $nav);
        $top = array_values(array_filter($all, static fn(DOMElement $a): bool => Dom::first($xp, "ancestor::ul[ancestor::ul] | ancestor::*[contains(@class,'sub-menu') or contains(@class,'submenu') or contains(@class,'dropdown-menu') or contains(@class,'children')]", $a) === null));
        $use = count($top) >= 3 ? $top : $all;
        $out = [];
        $seen = [];
        foreach ($use as $a) {
            $title = self::title($a);
            $url = Url::resolve($base, $a->getAttribute('href'));
            if (preg_match('~^https?://~i', $url) === 1) {
                $url = Dom::encodeUrl($url);
            }
            if ($title === '' || $url === '' || mb_strlen($title) > 60 || isset($seen[$url]) || preg_match('~^(javascript|tel|mailto|sms|skype|viber|whatsapp|tg):~i', $url) === 1) {
                continue;
            }
            $seen[$url] = true;
            $out[] = ['title' => $title, 'url' => $url];
            if (count($out) >= 30) {
                break;
            }
        }
        return $out;
    }

    /** Text of a menu link: without icon CSS, and once when a theme doubles it for a hover effect ("Projects Projects"). */
    private static function title(DOMElement $a): string
    {
        $title = Neutral::line(Dom::visibleText($a));
        if (preg_match('~^(.{2,60}?)\s+\1$~u', $title, $m) === 1) {
            $title = $m[1];
        }
        return $title;
    }
}
