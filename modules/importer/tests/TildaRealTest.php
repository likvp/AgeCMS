<?php
declare(strict_types=1);

use AgeCMS\Modules\Importer\Extract\{Tilda, TildaZip};
use AgeCMS\Modules\Importer\Neutral;

require_once dirname(__DIR__) . '/src/autoload.php';

/**
 * Tilda on the markup of real projects: shared header and footer zones, a header that breaks the HTML parser, Zero
 * Blocks as a menu and as a row of logos, mobile copies of records, popups, link cards, posts of Tilda Feeds, products of
 * the store, pictures behind lazy load, and project exports with copies of pages, body files and a footer page.
 */
final class TildaRealTest extends TestCase
{
    private const FX = __DIR__ . '/fixtures/tilda';

    private static function fx(string $file): string
    {
        return (string)file_get_contents(self::FX . '/' . $file);
    }

    /** @param list<array<string,mixed>> $els */
    private static function texts(array $els): string
    {
        $out = [];
        foreach ($els as $el) {
            $out[] = match ($el['type']) {
                'heading' => $el['text'],
                'paragraph' => Neutral::text((string)$el['html']),
                'hero' => $el['h1'] . ' ' . $el['subtitle'],
                'button' => $el['text'],
                'list' => implode(' ', array_map('strip_tags', $el['items'])),
                default => '',
            };
        }
        return implode(' | ', $out);
    }

    public function testRecordsSurviveABrokenHeaderAndZonesGiveMenus(): void
    {
        $page = Tilda::parsePage(self::fx('real-page.html'), 'https://ulybka.example.ru/sedatsiya');
        $this->assertTrue($page !== null);
        $els = $page['elements'];
        $this->assertSame('hero', $els[0]['type']);
        $this->assertSame('Лечение зубов во сне', $els[0]['h1'], 'a line break is a space');
        $this->assertSame('Записаться', $els[0]['cta_text'], 'the style inside the button is not text');
        $this->assertSame('https://static.tildacdn.com/tild2222-aaaa-bbbb-cccc-000000000002/cover.jpg', $els[0]['image'], 'the original, not the lazy-load copy');
        $this->assertSame('Лечение зубов во сне', $page['title']);
        $this->assertSame(['/page555001.html'], $page['aliases'], 'every Tilda page also answers at /page<id>.html');
        $this->assertSame('https://static.tildacdn.com/tild3031-3134-4139-b734-303666313361/og.jpg', $page['og_image']);

        $paragraphs = array_values(array_filter($els, static fn(array $e): bool => $e['type'] === 'paragraph'));
        $this->assertSame('Первый абзац о лечении.', Neutral::text($paragraphs[0]['html']), 'paragraphs split at <br><br>');
        $this->assertContains('<strong>важным</strong>', $paragraphs[1]['html']);
        $this->assertContains('<br', $paragraphs[1]['html'], 'a single line break stays');

        $text = self::texts($els);
        $this->assertContains('Наши партнёры', $text);
        $this->assertNotContains('(телефон)', $text, 'the mobile copy of a record (t-screenmax) is left out');
        $this->assertNotContains('cookie', $text);
        $this->assertNotContains('всплывающего окна', $text, 'a Zero Block shown in a popup is not content');
        $this->assertNotContains('Услуги', $text, 'the header Zero Block is the menu, not content');
        $this->assertNotContains('Киров', $text, 'the shared footer is not content');

        $gallery = array_values(array_filter($els, static fn(array $e): bool => $e['type'] === 'gallery'));
        $this->assertSame(1, count($gallery), 'a Zero Block of logos is one gallery');
        $this->assertSame(6, count($gallery[0]['images']), 'repeated logos, icons and SVG are left out');

        $faq = array_values(array_filter($els, static fn(array $e): bool => $e['type'] === 'faq'));
        $this->assertSame([['q' => 'Больно ли это?', 'a' => 'Нет, вы спите.'], ['q' => 'Сколько длится?', 'a' => 'Около часа.']], $faq[0]['items']);

        $lists = array_values(array_filter($els, static fn(array $e): bool => $e['type'] === 'list'));
        $this->assertSame(6, count($lists[0]['items']), 'six link cards are one list of links');
        $this->assertSame('<a href="https://ulybka.example.ru/stati/a1">Статья один</a>', $lists[0]['items'][0]);
        $this->assertContains('Читайте также', $text);

        $buttons = array_values(array_filter($els, static fn(array $e): bool => $e['type'] === 'button'));
        $this->assertSame([['Подробнее', 'https://ulybka.example.ru/kniga']], array_map(static fn(array $b): array => [$b['text'], $b['url']], $buttons), 'the linked title of a card keeps its link');

        $this->assertSame(['Услуги', 'Врачи', 'Цены', 'Контакты'], array_column($page['menus']['header'], 'title'), 'no logo, no phone');
        $this->assertSame(['Политика конфиденциальности', 'Оферта'], array_column($page['menus']['footer'], 'title'));
        $notes = implode('; ', array_column($page['notes'], 'what'));
        $this->assertContains('Zero Block rec200003', $notes);
        $this->assertNotContains('подвал', $notes, 'the footer note is for the home page only');
    }

    public function testFooterNoteOnHomeAndFooterAsContentOfAnEmptyPage(): void
    {
        $home = Tilda::parsePage(self::fx('real-page.html'), 'https://ulybka.example.ru/');
        $this->assertContains('Общий подвал сайта', implode('; ', array_column($home['notes'], 'what')));

        $html = preg_replace('~<!--/header-->.*<!--footer-->~s', '<!--/header--><!--footer-->', self::fx('real-page.html'));
        $contacts = Tilda::parsePage((string)$html, 'https://ulybka.example.ru/contacts');
        $this->assertContains('ул. Примерная', self::texts($contacts['elements']), 'a page with nothing of its own shows the footer');
        $this->assertContains('Содержимое из подвала', implode('; ', array_column($contacts['notes'], 'what')));
        $this->assertTrue(!isset($contacts['skip']));

        $empty = Tilda::parsePage('<html><body><!--allrecords--><div id="allrecords"><div id="rec1" class="r t-rec" data-record-type="450"><div class="t450"><a href="/a">A</a><a href="/b">B</a></div></div></div><!--/allrecords--></body></html>', 'https://ulybka.example.ru/menu');
        $this->assertSame([], $empty['elements']);
        $this->assertContains('без содержимого', (string)$empty['skip'], 'a page that is only a menu is marked to skip');
    }

    public function testFeedsPostIsAPostWithDateAndText(): void
    {
        $post = Tilda::parsePage(self::fx('tpost.html'), 'https://ulybka.example.ru/blog/tpost/abc123-kak-podgotovitsya');
        $this->assertSame('post', $post['kind']);
        $this->assertSame('2025-11-20', $post['published_at']);
        $this->assertSame('Как подготовиться к приёму', $post['title']);
        $this->assertSame('/blog', $post['parent_old_path'], 'the feed page');
        $els = $post['elements'];
        $this->assertSame('hero', $els[0]['type']);
        $this->assertSame('https://static.tildacdn.com/tild7777-aaaa-bbbb-cccc-000000000002/cover.jpg', $els[0]['image']);
        $text = self::texts($els);
        $this->assertContains('Первый абзац записи. | Второй абзац записи.', $text, 'one paragraph per <br><br> part');
        $this->assertContains('пункт один пункт два', $text);
        $this->assertNotContains('Автор', $text);
        $this->assertNotContains('Наш блог', $text);
        $images = array_column(array_values(array_filter($els, static fn(array $e): bool => $e['type'] === 'image')), 'src');
        $this->assertSame(['https://static.tildacdn.com/tild7777-aaaa-bbbb-cccc-000000000003/inside.jpg'], $images);
        $this->assertSame([], $post['notes']);
    }

    public function testStoreProductIsMarkedToSkip(): void
    {
        $page = Tilda::parsePage(self::fx('real-page.html'), 'https://ulybka.example.ru/catalog/tproduct/123456-kolpak');
        $this->assertSame([], $page['elements']);
        $this->assertContains('магазин', (string)$page['skip']);
        $this->assertSame('Товар магазина Tilda', $page['notes'][0]['what']);
    }

    public function testEmptyCoverTitleAndProjectDomain(): void
    {
        $html = '<html><head><title>Клиника | Город</title></head><body><!--allrecords--><div id="allrecords" class="t-records">'
            . '<div id="rec1" class="r t-rec" data-record-type="160"><div class="t-cover"><div class="t-cover__carrier" data-content-cover-bg="https://static.tildacdn.com/tild1234-aaaa-bbbb-cccc-000000000001/slide.jpg"></div></div></div>'
            . '<div id="rec2" class="r t-rec" data-record-type="106"><div class="t-text">Текст со <a href="https://proekt.tilda.ws/ceny">ссылкой на цены</a>.</div></div>'
            . '<div id="rec3" class="r t-rec" data-record-type="191"><a class="t-btn" href="http://proekt.tilda.ws/zapis">Записаться</a></div>'
            . '<div id="rec4" class="r t-rec" data-record-type="205"><div class="t-cover"><div class="t-cover__carrier" data-original="https://static.tildacdn.com/tild1234-aaaa-bbbb-cccc-000000000002/b.jpg"></div><h2 class="t-title">Запишитесь на приём</h2></div></div>'
            . '</div><!--/allrecords--></body></html>';
        $page = Tilda::parsePage($html, 'https://klinika.example.ru/');
        $this->assertSame(['image', 'paragraph', 'button', 'hero'], array_column($page['elements'], 'type'), 'a cover with no text is a picture');
        $this->assertSame('Клиника', $page['title'], 'a call to action further down is not the title');
        $this->assertContains('href="https://klinika.example.ru/ceny"', $page['elements'][1]['html'], 'the tilda.ws address of the project is this site');
        $this->assertSame('https://klinika.example.ru/zapis', $page['elements'][2]['url']);
    }

    public function testImageUrlGivesTheOriginal(): void
    {
        $this->assertSame('https://static.tildacdn.com/tild3435-3963-4230-b462-366261373465/photo.jpg', Tilda::imageUrl('https://thb.tildacdn.com/tild3435-3963-4230-b462-366261373465/-/resize/504x/photo.jpg'));
        $this->assertSame('https://static.tildacdn.com/tild3435-3963-4230-b462-366261373465/photo.jpg', Tilda::imageUrl('https://optim.tildacdn.com/tild3435-3963-4230-b462-366261373465/-/format/webp/photo.jpg'));
        $this->assertSame('https://static.tildacdn.com/tild3435-3963-4230-b462-366261373465/a.png', Tilda::imageUrl('//thb.tildacdn.com/tild3435-3963-4230-b462-366261373465/-/cover/360x230/center/center/a.png'));
        $this->assertSame('https://static.tildacdn.net/tild3435-3963-4230-b462-366261373465/photo.jpg', Tilda::imageUrl('https://static.tildacdn.net/tild3435-3963-4230-b462-366261373465/photo.jpg'));
        $this->assertSame('https://example.ru/a.jpg', Tilda::imageUrl('https://example.ru/a.jpg'));
    }

    public function testExportWithCopiesBodyFilesAndAFooterPage(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->assertTrue(true);
            return;
        }
        $rec = static fn(int $id, string $inner): string => '<div id="rec' . $id . '" class="r t-rec" data-record-type="106"><div class="t-text">' . $inner . '</div></div>';
        $page = static fn(int $pid, string $title, string $main): string => '<!DOCTYPE html><html><head><title>' . $title . '</title></head><body><!--allrecords--><div id="allrecords" class="t-records" data-tilda-page-id="' . $pid . '">'
            . $main . '<!--footer--><footer id="t-footer" class="t-records">' . $rec(900, 'Подвал проекта, телефон и адрес') . '</footer><!--/footer--></div><!--/allrecords--></body></html>';
        $files = [
            'htaccess' => "ErrorDocument 404 /page13.html\nDirectoryIndex page10.html\nRewriteEngine On\nRewriteRule ^about$ page11.html [NC]\nRewriteRule ^about/$ page11.html [NC]\nRewriteRule ^o-nas$ page11.html [NC]\nRewriteRule ^footer$ page12.html [NC]\n",
            'robots.txt' => "User-Agent: *\nDisallow: /page11.html\nDisallow: /footer\n",
            'sitemap.xml' => '<?xml version="1.0"?><urlset><url><loc>https://proj.example.ru/</loc></url></urlset>',
            'page10.html' => $page(10, 'Главная', $rec(101, 'Текст главной страницы')),
            'index.html' => $page(10, 'Главная', $rec(101, 'Текст главной страницы')),
            'page11.html' => $page(11, 'О нас', $rec(111, 'Текст страницы о нас')),
            'page12.html' => $page(12, 'Footer', $rec(900, 'Подвал проекта, телефон и адрес')),
            'page13.html' => $page(13, 'Ошибка 404', $rec(131, 'Страница не найдена')),
            'page14.html' => '<!DOCTYPE html><html><head><title>Каталог</title><script src="js/tilda-blocks-page14.min.js"></script></head><body><main>оболочка</main><script src="js/tilda-body-loader.js"></script></body></html>',
            'files/page14body.html' => '<!--allrecords--><div id="allrecords" class="t-records" data-tilda-page-id="14">' . $rec(141, 'Текст каталога из файла тела') . '</div><!--/allrecords-->',
            'files/page10body.html' => '<div id="allrecords">' . $rec(101, 'Текст главной страницы') . '</div>',
        ];
        $zipFile = TEST_TMP . '/tilda-real-export.zip';
        @mkdir(TEST_TMP, 0700, true);
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();

        $extractor = new TildaZip(['file' => $zipFile, 'dir' => TEST_TMP . '/tilda-real-job']);
        $state = [];
        $pages = [];
        while (!$extractor->step($state, microtime(true) + 5, static function (array $p) use (&$pages): void {
            $pages[$p['old_path']] = $p;
        })) {
        }
        $this->assertSame(['/', '/about', '/page14.html'], array_keys($pages), 'no copy, no error page, no footer page, no body files');
        $sorted = static function (array $a): array {
            sort($a);
            return $a;
        };
        $this->assertSame(['/index.html', '/page10.html'], $sorted($pages['/']['aliases']));
        $this->assertSame(['/o-nas', '/page11.html'], $sorted($pages['/about']['aliases']), 'every address of htaccess is kept');
        $this->assertTrue($pages['/about']['is_noindex'], 'closed in robots.txt');
        $this->assertTrue(!$pages['/']['is_noindex']);
        $this->assertContains('Текст каталога из файла тела', self::texts($pages['/page14.html']['elements']), 'a shell page takes its records from files/page14body.html');
        $this->assertSame('Каталог', $pages['/page14.html']['title']);
        $this->assertNotContains('Подвал проекта', self::texts($pages['/about']['elements']));
        $this->assertContains('page12.html', implode(' ', $state['warnings']));
        $this->assertSame('https://proj.example.ru/', $state['site']['source_url']);
    }
}
