<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Html\Dom;
use AgeCMS\Modules\Importer\Html\ElementParser;
use AgeCMS\Modules\Importer\Html\Sanitizer;
use AgeCMS\Modules\Importer\Neutral;
use AgeCMS\Modules\Importer\Url;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * A published Tilda page: the records (.t-rec with data-record-type) in order. Covers become the first screen, titles and
 * texts headings and paragraphs, accordions FAQ, forms a form, sliders a gallery. Menu, footer, popup and cookie records
 * are not content. A Zero Block (T396) has absolute layout: its texts and pictures are taken in order from top to bottom
 * and the page gets a note to arrange them by hand.
 *
 * Each record is parsed on its own: Tilda pages often have markup the HTML parser repairs badly (an unclosed tag in the
 * shared header swallows the rest of the page), so one broken record never takes the others with it. Records of the
 * shared header and footer (between the <!--header--> and <!--footer--> marks) give the menus, not content. A post of
 * Tilda Feeds (/tpost/...) becomes a post with its date; a product of the Tilda store (/tproduct/...) is marked
 * "skip": the store is not imported.
 */
final class Tilda
{
    private const MENU = '~^(t199|t228|t229|t280|t282|t450|t451|t456|t461|t466|t481|t770|t815|t821|t830|t967|t975|t1093|t1136|t-menu|t-menuburger|t-menusub|t-menuwidgeticons|tmenu-mobile)(__|$)~';
    private const HEADING = '~^(t-title|t-heading|t-section__title|t-name|t-card__title|t-product__title|t-store__card__title|t-feed__post-title)($|_)~';
    private const TEXT = '~^(t-descr|t-text|t-uptitle|t-section__descr|t-card__descr|t-subtitle|t-product__descr|t-feed__post-descr)($|_)|^t\d+__(text|descr|subtitle)$~';
    private const SKIP = '~^(t706|t-store__prod-popup|t1002|t898|t972|t657|t-feed__post-popup__arrow)(__|$)~';
    private const REC = '~<div\b(?=[^>]*\bid="rec\d+")(?=[^>]*\bclass="[^"]*\bt-rec\b)[^>]*>~';

    /** @return array<string,mixed>|null null when the page has no Tilda records */
    public static function parsePage(string $html, string $url): ?array
    {
        $chunks = self::chunks($html);
        if ($chunks === []) {
            return null;
        }
        $doc = Dom::load($html);
        $xp = Dom::xpath($doc);
        $profile = Profile::for('tilda');
        $page = HtmlPage::meta($doc, $xp, $url, $profile);
        $path = Url::oldPath($url, false);
        $menus = ['header' => [], 'footer' => []];
        $notes = [];

        // A post of Tilda Feeds: one record with the post in a "popup" shown as a page.
        if (preg_match('~(^|/)tpost/~', $path) === 1 || str_contains($html, 't-feed__post-popup__content')) {
            foreach ($chunks as [$chunk]) {
                $cdoc = Dom::load($chunk);
                $cxp = Dom::xpath($cdoc);
                if (($box = Dom::first($cxp, '//*[contains(concat(" ", normalize-space(@class), " "), " t-feed__post-popup ")]')) !== null) {
                    $post = self::post($box, $cxp, $url, $page);
                    $post['elements'] = self::ownDomain((array)$post['elements'], $url);
                    return Neutral::page($post);
                }
            }
        }
        // A product of the Tilda store: the store is not imported.
        if (preg_match('~(^|/)tproduct/~', $path) === 1) {
            $page['skip'] = 'товар магазина Tilda: магазин не переносится';
            $page['notes'] = [['what' => 'Товар магазина Tilda', 'why' => 'карточки магазина не переносятся (только страницы и записи блога)', 'source' => 'tproduct']];
            $page['elements'] = [];
            return Neutral::page($page);
        }
        // Zero Blocks shown inside popups (T1093 and alike name them by id in their styles).
        preg_match_all('~#rec(\d+)\[data-record-type="396"\]~', $html, $m);
        $popupZero = array_fill_keys($m[1], true);

        $elements = [];
        $footer = [];
        $firstH1 = '';
        $last = count($chunks) - 1;
        // Anchors of the page (<a name="service">, often a record of its own, T215): the next element that comes gets
        // them, so that a menu item /#service can lead to the block it went to.
        $anchorNames = [];
        $anchorAt = 0;
        $attach = static function () use (&$elements, &$anchorNames, &$anchorAt): void {
            if ($anchorNames !== [] && isset($elements[$anchorAt])) {
                $elements[$anchorAt]['anchor'] = array_values(array_unique(array_merge((array)($elements[$anchorAt]['anchor'] ?? []), $anchorNames)));
                $anchorNames = [];
            }
        };
        foreach ($chunks as $i => [$chunk, $zone]) {
            $attach();
            $cdoc = Dom::load($chunk);
            $cxp = Dom::xpath($cdoc);
            $rec = Dom::first($cxp, '//div[contains(concat(" ", normalize-space(@class), " "), " t-rec ")]');
            if ($rec === null) {
                continue;
            }
            foreach (Dom::all($cxp, '//script|//style|//noscript|//template') as $n) {
                Dom::remove($n);
            }
            if ($zone === 'main' && $firstH1 === '' && ($h1 = Dom::first($cxp, '//h1', $rec)) !== null) {
                $firstH1 = self::text($h1);
            }
            $id = preg_replace('~\D~', '', $rec->getAttribute('id')) ?? '';
            $type = $rec->getAttribute('data-record-type');
            $label = 'rec' . $id . ($type !== '' ? ' (тип ' . $type . ')' : '');
            $text = Dom::text($rec);
            if ($zone === 'main') {
                foreach (Dom::all($cxp, './/a[@name]', $rec) as $a) {
                    if (($name = trim($a->getAttribute('name'))) !== '') {
                        if ($anchorNames === []) {
                            $anchorAt = count($elements);
                        }
                        $anchorNames[] = $name;
                    }
                }
            }
            if ($zone !== 'main') {
                // The shared header and footer: menus only.
                if (!self::any($rec, $cxp, '~^t-popup~')) {
                    if ($zone === 'footer' && !self::any($rec, $cxp, self::MENU) && !self::any($rec, $cxp, self::SKIP)) {
                        $footer[] = [$cxp, $rec, $label];
                    }
                    $links = self::links($rec, $cxp, $url);
                    if ($zone === 'header' && $menus['header'] === [] && count($links) >= 2) {
                        $menus['header'] = $links;
                    } elseif ($zone === 'footer' && count($links) > count($menus['footer'])) {
                        $menus['footer'] = $links;
                    }
                }
                continue;
            }
            if (isset($popupZero[$id]) || $rec->getAttribute('data-parenttplid') !== '' || Dom::classMatches($rec, '~^t-screenmax-~')) {
                continue;
            }
            if (self::any($rec, $cxp, self::MENU)) {
                $links = self::links($rec, $cxp, $url);
                if ($menus['header'] === [] && $links !== []) {
                    $menus['header'] = $links;
                }
                continue;
            }
            if (self::any($rec, $cxp, '~^t-popup~')) {
                if (Dom::first($cxp, '//form', $rec) !== null) {
                    $notes[] = ['what' => 'Всплывающее окно с формой', 'why' => 'всплывающие окна не переносятся, заявки принимает форма сайта', 'source' => $label];
                }
                continue;
            }
            if (self::any($rec, $cxp, self::SKIP)) {
                continue;
            }
            if (self::any($rec, $cxp, '~^(t-store|js-store)(__|$)~')) {
                $notes[] = ['what' => 'Каталог магазина Tilda ' . $label, 'why' => 'товары магазина собираются скриптом и не переносятся', 'source' => $label];
                continue;
            }
            if (mb_strlen($text) < 600 && preg_match('~(cookie|куки|персональных данных.*(использ|сайт))~iu', $text) === 1 && Dom::first($cxp, '//form', $rec) === null && ($i >= $last - 1 || mb_strlen($text) < 300)) {
                continue;
            }
            if ($i >= $last - 1 && $i > 0 && mb_strlen($text) < 600 && preg_match('~(©|&copy;|copyright|все права)~iu', $text) === 1) {
                $menus['footer'] = self::links($rec, $cxp, $url);
                continue;
            }
            if ($type === '396' || self::any($rec, $cxp, '~^t396$~')) {
                // A Zero Block drawn as the site header (logo and a row of links) is a menu.
                if ($elements === [] && ($links = self::zeroMenu($rec, $cxp, $url)) !== null) {
                    if ($menus['header'] === []) {
                        $menus['header'] = $links;
                    }
                    continue;
                }
                [$els, $note] = self::zero($rec, $cxp, $url, $label);
                $elements = array_merge($elements, $els);
                if ($els !== []) {
                    $notes[] = $note;
                }
                continue;
            }
            if (($cover = Dom::first($cxp, '//*[contains(concat(" ", normalize-space(@class), " "), " t-cover ")]', $rec)) !== null) {
                if (($el = self::cover($cover, $cxp, $url, $label)) !== null) {
                    $elements[] = $el;
                }
                continue;
            }
            if (($faq = self::accordion($rec, $cxp, $url, $label)) !== null) {
                $elements = array_merge($elements, $faq);
                continue;
            }
            if (($gallery = self::gallery($rec, $cxp, $url, $label)) !== null) {
                $elements = array_merge($elements, $gallery);
                continue;
            }
            if (($form = Dom::first($cxp, '//form', $rec)) !== null) {
                $before = [];
                self::roles($rec, $cxp, $url, $before, $label, true);
                $parsed = (new ElementParser($url))->parseNode($form);
                $elements = array_merge($elements, $before, $parsed);
                continue;
            }
            if (($list = self::linkCards($rec, $cxp, $url, $label)) !== null) {
                $elements = array_merge($elements, $list);
                continue;
            }
            $els = [];
            self::roles($rec, $cxp, $url, $els, $label);
            $elements = array_merge($elements, $els);
        }
        $attach();
        // A page with nothing of its own (contacts drawn in the shared footer): the footer is its content.
        if ($elements === [] && $footer !== []) {
            foreach ($footer as [$cxp, $rec, $label]) {
                if (($type = $rec->getAttribute('data-record-type')) === '396' || self::any($rec, $cxp, '~^t396$~')) {
                    [$els] = self::zero($rec, $cxp, $url, $label);
                } else {
                    $els = [];
                    self::roles($rec, $cxp, $url, $els, $label, true);
                }
                $elements = array_merge($elements, $els);
            }
            if ($elements !== []) {
                $notes[] = ['what' => 'Содержимое из подвала сайта', 'why' => 'у страницы нет своих блоков, взяты тексты общего подвала: проверьте страницу', 'source' => 'footer'];
            }
        }
        $footerText = 0;
        foreach ($footer as [, $rec]) {
            $footerText += mb_strlen(Dom::text($rec));
        }
        if ($footerText >= 40 && Url::key($path) === '/' && !in_array('footer', array_column($notes, 'source'), true)) {
            $notes[] = ['what' => 'Общий подвал сайта', 'why' => 'тексты подвала Tilda (контакты, реквизиты) не переносятся в страницы: задайте их в подвале темы', 'source' => 'footer'];
        }
        $page['elements'] = self::images(ElementParser::tidy($elements));
        // The title: the first screen at the top, the first <h1> of the page, else <title> without the site name.
        $title = '';
        foreach (array_slice($page['elements'], 0, 3) as $el) {
            if ($el['type'] === 'hero' && $el['h1'] !== '') {
                $title = $el['h1'];
                break;
            }
        }
        if ($title === '') {
            $title = mb_strlen($firstH1) <= 150 ? $firstH1 : '';
        }
        $page['title'] = $title !== '' ? $title : (HtmlPage::stripSiteName(Neutral::line(Dom::first($xp, '//title')?->textContent ?? '')) ?: $page['title']);
        if ($page['elements'] === [] && $notes === []) {
            $page['skip'] = 'страница без содержимого (служебная: шапка, подвал или пустая)';
            $notes[] = ['what' => 'Пустая страница', 'why' => 'на странице нет блоков с содержимым: это служебная страница Tilda (шапка, подвал) или пустая', 'source' => 'page'];
        }
        $page['elements'] = self::ownDomain($page['elements'], $url);
        foreach ($menus as $k => $items) {
            $menus[$k] = self::ownDomain($items, $url);
        }
        $page['notes'] = $notes;
        $page['menus'] = $menus;
        $page['og_image'] = self::imageUrl((string)$page['og_image']);
        if (($node = Dom::first($xp, '//*[@data-tilda-page-id]')) !== null && ($pid = $node->getAttribute('data-tilda-page-id')) !== '') {
            $page['tilda_page_id'] = $pid;
            // Every Tilda page also answers at /page123.html: that address gets a redirect too.
            if ($path !== '/page' . $pid . '.html') {
                $page['aliases'][] = '/page' . $pid . '.html';
            }
        }
        return Neutral::page($page);
    }

    /**
     * Records of a page as separate pieces of HTML with their zone (header, main, footer). A piece runs from the opening
     * tag of a record to the next record or zone mark; the parser closes what is left open.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function chunks(string $html): array
    {
        if (preg_match_all(self::REC, $html, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $marks = [];
        foreach (['<!--header-->' => 'header', '<!--/header-->' => 'main', '<!--footer-->' => 'footer', '<!--/footer-->' => 'main', '<!--/allrecords-->' => 'end'] as $mark => $zone) {
            $pos = strpos($html, $mark);
            if ($pos !== false) {
                $marks[$pos] = $zone;
            }
        }
        // Without the marks, the <header id="t-header"> and <footer id="t-footer"> tags tell the zones.
        if ($marks === [] || !in_array('header', $marks, true) && !in_array('footer', $marks, true)) {
            foreach (['~<header\b[^>]*\bid="t-header"~' => 'header', '~<footer\b[^>]*\bid="t-footer"~' => 'footer'] as $re => $zone) {
                if (preg_match($re, $html, $mm, PREG_OFFSET_CAPTURE) === 1) {
                    $marks[(int)$mm[0][1]] = $zone;
                }
            }
        }
        ksort($marks);
        $starts = array_map(static fn(array $x): int => (int)$x[1], $m[0]);
        $bounds = array_merge($starts, array_keys($marks), [strlen($html)]);
        sort($bounds);
        $out = [];
        foreach ($starts as $start) {
            $end = strlen($html);
            foreach ($bounds as $b) {
                if ($b > $start) {
                    $end = $b;
                    break;
                }
            }
            $zone = 'main';
            foreach ($marks as $pos => $z) {
                if ($pos > $start) {
                    break;
                }
                $zone = $z;
            }
            if ($zone === 'end') {
                continue;
            }
            $out[] = [substr($html, $start, $end - $start), $zone];
        }
        return $out;
    }

    /** Text of a node with line breaks as spaces ("Строка<br>вторая" is not "Строкавторая"). */
    public static function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $html = preg_replace('~<br\s*/?>|</(div|p|li|h[1-6])>~i', ' ', Dom::innerHtml($node)) ?? '';
        return Neutral::line(strip_tags($html));
    }

    /**
     * The original of a Tilda picture: thumbnails and lazy-load copies (thb.tildacdn.com/.../-/resize/20x/a.jpg,
     * optim.tildacdn.com/.../-/format/webp/a.jpg, .../-/empty/a.jpg) come from static.tildacdn.com/<id>/a.jpg.
     */
    public static function imageUrl(string $src): string
    {
        if (preg_match('~^(?:https?:)?//(?:[a-z0-9-]+\.)?(tildacdn\.[a-z]+|tildaapi\.[a-z]+)/(tild[0-9a-f]{4}-[0-9a-f-]+)/(?:-/[^/]+/(?:[^/]*/)*?)?([^/?#]+)$~i', $src, $m) === 1) {
            return 'https://static.' . strtolower($m[1]) . '/' . $m[2] . '/' . $m[3];
        }
        return $src;
    }

    /**
     * Links to the technical address of a Tilda project (name.tilda.ws) lead to the same site: they get its address,
     * so links and menu items drawn with that domain become internal.
     *
     * @param list<array<string,mixed>> $items elements or menu items
     * @return list<array<string,mixed>>
     */
    private static function ownDomain(array $items, string $url): array
    {
        if (str_ends_with(Url::host($url), '.tilda.ws')) {
            return $items;
        }
        $origin = Url::origin($url);
        $fix = static fn(string $s): string => preg_replace('~https?://[a-z0-9-]+\.tilda\.ws(?=[/"\'#?]|$)~i', $origin, $s) ?? $s;
        foreach ($items as &$item) {
            foreach (['url', 'cta_url', 'html'] as $k) {
                if (isset($item[$k]) && is_string($item[$k]) && str_contains($item[$k], '.tilda.ws')) {
                    $item[$k] = $fix($item[$k]);
                }
            }
            if (isset($item['items']) && is_array($item['items'])) {
                foreach ($item['items'] as &$li) {
                    if (is_string($li) && str_contains($li, '.tilda.ws')) {
                        $li = $fix($li);
                    }
                }
                unset($li);
            }
        }
        unset($item);
        return $items;
    }

    /** Originals of all pictures of the elements (src, image, gallery, src in html). @param list<array<string,mixed>> $els */
    private static function images(array $els): array
    {
        foreach ($els as &$el) {
            foreach (['src', 'image'] as $k) {
                if (isset($el[$k]) && is_string($el[$k]) && ($el['type'] !== 'embed')) {
                    $el[$k] = self::imageUrl($el[$k]);
                }
            }
            if (isset($el['images']) && is_array($el['images'])) {
                foreach ($el['images'] as &$img) {
                    $img['src'] = self::imageUrl((string)$img['src']);
                }
                unset($img);
            }
            if (isset($el['html']) && is_string($el['html']) && str_contains($el['html'], 'tildacdn')) {
                $el['html'] = preg_replace_callback('~src="([^"]+)"~', static fn(array $m): string => 'src="' . htmlspecialchars(self::imageUrl(html_entity_decode($m[1], ENT_QUOTES)), ENT_QUOTES) . '"', $el['html']) ?? $el['html'];
            }
        }
        unset($el);
        // The same picture twice in a row (desktop and mobile copies) is one picture.
        $out = [];
        foreach ($els as $el) {
            $prev = $out === [] ? null : $out[count($out) - 1];
            if ($el['type'] === 'image' && $prev !== null && $prev['type'] === 'image' && $prev['src'] === $el['src']) {
                continue;
            }
            $out[] = $el;
        }
        return $out;
    }

    /**
     * A post of Tilda Feeds: title, date, cover, the text of the post, the feed page as the parent.
     *
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    private static function post(DOMElement $box, DOMXPath $xp, string $url, array $page): array
    {
        foreach (Dom::all($xp, '//script|//style|//noscript|//template') as $n) {
            Dom::remove($n);
        }
        $page['kind'] = 'post';
        $title = self::text(Dom::first($xp, './/*[contains(@class,"t-feed__post-popup__title")]', $box));
        if ($title !== '') {
            $page['title'] = $title;
        }
        $date = Dom::first($xp, './/meta[@itemprop="datePublished"]', $box)?->getAttribute('content') ?? '';
        if ($date === '') {
            $date = Dom::text(Dom::first($xp, './/*[contains(@class,"t-feed__post-popup__date")]', $box));
        }
        $page['published_at'] = $date;
        $back = Dom::first($xp, './/*[contains(@class,"t-feed__post-popup__close-wrapper")]//a[@href]', $box);
        if ($back !== null && ($href = Url::resolve($url, $back->getAttribute('href'))) !== '' && Url::sameSite($href, $url)) {
            $page['parent_old_path'] = Url::oldPath($href);
        }
        $cover = '';
        $img = Dom::first($xp, './/*[contains(@class,"t-feed__post-popup__cover-wrapper")]//img|.//img[contains(@class,"t-feed__post-popup__img")]', $box);
        if ($img !== null) {
            $cover = Url::resolve($url, Dom::imageSrc($img));
        } elseif (($bg = Dom::first($xp, './/*[contains(@class,"t-feed__post-popup__cover-wrapper")]//*[@data-original or @style]', $box)) !== null) {
            $cover = Url::resolve($url, $bg->getAttribute('data-original') ?: Dom::backgroundImage($bg));
        }
        $elements = [Neutral::el('hero', ['h1' => $page['title'], 'subtitle' => '', 'cta_text' => '', 'cta_url' => '', 'image' => $cover], 'rec t-feed__post-popup')];
        $body = Dom::first($xp, './/*[contains(@class,"t-feed__post-popup__text")]', $box);
        if ($body !== null) {
            $bdoc = Dom::load(self::splitBreaks(Dom::innerHtml($body)));
            $body = Dom::first(Dom::xpath($bdoc), '//body/div') ?? $body;
            foreach ((new ElementParser($url))->parse($body) as $el) {
                $el['source'] = 'запись Потоков ' . $el['source'];
                $elements[] = $el;
            }
        }
        // Pictures of the post gallery (when the post has one instead of the text).
        $gallery = [];
        foreach (Dom::all($xp, './/*[contains(@class,"t-feed__post-popup__gallery")]//*[@data-original]|.//*[contains(@class,"t-feed__post-popup__gallery")]//img', $box) as $n) {
            $src = $n->getAttribute('data-original') ?: (strtolower($n->nodeName) === 'img' ? Dom::imageSrc($n) : '');
            if ($src !== '') {
                $gallery[self::imageUrl(Url::resolve($url, $src))] = true;
            }
        }
        if (count($gallery) >= 2) {
            $elements[] = Neutral::el('gallery', ['images' => array_map(static fn(string $s): array => ['src' => $s, 'alt' => '', 'caption' => ''], array_keys($gallery))], 'запись Потоков галерея');
        }
        $page['elements'] = self::images(ElementParser::tidy($elements));
        $page['og_image'] = self::imageUrl((string)$page['og_image']);
        $page['notes'] = [];
        return $page;
    }

    /**
     * A grid of six or more cards that are links to pages of the site (lists of articles, "read also", catalogs of
     * services): one list of links instead of a picture, a title and a button for every card.
     *
     * @return list<array<string,mixed>>|null
     */
    private static function linkCards(DOMElement $rec, DOMXPath $xp, string $url, string $label): ?array
    {
        $items = [];
        $seen = [];
        foreach (Dom::all($xp, './/a[@href]', $rec) as $a) {
            if (Dom::classMatches($a, '~^(t-btn|t-btnflex|t-submit)~') || Dom::first($xp, './/*[@class]', $a) === null) {
                continue;
            }
            $title = Dom::first($xp, './/*[contains(@class,"t-name") or contains(@class,"t-title") or contains(@class,"__title") or contains(@class,"t-card__title")]', $a);
            $text = self::text($title);
            $href = Url::resolve($url, $a->getAttribute('href'));
            if ($text === '' || $href === '' || !Url::sameSite($href, $url) || isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            $items[] = '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($text, ENT_QUOTES) . '</a>';
        }
        if (count($items) < 6) {
            return null;
        }
        $out = [];
        $head = Dom::first($xp, './/*[contains(@class,"t-section__title")]', $rec);
        if (self::text($head) !== '') {
            $out[] = Neutral::el('heading', ['level' => 2, 'text' => self::text($head)], $label . ' t-section__title');
        }
        $out[] = Neutral::el('list', ['ordered' => false, 'items' => $items], $label . ' карточки-ссылки');
        return $out;
    }

    /**
     * A Tilda text: lists and tables as they are, otherwise paragraphs split at double line breaks (Tilda texts
     * separate paragraphs with <br><br>).
     *
     * @return list<array<string,mixed>>
     */
    private static function textBlock(DOMElement $c, DOMXPath $xp, string $url, string $label): array
    {
        $out = [];
        if (Dom::first($xp, './/ul|.//ol|.//p|.//table|.//h2|.//h3|.//blockquote|.//figure', $c) !== null) {
            foreach ((new ElementParser($url))->parse($c) as $el) {
                $el['source'] = $label . ' ' . $el['source'];
                $out[] = $el;
            }
            return $out;
        }
        foreach (preg_split('~(?:<br\s*/?>\s*(?:&nbsp;|\x{00A0})?\s*){2,}~iu', Dom::innerHtml($c)) ?: [] as $part) {
            $html = Sanitizer::inline($part, $url);
            if (Neutral::text($html) !== '') {
                $out[] = Neutral::el('paragraph', ['html' => $html], $label . ' ' . Dom::label($c, 1));
            }
        }
        return $out;
    }

    /** HTML with paragraphs made of double line breaks, for the text of a post. */
    private static function splitBreaks(string $html): string
    {
        return '<div><p>' . (preg_replace('~(?:<br\s*/?>\s*(?:&nbsp;|\x{00A0})?\s*){2,}~iu', '</p><p>', $html) ?? $html) . '</p></div>';
    }

    /**
     * Text of a Tilda button. Old buttons wrap a table in the link, and the HTML parser closes the link before the
     * table, so the text is then in the table right after it.
     */
    private static function buttonText(DOMElement $a, DOMXPath $xp): string
    {
        $text = self::text($a);
        if ($text === '' && ($next = Dom::first($xp, 'following-sibling::*[1][self::table]', $a)) !== null) {
            $text = self::text($next);
            $next->parentNode?->removeChild($next);
        }
        return $text;
    }

    private static function any(DOMElement $rec, DOMXPath $xp, string $regex): bool
    {
        if (Dom::classMatches($rec, $regex)) {
            return true;
        }
        foreach (Dom::all($xp, './/*[@class]', $rec) as $n) {
            if (Dom::classMatches($n, $regex)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Menu links of a record: the top level; the items of drop-downs only when the top level has fewer than four
     * links (a menu that is all drop-downs). No logo, phone, mail, popup, search or burger links.
     *
     * @return list<array{title:string,url:string}>
     */
    private static function links(DOMElement $rec, DOMXPath $xp, string $url): array
    {
        $top = [];
        $sub = [];
        $seen = [];
        foreach (Dom::all($xp, './/a[@href]', $rec) as $a) {
            $raw = trim($a->getAttribute('href'));
            if ($raw === '' || $raw === '#' || Dom::first($xp, 'ancestor-or-self::*[contains(@class,"logo")]', $a) !== null || Dom::first($xp, './/img', $a) !== null) {
                continue;
            }
            $title = self::text($a);
            $href = Url::resolve($url, $raw);
            if ($title === '' || mb_strlen($title) > 60 || $href === '' || isset($seen[$href]) || preg_match('~^(tel|mailto|javascript|whatsapp|viber|data):|#(popup|order|menuopen|open|price|submenu)|#!/~i', $href) === 1) {
                continue;
            }
            $seen[$href] = true;
            $isSub = Dom::first($xp, 'ancestor-or-self::*[contains(@class,"menusub") or contains(@class,"submenu")]', $a) !== null;
            if ($isSub) {
                $sub[] = ['title' => $title, 'url' => $href];
            } else {
                $top[] = ['title' => $title, 'url' => $href];
            }
        }
        return array_slice(count($top) >= 4 ? $top : array_merge($top, $sub), 0, count($top) >= 4 ? 30 : 12);
    }

    /**
     * A Zero Block at the top of a page that is a header: at least four links to pages of the site and little other
     * text. Null when it is content.
     *
     * @return list<array{title:string,url:string}>|null
     */
    private static function zeroMenu(DOMElement $rec, DOMXPath $xp, string $url): ?array
    {
        $links = [];
        $other = 0;
        foreach (Dom::all($xp, './/*[contains(concat(" ", normalize-space(@class), " "), " tn-elem ")]', $rec) as $elem) {
            $a = strtolower($elem->nodeName) === 'a' ? $elem : Dom::first($xp, './/a[@href]', $elem);
            $text = self::text($elem);
            if ($a !== null && $text !== '' && mb_strlen($text) <= 40) {
                $href = Url::resolve($url, $a->getAttribute('href'));
                if ($href !== '' && (Url::sameSite($href, $url) || str_ends_with(Url::host($href), '.tilda.ws')) && preg_match('~#(popup|order|menuopen)~', $href) !== 1) {
                    $links[] = ['title' => $text, 'url' => $href];
                    continue;
                }
            }
            $other += mb_strlen($text);
        }
        return count($links) >= 4 && $other < 200 ? $links : null;
    }

    /** Walks a record and picks Tilda roles by class names. @param list<array<string,mixed>> $out */
    private static function roles(DOMElement $node, DOMXPath $xp, string $url, array &$out, string $label, bool $skipForms = false): void
    {
        foreach ($node->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($c->nodeName);
            if (in_array($tag, ['script', 'style', 'noscript', 'svg', 'template', 'button', 'input', 'textarea', 'select'], true) || ($skipForms && $tag === 'form')) {
                continue;
            }
            if (preg_match('~^h[1-6]$~', $tag) === 1 || Dom::classMatches($c, self::HEADING)) {
                $text = self::text($c);
                // A long text styled as a title (quotes, leads) is a paragraph, not a heading.
                if (mb_strlen($text) > 150) {
                    $out = array_merge($out, self::textBlock($c, $xp, $url, $label));
                    continue;
                }
                if ($text !== '') {
                    $level = preg_match('~^h([1-6])$~', $tag, $m) === 1 ? (int)$m[1] : (Dom::classMatches($c, '~^(t-name|t-card__title|t-product__title|t-store__card__title)~') ? 3 : 2);
                    $out[] = Neutral::el('heading', ['level' => $level, 'text' => $text], $label . ' ' . Dom::label($c, 1));
                    // The title of a card is a link to its page (t-card__link): the link goes after it.
                    $a = Dom::first($xp, './/a[@href]', $c);
                    $href = $a === null ? '' : Url::resolve($url, $a->getAttribute('href'));
                    if ($href !== '' && preg_match('~^https?://~i', $href) === 1 && self::text($a) === $text && preg_match('~#(popup|order)~', $href) !== 1) {
                        $out[] = Neutral::el('button', ['text' => 'Подробнее', 'url' => $href], $label . ' ' . Dom::label($a, 1));
                    }
                }
                continue;
            }
            if (Dom::classMatches($c, self::TEXT)) {
                if (Dom::text($c) !== '') {
                    $out = array_merge($out, self::textBlock($c, $xp, $url, $label));
                }
                continue;
            }
            if ($tag === 'img') {
                $el = (new ElementParser($url))->imageElement($c);
                if ($el !== null) {
                    $el['source'] = $label . ' ' . $el['source'];
                    $out[] = $el;
                }
                continue;
            }
            if ($tag !== 'a' && ($c->getAttribute('data-original') !== '' || Dom::hasClass($c, 't-bgimg')) && Dom::first($xp, './/img', $c) === null) {
                $src = $c->getAttribute('data-original') ?: Dom::backgroundImage($c);
                if ($src !== '' && !str_ends_with(strtolower((string)parse_url($src, PHP_URL_PATH)), '.svg')) {
                    $out[] = Neutral::el('image', ['src' => Url::resolve($url, $src), 'alt' => Neutral::line($c->getAttribute('title') ?: $c->getAttribute('aria-label')), 'caption' => ''], $label . ' ' . Dom::label($c, 1));
                }
                if (Dom::text($c) === '') {
                    continue;
                }
            }
            if ($tag === 'a' && (Dom::classMatches($c, '~^(t-btn|t-submit|t-btnflex|t-btntext)~') || Dom::classMatches($c, '~__btn~'))) {
                $text = self::buttonText($c, $xp);
                $href = Url::resolve($url, $c->getAttribute('href'));
                if ($text !== '' && $href !== '' && !str_contains($href, '#popup') && !str_contains($href, '#order')) {
                    $out[] = Neutral::el('button', ['text' => $text, 'url' => $href], $label . ' ' . Dom::label($c, 1));
                }
                continue;
            }
            if ($tag === 'table') {
                $out[] = Neutral::el('table', ['html' => Sanitizer::rich(Dom::outerHtml($c), $url)], $label . ' table');
                continue;
            }
            if ($tag === 'iframe' || ($c->getAttribute('data-youtube-id') !== '') || ($c->getAttribute('data-vimeo-id') !== '')) {
                $src = $c->getAttribute('src') ?: $c->getAttribute('data-original') ?: ($c->getAttribute('data-youtube-id') !== '' ? 'https://www.youtube.com/watch?v=' . $c->getAttribute('data-youtube-id') : ($c->getAttribute('data-vimeo-id') !== '' ? 'https://vimeo.com/' . $c->getAttribute('data-vimeo-id') : ''));
                $src = Url::resolve($url, $src);
                if ($src !== '') {
                    $out[] = Neutral::el('embed', ['src' => $src, 'kind' => ElementParser::embedKind($src)], $label . ' video');
                }
                continue;
            }
            // A card that is a link to a page (blog lists, catalogs of services): its content, then the link.
            if ($tag === 'a' && Dom::first($xp, './/*[@class]', $c) !== null) {
                $before = count($out);
                self::roles($c, $xp, $url, $out, $label, $skipForms);
                $href = Url::resolve($url, $c->getAttribute('href'));
                if (count($out) > $before && $href !== '' && preg_match('~^https?://~i', $href) === 1 && !str_contains($href, '#popup') && !str_contains($href, '#order')) {
                    $out[] = Neutral::el('button', ['text' => 'Подробнее', 'url' => $href], $label . ' ' . Dom::label($c, 1));
                }
                continue;
            }
            self::roles($c, $xp, $url, $out, $label, $skipForms);
        }
    }

    /** @return array<string,mixed>|null */
    private static function cover(DOMElement $cover, DOMXPath $xp, string $url, string $label): array
    {
        $image = '';
        foreach (Dom::all($xp, './/*[@data-content-cover-bg]|.//*[@data-original]|.//*[contains(@class,"t-cover__carrier")]', $cover) as $n) {
            $image = $n->getAttribute('data-content-cover-bg') ?: $n->getAttribute('data-original') ?: Dom::backgroundImage($n);
            if ($image !== '') {
                break;
            }
        }
        $head = Dom::first($xp, './/h1|.//*[contains(@class,"t-title")]|.//*[contains(@class,"t-heading")]|.//*[contains(@class,"t-name")]|.//h2', $cover);
        $texts = [];
        foreach (Dom::all($xp, './/*[contains(@class,"t-descr") or contains(@class,"t-text") or contains(@class,"t-uptitle")]', $cover) as $d) {
            if ($d !== $head && Dom::text($d) !== '') {
                $texts[] = Dom::multilineText($d);
            }
        }
        $btn = Dom::first($xp, './/a[contains(@class,"t-btn") or contains(@class,"t-btnflex")]', $cover);
        // A cover with no text is a picture.
        if (self::text($head) === '' && $texts === [] && $btn === null) {
            return $image === '' ? null : Neutral::el('image', ['src' => Url::resolve($url, $image), 'alt' => '', 'caption' => ''], $label . ' t-cover');
        }
        return Neutral::el('hero', [
            'h1' => $head === null ? '' : self::text($head),
            'subtitle' => implode("\n", array_unique($texts)),
            'cta_text' => $btn === null ? '' : self::buttonText($btn, $xp),
            'cta_url' => $btn === null ? '' : Url::resolve($url, $btn->getAttribute('href')),
            'image' => $image === '' ? '' : Url::resolve($url, $image),
        ], $label . ' t-cover');
    }

    /** FAQ blocks (T585, T668, T1089 and alike): items with a title and a text. @return list<array<string,mixed>>|null */
    private static function accordion(DOMElement $rec, DOMXPath $xp, string $url, string $label): ?array
    {
        $items = [];
        foreach (Dom::all($xp, './/*[@class]', $rec) as $n) {
            if (!Dom::classMatches($n, '~^t\d+__accordion$~') && !$n->hasAttribute('data-accordion')) {
                continue;
            }
            $q = null;
            $a = null;
            foreach (Dom::all($xp, './/*[@class]', $n) as $d) {
                if ($q === null && Dom::classMatches($d, '~^t\d+__(title|header-title|name)$~')) {
                    $q = $d;
                } elseif ($a === null && Dom::classMatches($d, '~^t\d+__(text|descr|content-text|textwrapper)$~')) {
                    $a = $d;
                }
            }
            if ($q !== null && $a !== null && self::text($q) !== '' && Dom::text($a) !== '') {
                $items[] = ['q' => self::text($q), 'a' => Sanitizer::rich(Dom::innerHtml($a), $url)];
            }
        }
        if ($items === []) {
            return null;
        }
        $out = [];
        $head = Dom::first($xp, './/*[contains(@class,"t-section__title")]|.//h2', $rec);
        if ($head !== null && self::text($head) !== '' && Dom::first($xp, 'ancestor::*[@data-accordion or contains(@class,"__accordion")]', $head) === null) {
            $out[] = Neutral::el('heading', ['level' => 2, 'text' => self::text($head)], $label . ' t-section__title');
        }
        $out[] = Neutral::el('faq', ['items' => $items], $label . ' accordion');
        return $out;
    }

    /** Sliders and galleries (t-slds, t-gallery): pictures with almost no text. @return list<array<string,mixed>>|null */
    private static function gallery(DOMElement $rec, DOMXPath $xp, string $url, string $label): ?array
    {
        if (!self::any($rec, $xp, '~^(t-slds|t-gallery|t-carousel|t-slides)~')) {
            return null;
        }
        $images = [];
        $seen = [];
        foreach (Dom::all($xp, './/*[@data-original]|.//img', $rec) as $n) {
            $src = $n->getAttribute('data-original') ?: (strtolower($n->nodeName) === 'img' ? Dom::imageSrc($n) : '');
            $src = $src === '' ? '' : self::imageUrl(Url::resolve($url, $src));
            if ($src !== '' && !isset($seen[$src]) && !str_ends_with(strtolower((string)parse_url($src, PHP_URL_PATH)), '.svg')) {
                $seen[$src] = true;
                $images[] = ['src' => $src, 'alt' => Neutral::line($n->getAttribute('alt')), 'caption' => ''];
            }
        }
        if (count($images) < 2 || mb_strlen(Dom::text($rec)) > 80 * count($images)) {
            return null;
        }
        $out = [];
        $head = Dom::first($xp, './/*[contains(@class,"t-section__title")]|.//*[contains(@class,"t-title")]', $rec);
        if ($head !== null && self::text($head) !== '') {
            $out[] = Neutral::el('heading', ['level' => 2, 'text' => self::text($head)], $label . ' title');
        }
        $out[] = Neutral::el('gallery', ['images' => $images], $label . ' slider');
        return $out;
    }

    /**
     * Zero Block: atoms sorted top to bottom (data-field-top-value), texts as paragraphs (big ones as headings),
     * pictures and buttons as they are. Icons (SVG, smaller than 60 px) and repeated pictures (a running line of
     * logos has every picture twice) are left out.
     *
     * @return array{0:list<array<string,mixed>>,1:array{what:string,why:string,source:string}}
     */
    private static function zero(DOMElement $rec, DOMXPath $xp, string $url, string $label): array
    {
        $atoms = [];
        foreach (Dom::all($xp, './/*[contains(concat(" ", normalize-space(@class), " "), " tn-elem ")]', $rec) as $n => $elem) {
            $top = (float)($elem->getAttribute('data-field-top-value') ?: $n);
            $left = (float)$elem->getAttribute('data-field-left-value');
            $atoms[] = [$top, $left, $n, $elem];
        }
        usort($atoms, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $out = [];
        $seen = [];
        $isIcon = static function (string $src, DOMElement $elem): bool {
            $width = (float)$elem->getAttribute('data-field-width-value');
            return str_ends_with(strtolower((string)parse_url($src, PHP_URL_PATH)), '.svg') || ($width > 0 && $width < 60);
        };
        foreach ($atoms as [, , , $elem]) {
            $type = $elem->getAttribute('data-elem-type');
            $atom = Dom::first($xp, './/*[contains(@class,"tn-atom")]', $elem) ?? $elem;
            if ($type === 'image' || ($type === '' && Dom::first($xp, './/img', $elem) !== null)) {
                $img = Dom::first($xp, './/img', $elem);
                $src = $img !== null ? Dom::imageSrc($img) : ($atom->getAttribute('data-original') ?: Dom::backgroundImage($atom));
                $src = $src === '' ? '' : self::imageUrl(Url::resolve($url, $src));
                if ($src !== '' && !isset($seen[$src]) && !$isIcon($src, $elem)) {
                    $seen[$src] = true;
                    $out[] = Neutral::el('image', ['src' => $src, 'alt' => $img === null ? '' : Neutral::line($img->getAttribute('alt')), 'caption' => ''], $label . ' tn-atom__img');
                }
                continue;
            }
            if ($type === 'shape' && ($bg = $atom->getAttribute('data-original') ?: Dom::backgroundImage($atom)) !== '') {
                $bg = self::imageUrl(Url::resolve($url, $bg));
                if (!isset($seen[$bg]) && !$isIcon($bg, $elem)) {
                    $seen[$bg] = true;
                    $out[] = Neutral::el('image', ['src' => $bg, 'alt' => '', 'caption' => ''], $label . ' tn-atom (фон)');
                }
                continue;
            }
            if ($type === 'button' || strtolower($atom->nodeName) === 'a' && $type !== 'text') {
                $text = self::text($atom);
                $href = Url::resolve($url, $atom->getAttribute('href'));
                if ($text !== '' && $href !== '' && preg_match('~#(popup|order|menuopen)~', $href) !== 1) {
                    $out[] = Neutral::el('button', ['text' => $text, 'url' => $href], $label . ' tn-atom (кнопка)');
                }
                continue;
            }
            if ($type === 'video' || ($frame = Dom::first($xp, './/iframe', $elem)) !== null) {
                $frame ??= Dom::first($xp, './/iframe', $elem);
                $src = $frame?->getAttribute('src') ?: ($elem->getAttribute('data-field-youtube-value') !== '' ? 'https://www.youtube.com/watch?v=' . $elem->getAttribute('data-field-youtube-value') : '');
                if ($src !== '') {
                    $src = Url::resolve($url, $src);
                    $out[] = Neutral::el('embed', ['src' => $src, 'kind' => ElementParser::embedKind($src)], $label . ' tn-atom (видео)');
                }
                continue;
            }
            $text = self::text($atom);
            if ($text === '') {
                continue;
            }
            $size = (int)$elem->getAttribute('data-field-fontsize-value');
            $tag = strtolower($atom->nodeName);
            if (preg_match('~^h([1-6])$~', $tag, $m) === 1 || ($size >= 28 && mb_strlen($text) <= 150)) {
                $out[] = Neutral::el('heading', ['level' => isset($m[1]) ? (int)$m[1] : 2, 'text' => $text], $label . ' tn-atom (текст)');
            } else {
                $out[] = Neutral::el('paragraph', ['html' => Sanitizer::inline(Dom::innerHtml($atom), $url)], $label . ' tn-atom (текст)');
            }
        }
        // Mostly pictures (logos, a running line of photos): one gallery where the first picture was.
        $pictures = array_values(array_filter($out, static fn(array $e): bool => $e['type'] === 'image'));
        if (count($pictures) >= 6 && count($pictures) > 2 * (count($out) - count($pictures))) {
            $first = true;
            $grouped = [];
            foreach ($out as $e) {
                if ($e['type'] !== 'image') {
                    $grouped[] = $e;
                } elseif ($first) {
                    $first = false;
                    $grouped[] = Neutral::el('gallery', ['images' => array_map(static fn(array $i): array => ['src' => $i['src'], 'alt' => $i['alt'], 'caption' => ''], $pictures)], $label . ' tn-atom (картинки)');
                }
            }
            $out = $grouped;
        }
        return [$out, ['what' => 'Zero Block ' . $label, 'why' => 'блок со свободной вёрсткой: тексты и картинки перенесены по порядку сверху вниз, перенесите расположение вручную', 'source' => $label]];
    }
}
