<?php
declare(strict_types=1);

use AgeCMS\Modules\Importer\Importer;
use AgeCMS\Modules\Importer\Detect\Detector;
use AgeCMS\Modules\Importer\Extract\Generic;
use AgeCMS\Modules\Importer\Html\{Dom, MenuFinder};
use AgeCMS\Modules\Importer\Net\{ArrayFetcher, Response};

require_once dirname(__DIR__) . '/src/autoload.php';

/**
 * Sites of other CMSes as they really are (fixtures made after Bitrix, MODX, Wix, OpenCart and plain sites): a chat
 * widget of Bitrix24 on a MODX site, <base href> with relative breadcrumbs, raw Cyrillic links, Aspro themes with
 * "footer-v2" on <body>, side columns and a basket inside #content, pictures with a thumbnail in src, price lists in
 * tables and in divs, a Wix site in a folder of wixsite.com with a NUL byte in the markup, an OpenCart shop whose
 * catalog stays behind, a one-page site of sibling sections. No network: pages come from ArrayFetcher.
 */
final class GenericRealTest extends TestCase
{
    private const FX = __DIR__ . '/fixtures/generic';

    private static function fx(string $file): string
    {
        return (string)file_get_contents(self::FX . '/' . $file);
    }

    /** @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>,2:ArrayFetcher,3:Importer} */
    private static function analyze(string $input, array $map, int $limit = 30): array
    {
        $fetcher = new ArrayFetcher($map);
        $importer = new Importer(['delay_ms' => 0, 'limit' => $limit], $fetcher);
        $analysis = $importer->analyze($input);
        $byPath = [];
        foreach ($analysis['pages'] as $p) {
            $byPath[$p['old_path']] = $p;
        }
        return [$analysis, $byPath, $fetcher, $importer];
    }

    /** @param list<array<string,mixed>> $els @return list<string> */
    private static function texts(array $els): array
    {
        return array_map(static fn(array $e): string => match ($e['type']) {
            'heading' => 'h' . $e['level'] . ' ' . $e['text'],
            'paragraph', 'table', 'quote' => $e['type'] . ' ' . trim(html_entity_decode(strip_tags((string)$e['html']))),
            'list' => 'list ' . implode(' | ', array_map('strip_tags', $e['items'])),
            'image' => 'image ' . $e['src'],
            'button' => 'button ' . $e['text'] . ' ' . $e['url'],
            default => (string)$e['type'],
        }, $els);
    }

    public function testDetectorIgnoresWidgetsAndReadsMoreSigns(): void
    {
        $modx = Detector::detect(new Response(200, self::fx('modx-home.html'), ['content-type' => 'text/html', 'set-cookie' => 'PHPSESSID=abc; path=/'], 'https://dez.example.ru/'));
        $this->assertSame('modx', $modx['platform'], 'the Bitrix24 chat button loads /bitrix/js/crm/ from bitrix24.ru: not a Bitrix site');
        $this->assertTrue(!isset($modx['scores']['bitrix']));
        $this->assertContains('Битрикс24', implode('; ', $modx['evidence']));

        $composite = Detector::detect(new Response(200, '<html><head><link href="/local/templates/main/style.css" rel="stylesheet"><script>BX.ready(function(){});</script></head><body><img src="/upload/iblock/a1/room.jpg"></body></html>', ['content-type' => 'text/html', 'x-bitrix-composite' => 'Cache (200)'], 'https://hotel.example.ru/'));
        $this->assertSame('bitrix', $composite['platform']);
        $this->assertTrue($composite['confidence'] >= 0.9, 'the composite cache header and /upload/iblock/ say Bitrix without /bitrix/ paths');

        $evo = Detector::detect(new Response(200, '<html><head><base href="https://clinic.example.ru/"><script src="assets/templates/clinic/js/main.js"></script></head><body><div style="background:url(assets/cache/images/top-1920x350-1ce.jpg)"></div></body></html>', ['content-type' => 'text/html', 'set-cookie' => 'evo1isqy9n=960af80397a8e2ca; path=/; HttpOnly'], 'https://clinic.example.ru/'));
        $this->assertSame('modx', $evo['platform'], 'MODX Evolution: session cookie evo..., relative assets/templates/');

        $hostcms = Detector::detect(new Response(200, '<html><body><img src="/upload/information_system_27/1/8/6/item_186/small.jpg"></body></html>', ['content-type' => 'text/html'], 'https://stone.example.ru/'));
        $this->assertSame('hostcms', $hostcms['platform']);
    }

    public function testModxBaseHrefBreadcrumbsAndCyrillicLinks(): void
    {
        $b = 'https://dez.example.ru';
        $cyrillic = $b . '/uslugi/' . rawurlencode('обработка-квартиры');
        $map = [
            "$b/" => self::fx('modx-home.html'),
            "$b/robots.txt" => [200, "User-agent: *\nDisallow: /manager/\nSitemap: $b/sitemap.xml\n", ['content-type' => 'text/plain']],
            "$b/sitemap.xml" => [200, self::fx('modx-sitemap.xml'), ['content-type' => 'application/xml']],
            "$b/stati/klopy-v-dome" => self::fx('modx-article.html'),
            $cyrillic => self::fx('modx-cyrillic.html'),
        ];
        [$analysis, $p, $fetcher] = self::analyze("$b/", $map);
        $this->assertSame('modx', $analysis['detect']['platform']);
        $this->assertTrue(in_array($cyrillic, $fetcher->requested, true), 'a raw UTF-8 address of the sitemap is fetched percent-encoded, not mangled by parse_url');
        $this->assertTrue(isset($p['/uslugi/обработка-квартиры']), 'the old path is the readable one');

        $menu = $analysis['site']['menus']['header'];
        $this->assertSame(['Дезинсекция', 'Статьи', 'Контакты', 'Обработка от клопов'], array_column($menu, 'title'));
        $this->assertSame("$b/stati/", $menu[1]['url'], 'relative menu links resolve against <base href>, not the page');

        $article = $p['/stati/klopy-v-dome'];
        $this->assertSame('/stati/', $article['parent_old_path'], 'relative breadcrumbs resolve against <base href>');
        $this->assertSame('post', $article['kind']);
        $this->assertSame('2020-01-23', $article['published_at'], 'the date shown as a line under the title');
        $texts = self::texts($article['elements']);
        $this->assertSame('h1 Клопы в доме', $texts[0]);
        $this->assertNotContains('23.01.2020', implode("\n", $texts), 'the date line is not a paragraph of the post');
        $this->assertTrue(in_array('list осмотр помещения; | обработка мест скопления; | повторная обработка через две недели.', $texts, true), 'paragraphs typed as "- item" make a list');
        $this->assertTrue(in_array("image $b/assets/images/stati/klop.jpg", $texts, true));
        $html = json_encode($article['elements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertContains('href=\"' . $b . '/stati/tarakany\"', $html, 'links inside the text resolve against <base href>');
    }

    public function testBitrixColumnsPicturesTablesAndNewsDate(): void
    {
        $html = self::fx('bitrix-service.html');
        $page = Generic::parsePage($html, 'https://med.example.ru/service/dermatologiya/', 'bitrix');
        $texts = self::texts($page['elements']);
        $all = implode("\n", $texts);
        $this->assertSame('h1 Дерматология', $texts[0], 'the left column with the list of services is not content');
        $this->assertNotContains('Наши услуги', $all);
        $this->assertNotContains('0 анализов', $all, 'the basket of the right column is not content');
        $this->assertNotContains('form', implode(' ', array_column($page['elements'], 'type')), 'a select without a field to type into is a filter, not a form');
        $this->assertTrue(in_array('image https://med.example.ru/upload/resize_cache/iblock/504/1400_700_2/room.jpg', $texts, true), 'the biggest <source> of the picture, not the 50x30 thumbnail of src');
        $this->assertNotContains('title-icon', $all, 'decorative icons are left out');
        $tables = array_values(array_filter($page['elements'], static fn(array $e): bool => $e['type'] === 'table'));
        $this->assertSame(2, count($tables), 'a price table with hints in <div><p> is data, and a table of divs becomes a table');
        $this->assertContains('<td>Приём дерматолога<p>первичный, 30 минут</p></td>', $tables[0]['html']);
        $this->assertContains('<tr><td>Иванова А. А.</td><td>пн, ср с 9.00 до 14.00</td></tr>', $tables[1]['html']);
        $this->assertTrue(in_array('h3 Расписание', $texts, true), 'the name of a block styled by class is a heading');

        $menus = MenuFinder::find(Dom::load($html), 'https://med.example.ru/');
        $this->assertSame(['О центре', 'Услуги', 'Новости', 'Контакты'], array_column($menus['header'], 'title'), '"footer-v2" on <body> does not put the header into the footer');
        $this->assertSame(['О центре', 'Новости', 'Политика'], array_column($menus['footer'], 'title'), 'links of a footer without a nav make the footer menu');

        $news = Generic::parsePage(self::fx('bitrix-news.html'), 'https://med.example.ru/about/news/novyy-metod/', 'bitrix');
        $this->assertSame('post', $news['kind'], 'news one level down (/about/news/) are posts too');
        $this->assertSame('2013-09-16', $news['published_at'], 'the date the template shows, in words, before datePublished (the date of entry)');
        $this->assertNotContains('Предыдущая новость', implode("\n", self::texts($news['elements'])), 'links to the neighbour news are navigation');
        $this->assertNotContains('16 сентября', implode("\n", self::texts($news['elements'])), 'the date line is the date of the post, not its text');
    }

    public function testWixSiteInAFolderWithANulByte(): void
    {
        $b = 'https://studio.wixsite.com';
        $map = [
            "$b/nail" => [200, self::fx('wix-home.html'), ['content-type' => 'text/html; charset=utf-8', 'x-wix-request-id' => '1']],
            "$b/robots.txt" => [200, "User-agent: *\nAllow: /\n", ['content-type' => 'text/plain']],
            "$b/nail/sitemap.xml" => [200, self::fx('wix-sitemap.xml'), ['content-type' => 'application/xml']],
            "$b/nail/prise" => self::fx('wix-prise.html'),
        ];
        [$analysis, $p, $fetcher, $importer] = self::analyze("$b/nail", $map);
        $this->assertSame('wix', $analysis['detect']['platform']);
        $this->assertTrue(in_array("$b/nail/sitemap.xml", $fetcher->requested, true), 'the sitemap of a site in a folder is in that folder');
        $this->assertSame(['/', '/prise'], array_keys($p), 'the folder of the site is its root');
        $this->assertSame(['/nail'], $p['/']['aliases'], 'the full old address stays for redirects and links');
        $this->assertSame('Маникюр', $p['/']['title'], 'the h1 of the site header is the site name, not the page title');
        $this->assertSame(['Главная', 'Услуги и цены', 'Контакты'], array_column($analysis['site']['menus']['header'], 'title'), 'a NUL byte inside an attribute no longer ends the document');

        $texts = self::texts($p['/']['elements']);
        $this->assertSame('h2 Маникюр и педикюр', $texts[0], 'zero-width spaces are dropped');
        $this->assertTrue(in_array('paragraph Делаем маникюр быстро и аккуратно, стерильные инструменты.', $texts, true), 'a sentence styled as h5 is a paragraph');
        $this->assertTrue(in_array('image https://static.wixstatic.com/media/5b91af_aa11.jpg', $texts, true), 'the original file, not a resized copy; commas inside srcset addresses are not separators');
        $this->assertNotContains('top of page', implode("\n", $texts));
        $this->assertSame('list Маникюр классический - 900 р. | Маникюр аппаратный - 1100 р. | Педикюр - 1900 р.', self::texts($p['/prise']['elements'])[1], '"1.Item" paragraphs make an ordered list');

        $pages = [];
        foreach ($analysis['pages'] as $i => $page) {
            $page['id'] = $i + 1;
            $pages[] = $page;
        }
        $plan = $importer->plan($pages, $analysis['site'], null, static fn(string $s): ?string => null);
        $this->assertSame(['index', 'prise'], array_values(array_column($plan['items'], 'slug')));
        $this->assertTrue(in_array(['from' => '/nail/prise', 'to' => '/prise'], $plan['redirects'], true), 'the old address in the folder redirects to the new one');
    }

    public function testOpenCartTakesInformationPagesOnly(): void
    {
        $b = 'https://tea.example.ru';
        $map = [
            "$b/" => self::fx('opencart-home.html'),
            "$b/robots.txt" => [200, "User-agent: *\nSitemap: $b/sitemap.xml\n", ['content-type' => 'text/plain']],
            "$b/sitemap.xml" => [200, self::fx('opencart-sitemap.xml'), ['content-type' => 'application/xml']],
            "$b/o-nas.html" => self::fx('opencart-info.html'),
            "$b/dostavka.html" => self::fx('opencart-delivery.html'),
            "$b/chai/" => self::fx('opencart-category.html'),
            "$b/kofe/" => self::fx('opencart-category.html'),
            "$b/sort-1.html" => self::fx('opencart-product.html'),
            "$b/sort-2.html" => self::fx('opencart-product.html'),
            "$b/sort-3.html" => self::fx('opencart-product.html'),
            "$b/sort-4.html" => self::fx('opencart-product.html'),
        ];
        [$analysis, $p, $fetcher] = self::analyze("$b/", $map, 15);
        $this->assertSame('opencart', $analysis['detect']['platform']);
        $this->assertSame(['/', '/o-nas.html', '/dostavka.html'], array_keys($p), 'information pages from the footer come first, the catalog stays behind');
        $this->assertSame('О нас', $p['/o-nas.html']['title'], 'a product module in the column of an information page does not make it a catalog page');
        $this->assertContains('Страницы каталога магазина', implode("\n", $analysis['warnings']));
        $this->assertSame([], array_values(array_filter($fetcher->requested, static fn(string $u): bool => str_contains($u, 'route=account'))), 'account pages are never fetched');
    }

    public function testOnePageSiteKeepsAllSections(): void
    {
        $page = Generic::parsePage(self::fx('static-onepage.html'), 'https://bnb.example.ru/', '');
        $texts = self::texts($page['elements']);
        $all = implode("\n", $texts);
        foreach (['h2 О нас', 'h2 Услуги и цены', 'h2 Контакты', 'list Маникюр от 500 руб. | Педикюр от 1100 руб. | Коррекция бровей от 400 руб.', 'button Записаться https://booking.example.com/123'] as $want) {
            $this->assertTrue(in_array($want, $texts, true), "one-page site: {$want}");
        }
        $this->assertContains('Мы в соцсетях', $all, '"hidden md:flex" is shown on a computer');
        $this->assertNotContains('Ваша заявка отправлена', $all, 'the message of the form dialog is not content');
        $this->assertNotContains('list О нас', $all, 'the in-page menu of anchors is not content');
    }

    public function testQueueGivesEverySectionAShare(): void
    {
        $b = 'https://x.example.ru';
        $queue = ["$b/", "$b/contacts/", "$b/service/a/", "$b/service/b/", "$b/service/c/", "$b/about/news/n1/", "$b/about/news/n2/", "$b/about/", "$b/price/"];
        $this->assertSame(
            ["$b/", "$b/contacts/", "$b/about/", "$b/price/", "$b/service/a/", "$b/about/news/n1/", "$b/service/b/", "$b/about/news/n2/", "$b/service/c/"],
            Generic::prioritize($queue, 2),
            'the home page and the menu as they were, then pages one level deep, then a page of every section in turn'
        );
        $queue = ["$b/", "$b/uslugi/x/y1/", "$b/uslugi/x/y2/", "$b/uslugi/z/", "$b/uslugi/x/"];
        $this->assertSame(["$b/", "$b/uslugi/z/", "$b/uslugi/x/", "$b/uslugi/x/y1/", "$b/uslugi/x/y2/"], Generic::prioritize($queue, 1), 'a section page goes before its children');
    }
}
