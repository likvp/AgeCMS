<?php
declare(strict_types=1);

use AgeCMS\{Actor, Content, Database, Pages, SkillRegistry};
use AgeCMS\Modules\Importer\{Compat, Importer, Jobs, Mapper, Neutral, Planner, Report, Runner, Url};
use AgeCMS\Modules\Importer\Detect\Detector;
use AgeCMS\Modules\Importer\Extract\{Generic, Gutenberg, Tilda};
use AgeCMS\Modules\Importer\Html\Sanitizer;
use AgeCMS\Modules\Importer\Net\{ArrayFetcher, Network, Response, Robots};

require_once dirname(__DIR__, 3) . '/tests/support/HttpCase.php';
require_once dirname(__DIR__) . '/src/autoload.php';

/**
 * Importer module: extractors on small fixtures of each platform, the mapping into theme blocks, addresses and
 * redirects, the stepwise job with rollback, the skills and the admin page. No network: pages come from ArrayFetcher.
 */
final class ImporterTest extends HttpCase
{
    private const FX = __DIR__ . '/fixtures';

    private static function fx(string $file): string
    {
        return (string)file_get_contents(self::FX . '/' . $file);
    }

    /** A small real JPEG for the media library. */
    private static function jpeg(int $w = 40, int $h = 30, int $color = 0x3366aa): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, $color);
        ob_start();
        imagejpeg($im, null, 80);
        return (string)ob_get_clean();
    }

    /** @return array<string,mixed> */
    private static function staticSite(): array
    {
        $b = 'https://bread.example.net';
        return [
            "$b/" => self::fx('static/index.html'),
            "$b/robots.txt" => [200, self::fx('static/robots.txt'), ['content-type' => 'text/plain']],
            "$b/sitemap.xml" => [200, self::fx('static/sitemap.xml'), ['content-type' => 'application/xml']],
            "$b/sitemap-pages.xml" => [200, self::fx('static/sitemap-pages.xml'), ['content-type' => 'application/xml']],
            "$b/about.html" => self::fx('static/about.html'),
            "$b/dostavka/" => self::fx('static/dostavka.html'),
            "$b/private/secret.html" => '<html><body><h1>Секрет</h1></body></html>',
            "$b/img/oven.jpg" => [200, self::jpeg(), ['content-type' => 'image/jpeg']],
        ];
    }

    /** @return array<string,mixed> */
    private static function wordpressSite(): array
    {
        $b = 'https://wp.example.com';
        return [
            "$b/" => self::fx('wordpress/home.html'),
            "$b/wp-json/wp/v2/pages?*" => self::fx('wordpress/pages.json'),
            "$b/wp-json/wp/v2/posts?*" => self::fx('wordpress/posts.json'),
            "$b/uslugi/" => self::fx('wordpress/uslugi.html'),
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<int,array<string,mixed>>} analysis, plan, pages by old path */
    private function dry(string $input, array $map, array $options = [], ?array $blocks = null, ?callable $taken = null): array
    {
        $importer = new Importer($options + ['delay_ms' => 0], new ArrayFetcher($map));
        $analysis = $importer->analyze($input, null, TEST_TMP . '/import-' . bin2hex(random_bytes(3)));
        $pages = [];
        foreach ($analysis['pages'] as $i => $p) {
            $p['id'] = $i + 1;
            $pages[] = $p;
        }
        $plan = $importer->plan($pages, $analysis['site'], $blocks ?? self::themeWithArticle(), $taken ?? static fn(string $s): ?string => null);
        $byPath = [];
        foreach ($pages as $p) {
            $byPath[$p['old_path']] = $p + ['plan' => $plan['items'][$p['id']]];
        }
        return [$analysis, $plan, $byPath];
    }

    /** The default theme as of the importer contract: hero, text, image, faq, lead_form, article (richtext), child_pages. */
    private static function themeWithArticle(): array
    {
        $theme = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/themes/default/theme.json'), true)['blocks'];
        $theme['article'] ??= ['fields' => ['heading' => ['type' => 'string', 'max' => 200], 'body' => ['type' => 'richtext', 'max' => 200000, 'required' => true]]];
        $theme['child_pages'] ??= ['fields' => ['heading' => ['type' => 'string', 'max' => 200], 'parent' => ['type' => 'string', 'max' => 120]]];
        return $theme;
    }

    /** @param array<string,mixed> $doc @return list<string> */
    private static function types(array $doc): array
    {
        return array_map(static fn(array $b): string => $b['type'], $doc['blocks']);
    }

    // ---- detection, parsing ------------------------------------------------------------------------------------

    public function testDetectorRecognisesPlatformsWithEvidence(): void
    {
        $r = static fn(string $html, array $h = []): array => Detector::detect(new Response(200, $html, $h + ['content-type' => 'text/html'], 'https://x.test/'));
        $wp = $r(self::fx('wordpress/home.html'));
        $this->assertSame('wordpress', $wp['platform']);
        $this->assertTrue($wp['confidence'] > 0.9);
        $this->assertContains('meta generator «WordPress 6.4.2»', implode('; ', $wp['evidence']));
        $this->assertSame('tilda', $r(self::fx('tilda/page.html'))['platform']);
        $bx = $r(self::fx('bitrix/home.html'), ['set-cookie' => 'BITRIX_SM_GUEST_ID=5; path=/']);
        $this->assertSame('bitrix', $bx['platform']);
        $this->assertContains('cookie BITRIX_SM', implode('; ', $bx['evidence']));
        $this->assertSame('joomla', $r('<html><head><meta name="generator" content="Joomla! - Open Source Content Management"></head><body><script src="/media/jui/js/jquery.min.js"></script></body></html>')['platform']);
        $this->assertSame('wix', $r('<html><head><meta name="generator" content="Wix.com Website Builder"></head><body><img src="https://static.wixstatic.com/media/a.jpg"></body></html>')['platform']);
        $this->assertSame('modx', $r('<html><body><link href="/assets/components/minishop2/css/web/default.css"><!-- MODX Revolution --></body></html>', ['x-powered-by' => 'MODX Revolution'])['platform']);
        $this->assertSame('hostcms', $r('<html><body><img src="/hostcmsfiles/logo.png"></body></html>')['platform']);
        $this->assertSame('webflow', $r('<html data-wf-page="abc" data-wf-site="def"><body></body></html>')['platform']);
        $plain = $r(self::fx('static/index.html'));
        $this->assertSame('generic', $plain['platform']);
    }

    public function testSanitizerKeepsTheWhiteListOnly(): void
    {
        $html = Sanitizer::rich('<div class="x" style="color:red"><h1>Заголовок</h1><p onclick="x()">Текст <b>жирный</b> <a href="javascript:alert(1)">плохая</a> <a href="/about" target="_blank">хорошая</a></p><script>alert(1)</script><iframe src="https://evil"></iframe><img src="https://a.test/i.jpg" alt="Пример" width="1" height="1"><img src="https://a.test/big.jpg" alt="Большая"></div>', 'https://old.test/page/');
        $this->assertSame('<h2>Заголовок</h2><p>Текст <strong>жирный</strong> плохая <a href="https://old.test/about">хорошая</a></p><img src="https://a.test/big.jpg" alt="Большая">', $html);
        $this->assertSame('a<br>b', Sanitizer::inline('<p>a</p><p>b</p>'));
        $this->assertSame('<a href="tel:+79991234567">звонок</a>', Sanitizer::inline('<a href="tel:+7 (999) 123-45-67">звонок</a>'));
    }

    public function testRobotsAndUrlHelpers(): void
    {
        $robots = Robots::parse(self::fx('static/robots.txt'));
        $this->assertTrue($robots->allowed('/about.html'));
        $this->assertTrue(!$robots->allowed('/private/secret.html'), 'Disallow for * is kept, the Yandex group does not apply');
        $this->assertSame(['https://bread.example.net/sitemap.xml'], $robots->sitemaps);
        $this->assertSame('https://a.test/x/c.html', Url::resolve('https://a.test/x/y/b.html', '../c.html'));
        $this->assertSame('/?p=12', Url::oldPath('https://a.test/?p=12&utm_source=x#top'));
        $this->assertSame('/о-нас/', Url::oldPath('https://a.test/%D0%BE-%D0%BD%D0%B0%D1%81/'));
        $this->assertSame('/about', Url::key('/About/index.html'));
        $this->assertSame('o-kompanii/komanda', Planner::slugFromPath('/o-kompanii/komanda/'));
        $this->assertSame('o-nas', Planner::slugFromPath('/о-нас/'));
        $this->assertSame('uslugi/remont', Planner::slugFromPath('/index.php/uslugi/remont.html'));
        $this->assertSame('', Planner::slugFromPath('/?p=12'));
        $this->assertSame('', Planner::slugFromPath('/admin/'), 'a reserved first part');
    }

    public function testGutenbergCommentsAndClassicContent(): void
    {
        $blocks = Gutenberg::parse("<!-- wp:group --><div class=\"wp-block-group\"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:group -->\n<!-- wp:spacer {\"height\":\"20px\"} /--><p>tail</p>");
        $this->assertSame(['core/group', 'core/spacer', 'freeform'], array_column($blocks, 'name'));
        $this->assertSame('core/paragraph', $blocks[0]['parts'][1]['name']);
        $this->assertSame(['height' => '20px'], $blocks[1]['attrs']);
        $this->assertSame("<p>Первая строка<br>\nвторая</p>\n<p>Абзац</p>\n", Gutenberg::autop("Первая строка\nвторая\n\nАбзац"));
        $notes = [];
        $els = Gutenberg::toElements("Текст [unknown_sc a=1]внутри[/unknown_sc]\n\n[vc_row][vc_column]Колонка[/vc_column][/vc_row]", 'https://a.test/', [], [], $notes);
        $this->assertSame('paragraph', $els[0]['type']);
        $this->assertContains('внутри', $els[0]['html']);
        $whats = implode('; ', array_column($notes, 'what'));
        $this->assertContains('Шорткод [unknown_sc]', $whats);
        $this->assertContains('конструктора', $whats);
    }

    // ---- extractors --------------------------------------------------------------------------------------------

    public function testGenericCrawlsTheSitemapAndRespectsRobots(): void
    {
        [$a, $plan, $p] = $this->dry('https://bread.example.net/', self::staticSite());
        $this->assertSame(['/', '/about.html', '/dostavka/'], array_keys($p), 'the private page (robots) and the pdf are left out');
        $this->assertSame('generic', $a['detect']['platform']);
        $home = $p['/']['plan'];
        $this->assertSame('index', $home['slug']);
        $this->assertSame(['hero', 'article', 'faq', 'article'], self::types($home['doc']));
        $this->assertSame('Свежий хлеб каждый день', $home['doc']['blocks'][0]['data']['h1']);
        $this->assertSame(2, count($home['doc']['blocks'][2]['data']['items']), 'details/summary became questions');
        $this->assertContains('карта', implode(' ', array_column($home['losses'], 'what')));
        $this->assertNotContains('cookie', json_encode($home['doc'], JSON_UNESCAPED_UNICODE), 'the cookie banner is junk');
        $about = $p['/about.html']['plan'];
        $this->assertSame('about', $about['slug']);
        $this->assertSame([['from' => '/about.html', 'to' => '/about']], $about['redirects']);
        $this->assertSame('О нас', $p['/about.html']['title'], 'the site name is cut from the <title>');
        $this->assertContains('<figure><img src="https://bread.example.net/img/oven.jpg" alt="Печь"></figure>', $about['doc']['blocks'][1]['data']['body'], 'a picture between paragraphs stays in the article');
        $this->assertNotContains('Ссылка', json_encode($about['doc'], JSON_UNESCAPED_UNICODE), 'the link column is left out by text density');
        $delivery = $p['/dostavka/']['plan'];
        $this->assertContains('<a href="/about">О пекарне</a>', $delivery['doc']['blocks'][1]['data']['body'], 'links to imported pages get the new address');
        $this->assertContains('href="tel:+79991234567"', $delivery['doc']['blocks'][1]['data']['body']);
        $this->assertSame([['title' => 'Главная', 'url' => '/'], ['title' => 'О нас', 'url' => '/about'], ['title' => 'Доставка', 'url' => '/dostavka'], ['title' => 'Telegram', 'url' => 'https://t.me/bread']], $plan['menus']['header']);
    }

    public function testWordPressRestPagesPostsSeoAndBuilders(): void
    {
        [$a, $plan, $p] = $this->dry('https://wp.example.com/', self::wordpressSite());
        $this->assertSame('wordpress', $a['detect']['platform']);
        $this->assertSame(['/o-kompanii/', '/o-kompanii/komanda/', '/uslugi/', '/', '/2024/03/kak-vybrat-plitku/'], array_keys($p));
        $about = $p['/o-kompanii/'];
        $this->assertSame('О компании Строй-Мастер - ремонт с 2005 года', $about['meta_title'], 'Yoast title');
        $this->assertSame('Компания Строй-Мастер: ремонт квартир под ключ с гарантией.', $about['plan']['doc']['meta_description']);
        $this->assertSame(['/?page_id=2'], $about['aliases']);
        $blocks = $about['plan']['doc']['blocks'];
        $this->assertSame('hero', $blocks[0]['type']);
        $this->assertSame(['h1' => 'Строим с 2005 года', 'subtitle' => 'Более 500 квартир под ключ', 'cta_text' => 'Оставить заявку', 'cta_url' => 'https://wp.example.com/kontakty/'], $blocks[0]['data'], 'the cover is the first screen');
        $this->assertSame('https://wp.example.com/wp-content/uploads/2023/01/cover.jpg', $blocks[1]['data']['src'], 'the cover picture follows: the hero of the theme has no picture');
        $article = $blocks[2]['data'];
        $this->assertSame('Почему мы', $article['heading']);
        $this->assertContains('<a href="/uslugi">услуги</a>', $article['body']);
        $this->assertContains('team.jpg', $article['body'], 'the largest srcset candidate');
        $this->assertContains('<figcaption>Команда на объекте</figcaption>', $article['body']);
        $types = self::types($about['plan']['doc']);
        $this->assertTrue(in_array('faq', $types, true), 'Yoast FAQ block');
        $this->assertContains('youtube', json_encode($about['plan']['losses']));
        $this->assertSame('o-kompanii', $p['/o-kompanii/komanda/']['plan']['parent_slug']);

        $services = $p['/uslugi/'];
        $this->assertSame('Услуги & цены', $services['title']);
        $this->assertContains('конструктором', json_encode($services['notes'], JSON_UNESCAPED_UNICODE), 'Elementor page is taken from the rendered address');
        $this->assertSame('Услуги и цены', $services['plan']['doc']['blocks'][0]['data']['h1']);
        $this->assertTrue(in_array('faq', self::types($services['plan']['doc']), true), 'Elementor accordion');

        $post = $p['/2024/03/kak-vybrat-plitku/'];
        $this->assertSame('blog/kak-vybrat-plitku', $post['plan']['slug']);
        $this->assertSame('blog', $post['plan']['parent_slug']);
        $this->assertSame(1, $post['plan']['doc']['is_noindex']);
        $this->assertSame('Article', $post['plan']['doc']['schema_type']);
        $this->assertSame(['/?p=12', '/2024/03/kak-vybrat-plitku/'], array_values(array_unique(array_merge($post['aliases'], [$post['old_path']]))));
        $this->assertSame('index', $p['/']['plan']['slug']);
        $section = $plan['items']['#section:blog'];
        $this->assertSame('blog', $section['slug']);
        $this->assertSame(['hero', 'child_pages'], self::types($section['doc']));
        $this->assertSame('blog', $section['doc']['blocks'][1]['data']['parent']);
        $this->assertSame(['Главная', 'О компании', 'Услуги', 'Блог'], array_column($plan['menus']['header'], 'title'), 'the menu keeps imported pages and the new blog section');
    }

    public function testWordPressWithoutRestFallsBackToCrawling(): void
    {
        $map = self::wordpressSite();
        unset($map['https://wp.example.com/wp-json/wp/v2/pages?*'], $map['https://wp.example.com/wp-json/wp/v2/posts?*']);
        [$a, , $p] = $this->dry('https://wp.example.com/', $map);
        $this->assertContains('REST API WordPress закрыт', implode(' ', $a['warnings']));
        $this->assertTrue(isset($p['/uslugi/']), 'the menu links are crawled');
    }

    public function testWxrExportPagesPostsMenusAndShortcodes(): void
    {
        [$a, $plan, $p] = $this->dry(self::FX . '/export.xml', []);
        $this->assertSame('Кофейня Зерно', $a['site']['title']);
        $this->assertSame(['/o-nas/', '/o-nas/vakansii/', '/novyj-sort/'], array_keys($p), 'the draft is left out');
        $this->assertContains('draft', implode(' ', $a['warnings']));
        $about = $p['/o-nas/'];
        $this->assertSame('О нас - Кофейня Зерно', $about['meta_title'], 'Yoast variables are filled');
        $this->assertSame('', $about['plan']['doc']['meta_title'], 'the name of the old site is cut off; the page title is left, so the core uses the title');
        $this->assertSame('Свежая обжарка', Mapper::metaTitle('Свежая обжарка | Кофейня Зерно', 'Новый сорт', 'Кофейня Зерно'));
        $this->assertSame('Block: Cover', Mapper::metaTitle('Block: Cover - Import Lab WP', 'Cover', 'Import Lab WP'));
        $this->assertSame('История кофейни Зерно.', $about['meta_description']);
        $body = $about['plan']['doc']['blocks'][1]['data']['body'];
        $this->assertSame('Наша история', $about['plan']['doc']['blocks'][1]['data']['heading']);
        $this->assertContains('Текст из многоразового блока.', $body, 'the reusable block is looked up');
        $this->assertContains('<li>Эспрессо</li>', $body);
        $this->assertContains('cup.jpg', $body, 'a picture in a column');
        $this->assertContains('latest-posts', json_encode($about['plan']['losses']));
        $this->assertSame('o-nas', $p['/o-nas/vakansii/']['plan']['parent_slug']);
        $this->assertContains('<p>Ищем бариста.</p>', $p['/o-nas/vakansii/']['plan']['doc']['blocks'][1]['data']['body'], 'classic content gets paragraphs');
        $post = $p['/novyj-sort/'];
        $this->assertSame('blog/novyj-sort', $post['plan']['slug']);
        $this->assertSame('2024-02-14', $post['published_at']);
        $this->assertSame('https://coffee.example.org/wp-content/uploads/beans.jpg', $post['og_image'], 'the featured image');
        $types = self::types($post['plan']['doc']);
        $this->assertTrue(in_array('lead_form', $types, true), 'the contact form becomes the lead form');
        $this->assertContains('contact-form-7', json_encode($post['plan']['losses']));
        $this->assertSame([['from' => '/novyj-sort/', 'to' => '/blog/novyj-sort'], ['from' => '/?p=20', 'to' => '/blog/novyj-sort']], $post['plan']['redirects']);
        $this->assertSame([['title' => 'О нас', 'url' => '/o-nas'], ['title' => 'Instagram', 'url' => 'https://instagram.com/zerno']], $plan['menus']['header']);
    }

    public function testTildaRecordsCoverZeroBlockFaqAndForm(): void
    {
        $page = Tilda::parsePage(self::fx('tilda/page.html'), 'https://prana.example.ru/');
        $this->assertTrue($page !== null);
        $els = $page['elements'];
        $this->assertSame('hero', $els[0]['type']);
        $this->assertSame(['Йога для каждого', 'Первое занятие бесплатно', 'Записаться', 'https://prana.example.ru/#zapis', 'https://static.tildacdn.com/tild6564-3262/cover.jpg'], [$els[0]['h1'], $els[0]['subtitle'], $els[0]['cta_text'], $els[0]['cta_url'], $els[0]['image']]);
        $types = array_column($els, 'type');
        $this->assertSame(['hero', 'heading', 'paragraph', 'image', 'heading', 'image', 'paragraph', 'heading', 'faq', 'heading', 'form'], $types);
        $this->assertSame('https://static.tildacdn.com/tild3335-6263/hall.jpg', $els[3]['src'], 'data-original, not the blurred preview');
        $this->assertSame(['Заголовок зеро', 'https://static.tildacdn.com/tild1111-2222/zero.png', 'Нижний текст зеро'], [$els[4]['text'], $els[5]['src'], strip_tags($els[6]['html'])], 'Zero Block atoms from top to bottom');
        $this->assertSame([['q' => 'Что взять с собой?', 'a' => 'Удобную одежду и воду.'], ['q' => 'Нужен ли опыт?', 'a' => 'Нет, начнём с основ.']], $els[8]['items']);
        $this->assertSame(['Ваше имя', 'Телефон'], array_column($els[10]['fields'], 'label'));
        $notes = implode('; ', array_column($page['notes'], 'what'));
        $this->assertContains('Zero Block', $notes);
        $this->assertContains('Всплывающее окно', $notes);
        $this->assertSame(['Главная', 'Расписание', 'Цены'], array_column($page['menus']['header'], 'title'));
        $this->assertSame(['Политика', 'ВКонтакте'], array_column($page['menus']['footer'], 'title'));

        $mapper = new Mapper(self::themeWithArticle());
        $mapped = $mapper->map($page);
        $this->assertSame('https://prana.example.ru/#zapis', $mapped['blocks'][0]['data']['cta_url'], 'without a link map the address stays as it was');
        $this->assertSame(['hero', 'image', 'article', 'faq', 'lead_form'], array_column($mapped['blocks'], 'type'), 'pictures next to text and short sections stay in one article');
        $this->assertContains('<figure><img src="https://static.tildacdn.com/tild3335-6263/hall.jpg"', $mapped['blocks'][2]['data']['body']);
        $this->assertContains('<h2>Заголовок зеро</h2>', $mapped['blocks'][2]['data']['body']);
        $this->assertSame('Вопросы', $mapped['blocks'][3]['data']['heading'], 'the title of the FAQ record');
        $this->assertSame('Записаться', $mapped['blocks'][4]['data']['heading']);
        $this->assertSame('hero_1', $mapped['anchors'][explode(' ', $els[0]['source'])[0]] ?? null, 'the record of the cover is the hero block');
    }

    public function testTildaExportArchive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->assertTrue(true);
            return;
        }
        $zipFile = TEST_TMP . '/tilda-export.zip';
        @mkdir(TEST_TMP, 0700, true);
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('project123/page456789.html', self::fx('tilda/page.html'));
        $zip->addFromString('project123/page456790.html', self::fx('tilda/page456790.html'));
        $zip->addFromString('project123/htaccess', self::fx('tilda/htaccess'));
        $zip->addFromString('project123/images/tild9999-raspisanie.jpg', self::jpeg());
        $zip->addFromString('project123/js/tilda.js', 'evil();');
        $zip->addFromString('../escape.html', 'x');
        $zip->close();

        [$a, $plan, $p] = $this->dry($zipFile, []);
        $this->assertSame('tilda', $a['detect']['platform']);
        $this->assertSame(['/', '/raspisanie'], array_keys($p));
        $sched = $p['/raspisanie'];
        $this->assertSame(['/page456790.html'], $sched['aliases']);
        $this->assertSame('raspisanie', $sched['plan']['slug']);
        $bodies = implode(' ', array_map(static fn(array $b): string => (string)($b['data']['body'] ?? $b['data']['src'] ?? ''), $sched['plan']['doc']['blocks']));
        $this->assertContains('local:images/tild9999-raspisanie.jpg', $bodies, 'a picture of the archive is taken from disk');
        $this->assertContains('<a href="/">На главную</a>', $bodies, 'the link to page456789.html leads to the home page');
        $this->assertSame([['from' => '/page456790.html', 'to' => '/raspisanie']], $sched['plan']['redirects']);
        $this->assertSame(['Главная', 'Расписание'], array_column($plan['menus']['header'], 'title'));
        $this->assertTrue(!is_file(TEST_TMP . '/escape.html'), 'nothing is unpacked outside the job folder');
    }

    public function testBitrixNewsWithDateBreadcrumbsAndCp1251(): void
    {
        $b = 'https://metall.example.ru';
        $map = [
            "$b/" => [200, self::fx('bitrix/home.html'), ['content-type' => 'text/html; charset=windows-1251', 'set-cookie' => 'BITRIX_SM_GUEST_ID=1']],
            "$b/sitemap.xml" => [200, '<?xml version="1.0"?><urlset><url><loc>https://metall.example.ru/news/novyj-ceh/</loc></url></urlset>', ['content-type' => 'application/xml']],
            "$b/news/novyj-ceh/" => self::fx('bitrix/news.html'),
        ];
        [$a, $plan, $p] = $this->dry("$b/", $map);
        $this->assertSame('bitrix', $a['detect']['platform']);
        $this->assertSame('Завод Металл', $p['/']['title'], 'windows-1251 is decoded');
        $news = $p['/news/novyj-ceh/'];
        $this->assertSame('post', $news['kind']);
        $this->assertSame('2023-09-12', $news['published_at']);
        $this->assertSame('/news/', $news['parent_old_path'], 'from the breadcrumbs');
        $this->assertSame('news/novyj-ceh', $news['plan']['slug'], 'news stay in their section');
        $doc = json_encode($news['plan']['doc'], JSON_UNESCAPED_UNICODE);
        $this->assertNotContains('12.09.2023', $doc, 'the date is a field, not text');
        $this->assertNotContains('<h3>Открыт новый цех</h3>', $doc, 'the title is not repeated in the text');
        $this->assertContains('<table>', $doc);
        $this->assertSame('Новости', $plan['items']['#section:news']['doc']['title']);
    }

    // ---- mapping and planning ----------------------------------------------------------------------------------

    public function testMapperWithoutRichTextUsesPlainTextAndKeepsLimits(): void
    {
        $theme = [
            'hero' => ['fields' => ['h1' => ['type' => 'string', 'max' => 20, 'required' => true], 'subtitle' => ['type' => 'text', 'max' => 500]]],
            'text' => ['fields' => ['heading' => ['type' => 'string', 'max' => 200], 'body' => ['type' => 'text', 'max' => 120, 'required' => true]]],
            'image' => ['fields' => ['src' => ['type' => 'url', 'media' => true, 'required' => true], 'alt' => ['type' => 'string', 'max' => 200, 'required' => true]]],
            'faq' => ['fields' => ['items' => ['type' => 'list', 'max' => 2, 'of' => ['q' => ['type' => 'string', 'max' => 200, 'required' => true], 'a' => ['type' => 'text', 'max' => 1500, 'required' => true]]]]],
        ];
        $page = \AgeCMS\Modules\Importer\Neutral::page(['title' => 'Очень длинное название страницы', 'elements' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Раздел', 'source' => 'h2'],
            ['type' => 'paragraph', 'html' => 'Текст со <a href="https://x.test/a">ссылкой</a>.', 'source' => 'p'],
            ['type' => 'image', 'src' => 'https://x.test/i.jpg', 'alt' => '', 'caption' => '', 'source' => 'img'],
            ['type' => 'list', 'ordered' => false, 'items' => ['один', 'два'], 'source' => 'ul'],
            ['type' => 'paragraph', 'html' => str_repeat('слово ', 20), 'source' => 'p'],
            ['type' => 'faq', 'items' => [['q' => 'В1', 'a' => '<p>О1</p>'], ['q' => 'В2', 'a' => 'О2'], ['q' => 'В3', 'a' => 'О3']], 'source' => 'faq'],
            ['type' => 'form', 'fields' => [['name' => 'inn', 'label' => 'ИНН', 'type' => 'text']], 'submit_text' => 'Go', 'source' => 'form'],
        ]]);
        $m = new Mapper($theme);
        $this->assertSame(['hero' => 'hero', 'faq' => 'faq', 'image' => 'image', 'text' => 'text'], $m->roles());
        $out = $m->map($page);
        $this->assertSame(['hero', 'text', 'image', 'text', 'text', 'faq', 'faq'], array_column($out['blocks'], 'type'));
        $this->assertSame('Очень длинное назва…', $out['blocks'][0]['data']['h1'], 'cut to max 20');
        $this->assertSame(['heading' => 'Раздел', 'body' => 'Текст со ссылкой (https://x.test/a).'], $out['blocks'][1]['data']);
        $this->assertSame('Очень длинное название страницы', $out['blocks'][2]['data']['alt'], 'a required alt falls back to the title');
        $this->assertSame("- один\n- два", $out['blocks'][3]['data']['body']);
        $this->assertSame('О1', $out['blocks'][5]['data']['items'][0]['a']);
        $this->assertSame(1, count($out['blocks'][6]['data']['items']), 'questions over the list max go to a second block');
        $why = implode('; ', array_column($out['losses'], 'why'));
        $this->assertContains('обрезано', $why);
        $this->assertContains('нет блока формы', $why);
    }

    public function testMapperFollowsImportHintsOfTheTheme(): void
    {
        $theme = [
            'promo' => ['import' => 'hero', 'fields' => ['title' => ['type' => 'string', 'required' => true], 'lead' => ['type' => 'text']]],
            'story' => ['import' => 'article', 'fields' => ['body' => ['type' => 'richtext', 'required' => true]]],
            'qa' => ['import' => 'faq', 'fields' => ['list' => ['type' => 'list', 'of' => ['question' => ['type' => 'string'], 'answer' => ['type' => 'richtext']]]]],
            'hero' => ['fields' => ['h1' => ['type' => 'string']]],
        ];
        $m = new Mapper($theme);
        $this->assertSame('promo', $m->roles()['hero'], 'the hint wins over the name');
        $out = $m->map(\AgeCMS\Modules\Importer\Neutral::page(['title' => 'Т', 'elements' => [
            ['type' => 'hero', 'h1' => 'Привет', 'subtitle' => 'Мир', 'cta_text' => '', 'cta_url' => '', 'image' => '', 'source' => ''],
            ['type' => 'paragraph', 'html' => 'Текст', 'source' => ''],
            ['type' => 'faq', 'items' => [['q' => 'Вопрос', 'a' => '<p>Ответ <strong>жирно</strong></p>']], 'source' => ''],
        ]]));
        $this->assertSame([
            ['id' => 'promo_1', 'type' => 'promo', 'data' => ['title' => 'Привет', 'lead' => 'Мир']],
            ['id' => 'story_1', 'type' => 'story', 'data' => ['body' => '<p>Текст</p>']],
            ['id' => 'qa_1', 'type' => 'qa', 'data' => ['list' => [['question' => 'Вопрос', 'answer' => '<p>Ответ <strong>жирно</strong></p>']]]],
        ], $out['blocks']);
    }

    public function testPlannerConflictsSuffixSkipAndHome(): void
    {
        $pages = [
            ['id' => 1, 'old_path' => '/', 'aliases' => [], 'suggested_slug' => '', 'parent_old_path' => '', 'kind' => 'page', 'title' => 'Главная'],
            ['id' => 2, 'old_path' => '/about/', 'aliases' => ['/?page_id=7'], 'suggested_slug' => '', 'parent_old_path' => '', 'kind' => 'page', 'title' => 'О нас'],
            ['id' => 3, 'old_path' => '/about/team/', 'aliases' => [], 'suggested_slug' => '', 'parent_old_path' => '', 'kind' => 'page', 'title' => 'Команда'],
            ['id' => 4, 'old_path' => '/?p=9', 'aliases' => [], 'suggested_slug' => 'privet-mir', 'parent_old_path' => '', 'kind' => 'post', 'title' => 'Привет'],
            ['id' => 5, 'old_path' => '/Контакты.html', 'aliases' => [], 'suggested_slug' => '', 'parent_old_path' => '/about/', 'kind' => 'page', 'title' => 'Контакты'],
        ];
        $taken = static fn(string $s): ?string => in_array($s, ['index', 'about'], true) ? 'страница' : null;
        $plan = (new Planner(['conflict' => 'suffix'], $taken))->plan($pages, ['source_url' => 'https://old.test/']);
        $e = $plan['pages'];
        $this->assertSame('skip', $e[1]['action'], 'the home page is not replaced by default');
        $this->assertSame('about-2', $e[2]['slug']);
        $this->assertSame('about-2/team', $e[3]['slug'], 'the child follows its renamed parent');
        $this->assertSame('about-2', $e[3]['parent_slug']);
        $this->assertSame('blog/privet-mir', $e[4]['slug']);
        $this->assertSame('kontakty', $e[5]['slug']);
        $this->assertSame('about-2', $e[5]['parent_slug'], 'the parent named by the source');
        $this->assertSame([['from' => '/about/', 'to' => '/about-2'], ['from' => '/?page_id=7', 'to' => '/about-2']], $e[2]['redirects']);
        $this->assertSame('/about-2/team', $plan['links']['/about/team']);
        $this->assertSame('/', $plan['links']['/'], 'links to the home page still lead home');

        $skip = (new Planner(['conflict' => 'skip', 'replace_home' => true], $taken))->plan($pages, []);
        $this->assertSame('update_home', $skip['pages'][1]['action']);
        $this->assertSame('skip', $skip['pages'][2]['action']);
        $this->assertSame('about/team', $skip['pages'][3]['slug'], 'under the existing page');
        $this->assertSame('about', $skip['pages'][3]['parent_slug']);
    }

    // ---- shared rules found on real sites ----------------------------------------------------------------------

    public function testPlannerSectionsSkipMarksFeedsPostsAndCopiesOfAPage(): void
    {
        $page = static fn(int $id, string $path, string $kind = 'page', array $extra = []): array => $extra + ['id' => $id, 'old_path' => $path, 'aliases' => [], 'suggested_slug' => '', 'parent_old_path' => '', 'kind' => $kind, 'title' => 'Страница ' . $id];
        $pages = [
            $page(1, '/', 'page', ['tilda_page_id' => '100']),
            $page(2, '/glavnaya', 'page', ['tilda_page_id' => '100', 'aliases' => ['/page100.html']]),
            $page(3, '/about/news/'),
            $page(4, '/about/news/novyj-metod/', 'post'),
            $page(5, '/news/2020/05/otkrytie', 'post'),
            $page(6, '/blog/tpost/ztn2i7yg61-agentstvo-ili-komanda', 'post'),
            $page(7, '/company'),
            $page(8, '/tpost/ab12cd34e5-kak-podgotovitsya', 'post', ['parent_old_path' => '/company']),
            $page(9, '/catalog/tproduct/929785863942-tovar', 'page', ['skip' => 'товар магазина Tilda: магазин не переносится']),
        ];
        $plan = (new Planner([], static fn(string $s): ?string => null))->plan($pages, ['source_url' => 'https://old.test/']);
        $e = $plan['pages'];
        $this->assertSame(['about/news/novyj-metod', 'about/news', 'about/news'], [$e[4]['slug'], $e[4]['section'], $e[4]['parent_slug']], 'the section of a post is its folder, the imported listing is its page');
        $this->assertSame('about/news', $e[3]['children_of']);
        $this->assertSame(['news/otkrytie', 'news'], [$e[5]['slug'], $e[5]['parent_slug']], 'date folders are not a section');
        $this->assertSame('Новости', array_column($plan['sections'], 'title', 'id')['#section:news']);
        $this->assertSame(['blog/agentstvo-ili-komanda', 'blog'], [$e[6]['slug'], $e[6]['parent_slug']], 'a Feeds post loses "tpost" and the uid');
        $this->assertSame([['from' => '/blog/tpost/ztn2i7yg61-agentstvo-ili-komanda', 'to' => '/blog/agentstvo-ili-komanda']], $e[6]['redirects']);
        $this->assertSame(['company/kak-podgotovitsya', 'company'], [$e[8]['slug'], $e[8]['parent_slug']], 'a post in the root goes under its feed page');
        $this->assertSame(['skip', 'товар магазина Tilda: магазин не переносится'], [$e[9]['action'], $e[9]['reason']], 'the mark of the extractor is kept');
        $this->assertTrue(!isset($plan['links']['/catalog/tproduct/929785863942-tovar']), 'links to a skipped product stay links to the old site');
        $this->assertSame('skip', $e[2]['action'], 'a copy of the home page under another address');
        $this->assertContains('копия страницы /', $e[2]['reason']);
        $this->assertSame([['from' => '/glavnaya', 'to' => '/'], ['from' => '/page100.html', 'to' => '/']], $e[2]['redirects']);
        $this->assertSame('/', $plan['links']['/glavnaya']);
    }

    public function testUrlHelpersKeepCyrillicLetters(): void
    {
        $this->assertSame('/single-post/2016-1-29-что-важно-при-выборе', Url::oldPath('https://psy.wixsite.com/single-post/2016-1-29-что-важно-при-выборе'), 'р-я (D1 80..8F) survive parse_url');
        $this->assertSame('/раздел/другая', Url::oldPath(Url::resolve('https://сайт.рф/раздел/страница', 'другая')));
        $this->assertTrue(Url::sameSite('https://сайт.рф/a', 'https://xn--80aswg.xn--p1ai/b'), 'an international host and its punycode are the same site');
        $this->assertTrue(Url::isImage('https://x.ru/фото/зал.jpg'));
        $this->assertSame('/strana/page', Url::oldPath('https://x.ru/strana/page#рус'));
    }

    public function testMapperKeepsPicturesInTextJoinsSectionsAndFindsAnchors(): void
    {
        $long = implode('', array_map(static fn(int $i): string => '<p>Абзац ответа номер ' . $i . ' с подробным объяснением, что делать и как.</p>', range(1, 60)));
        $page = Neutral::page(['title' => 'Услуги', 'old_path' => '/uslugi', 'elements' => [
            Neutral::el('heading', ['level' => 1, 'text' => 'Услуги'], 'rec1 (тип 1)'),
            Neutral::el('heading', ['level' => 2, 'text' => 'Что мы делаем'], 'rec2 (тип 2)') + ['anchor' => ['service']],
            Neutral::el('paragraph', ['html' => 'Короткий текст раздела.'], 'rec2 (тип 2)'),
            Neutral::el('image', ['src' => 'https://old.test/wp-content/uploads/badge-150x150.png', 'alt' => 'Значок', 'caption' => ''], 'rec3 (тип 3)'),
            Neutral::el('heading', ['level' => 2, 'text' => 'Как мы работаем'], 'rec4 (тип 4)'),
            Neutral::el('paragraph', ['html' => 'Ещё немного текста.'], 'rec4 (тип 4)'),
            Neutral::el('gallery', ['images' => array_map(static fn(int $i): array => ['src' => 'https://old.test/g' . $i . '.jpg', 'alt' => '', 'caption' => ''], range(1, 30))], 'rec5 (тип 5)'),
            Neutral::el('faq', ['items' => [['q' => 'Долгий вопрос?', 'a' => $long], ['q' => 'Короткий?', 'a' => 'Да.']]], 'rec6 (тип 6)'),
        ]]);
        $mapped = (new Mapper(self::themeWithArticle()))->map($page);
        $types = array_column($mapped['blocks'], 'type');
        $this->assertSame(['hero', 'article', 'faq', 'article'], $types, 'small sections, a badge and a gallery are one article; the rest of a long answer goes under the questions');
        $body = $mapped['blocks'][1]['data']['body'];
        $this->assertContains('<h2>Как мы работаем</h2>', $body);
        $this->assertContains('badge-150x150.png" alt="Значок" width="150" height="150">', $body, 'a small picture keeps its size');
        $this->assertSame(30, substr_count($body, '<figure>') - 1, 'the gallery is figures in the text');
        $answer = $mapped['blocks'][2]['data']['items'][0]['a'];
        $this->assertTrue(mb_strlen($answer) <= 1500 && str_starts_with($answer, 'Абзац ответа номер 1 '), 'the beginning of the answer stays');
        $this->assertContains('Абзац ответа номер 60', $mapped['blocks'][3]['data']['body']);
        $this->assertContains('Долгий вопрос?', json_encode($mapped['losses'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('article_1', $mapped['anchors']['service']);
        $this->assertSame('faq_1', $mapped['anchors']['rec6']);
        $wanting = new Mapper(self::themeWithArticle());
        $wanting->wantAnchors(['rec4']);
        $split = $wanting->map($page);
        $this->assertSame(['hero', 'article', 'article', 'faq', 'article'], array_column($split['blocks'], 'type'), 'a part a menu leads to starts its own block');
        $this->assertSame(['article_2', 'Как мы работаем'], [$split['anchors']['rec4'], $split['blocks'][2]['data']['heading']]);

        $warnings = [];
        $menus = Importer::menuAnchors(
            ['header' => [['title' => 'Услуги', 'url' => '/uslugi#service'], ['title' => 'Вопросы', 'url' => '/uslugi#rec6'], ['title' => 'Услуги', 'url' => '/#nothing'], ['title' => 'Прочее', 'url' => '/uslugi#gone']], 'footer' => []],
            ['/uslugi' => $mapped['anchors'], '/' => []],
            [['id' => 1, 'title' => 'Услуги']],
            [1 => ['action' => 'create', 'slug' => 'uslugi']],
            $warnings
        );
        $this->assertSame(['/uslugi#article_1', '/uslugi#faq_1', '/uslugi', '/uslugi'], array_column($menus['header'], 'url'), 'an anchor leads to its block, a lost one to the page of that name or the page itself');
        $this->assertContains('«Прочее»', implode(' ', $warnings));
        $this->assertTrue(Runner::isAvif("\0\0\0\x1cftypavif\0\0\0\0") && !Runner::isAvif("\xff\xd8\xff\xe0"));
    }

    public function testParserPriceRowsCiteAndPagesOfWordPress(): void
    {
        $html = '<html><head><title>Цены - Клиника</title><meta property="og:type" content="article"></head>'
            . '<body class="page-template-default page page-id-5"><main><h1>Цены</h1>'
            . '<div class="price-table"><div class="price-table__head"><div class="price-table__col"><p>Услуга</p></div><div class="price-table__col"><p>Сумма</p></div></div>'
            . '<div class="price-table__body"><div class="price-table__row"><div class="price-table__col"><p>Пломба</p><p>Светоотверждаемая, один зуб</p></div><div class="price-table__col"><p>от 3 500</p></div></div></div></div>'
            . '<div class="w-grid-list">'
            . implode('', array_map(static fn(int $i): string => '<article class="w-grid-item post-' . $i . '"><div class="w-grid-item-h"><div class="hw"><p class="title">Консультация ' . $i . '</p><div class="hw2"><span>1' . $i . '00</span> <span>₽/час</span></div></div></div></article>', [1, 2, 3, 4]))
            . '</div><blockquote><p>Очень довольны лечением.</p><cite>Анна, ул. Ленина</cite></blockquote>'
            . '<div class="footer_phone">+7 000 000-00-00</div><p>© 2004 - 2026 Клиника</p></main>'
            . '<div class="site-footer"><p>Адрес клиники и реквизиты</p></div></body></html>';
        $page = \AgeCMS\Modules\Importer\Extract\HtmlPage::parse($html, 'https://wp.test/tseny/', \AgeCMS\Modules\Importer\Extract\Profile::for('wordpress'));
        $this->assertSame('page', $page['kind'], 'the body class of WordPress says page, whatever og:type says');
        $tables = array_values(array_filter($page['elements'], static fn(array $e): bool => $e['type'] === 'table'));
        $this->assertSame(2, count($tables));
        $this->assertContains('<th>Услуга</th>', $tables[0]['html']);
        $this->assertContains('Пломба<br>Светоотверждаемая, один зуб', $tables[0]['html']);
        $this->assertContains('<td>Консультация 3</td><td>1300 ₽/час</td>', $tables[1]['html'], 'a grid of price boxes is a table');
        $quote = array_values(array_filter($page['elements'], static fn(array $e): bool => $e['type'] === 'quote'))[0];
        $this->assertSame(['Анна, ул. Ленина', false], [$quote['cite'], str_contains($quote['html'], 'Ленина')], 'the signature once');
        $all = json_encode($page['elements'], JSON_UNESCAPED_UNICODE);
        $this->assertNotContains('реквизиты', $all, 'a footer drawn with divs is the frame');
        $this->assertNotContains('2004 - 2026', $all);
        $post = \AgeCMS\Modules\Importer\Extract\HtmlPage::parse(str_replace('page-template-default page page-id-5', 'post-template-default single single-post postid-9', $html), 'https://wp.test/tseny/', \AgeCMS\Modules\Importer\Extract\Profile::for('wordpress'));
        $this->assertSame('post', $post['kind']);
    }

    public function testGenericGivesFeedsPostsAThirdAndSkipsTheTildaStore(): void
    {
        $b = 'https://tilda.example.ru';
        $tpl = self::fx('tilda/page.html');
        $map = [
            "$b/" => str_replace('Йога для каждого', 'Главная студии', $tpl),
            "$b/robots.txt" => [200, "User-agent: *\nSitemap: $b/sitemap.xml\nSitemap: $b/sitemap-store.xml\nSitemap: $b/sitemap-feeds.xml\n", ['content-type' => 'text/plain']],
            "$b/sitemap.xml" => [200, '<urlset>' . implode('', array_map(static fn(int $i): string => "<url><loc>$b/page-$i</loc></url>", range(1, 20))) . "<url><loc>$b/shop/tproduct/1-x</loc></url></urlset>", ['content-type' => 'application/xml']],
            "$b/sitemap-store.xml" => [200, '<urlset><url><loc>' . $b . '/shop/tproduct/2-y</loc></url></urlset>', ['content-type' => 'application/xml']],
            "$b/sitemap-feeds.xml" => [200, "<sitemapindex><sitemap><loc>$b/sitemap-feed-1.xml</loc></sitemap></sitemapindex>", ['content-type' => 'application/xml']],
            "$b/sitemap-feed-1.xml" => [200, "<urlset><url><loc>$b/blog</loc></url>" . implode('', array_map(static fn(int $i): string => "<url><loc>$b/blog/tpost/a{$i}b2c3d4e5-post-$i</loc></url>", range(1, 8))) . '</urlset>', ['content-type' => 'application/xml']],
        ];
        foreach (range(1, 20) as $i) {
            $map["$b/page-$i"] = str_replace('Йога для каждого', 'Страница номер ' . $i, $tpl);
        }
        foreach (range(1, 8) as $i) {
            $map["$b/blog/tpost/a{$i}b2c3d4e5-post-$i"] = str_replace('Как подготовиться к приёму', 'Запись номер ' . $i, self::fx('tilda/tpost.html'));
        }
        $fetcher = new ArrayFetcher($map);
        $g = new Generic($fetcher, ['url' => "$b/", 'limit' => 9, 'platform' => 'tilda']);
        $state = [];
        $pages = [];
        while (!$g->step($state, microtime(true) + 60, static function (array $p) use (&$pages): void {
            $pages[] = $p;
        })) {
        }
        $kinds = array_count_values(array_column($pages, 'kind'));
        $this->assertSame(9, count($pages));
        $this->assertSame(3, $kinds['post'] ?? 0, 'a third of the limit goes to the posts of Feeds');
        $this->assertTrue(!in_array("$b/sitemap-store.xml", $fetcher->requested, true), 'the store sitemap is not read');
        $this->assertNotContains('tproduct', implode(' ', array_column($pages, 'old_path')));
        $this->assertContains('Магазин Tilda', implode(' ', $state['warnings']));
        $this->assertSame([0, 1, 3, 9, 9], array_map(static fn(string $u): int => Generic::mapRank($u, 'wordpress'), ['https://w.test/page-sitemap.xml', 'https://w.test/wp-sitemap-posts-post-1.xml', 'https://w.test/testimonial-sitemap.xml', 'https://w.test/testimonial-category-sitemap.xml', 'https://w.test/wp-sitemap-taxonomies-category-1.xml']), 'WordPress: pages, posts, other types, never archives of categories');
    }

    public function testWordPressLeavesAThirdOfTheLimitToPostsAndSkipsShopPages(): void
    {
        $site = self::wordpressSite();
        $pages = json_decode(self::fx('wordpress/pages.json'), true);
        $pages[] = ['id' => 90, 'slug' => 'cart', 'status' => 'publish', 'link' => 'https://wp.example.com/cart/', 'title' => ['rendered' => 'Корзина'], 'content' => ['rendered' => '<div class="wp-block-woocommerce-cart"></div>'], 'excerpt' => ['rendered' => ''], 'parent' => 0];
        $site['https://wp.example.com/wp-json/wp/v2/pages?*'] = json_encode($pages, JSON_UNESCAPED_UNICODE);
        $site['https://wp.example.com/wp-json/wp/v2/posts?*'] = [200, self::fx('wordpress/posts.json'), ['content-type' => 'application/json', 'x-wp-total' => '1']];
        [$a, $plan, $p] = $this->dry('https://wp.example.com/', $site, ['limit' => 3]);
        $this->assertSame(['/o-kompanii/', '/o-kompanii/komanda/', '/2024/03/kak-vybrat-plitku/'], array_keys($p), 'two pages and the post: the post is not pushed out by the pages');
        $this->assertContains('отдана записям блога', implode(' ', $a['warnings']));
        [, , $all] = $this->dry('https://wp.example.com/', $site);
        $this->assertSame(['skip', 'служебная страница магазина (корзина, оформление заказа, личный кабинет): магазин не переносится'], [$all['/cart/']['plan']['action'], $all['/cart/']['plan']['reason']]);
    }

    // ---- the job on a real site --------------------------------------------------------------------------------

    public function testModuleLoadsWithSkillsAndAdminPagesAndNoTablesUntilUsed(): void
    {
        $this->freshSite();
        SkillRegistry::boot();
        $info = SkillRegistry::modules()['importer'];
        $this->assertSame('ok', $info['status'], (string)$info['reason']);
        $revisions = (int)Database::value('SELECT COUNT(*) FROM revisions');
        $this->assertTrue(!Jobs::exists(), 'no tables and no history entries until the first import');
        $this->assertSame(null, Jobs::active(), 'the background tick finds nothing without tables');
        Jobs::ensure();
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM mod_importer_jobs'));
        $this->assertSame($revisions, (int)Database::value('SELECT COUNT(*) FROM revisions'), 'creating the tables writes nothing to the history');
        $names = array_map(static fn(array $s): string => $s['module'] . '/' . $s['name'], array_filter(SkillRegistry::skills(), static fn(array $s): bool => $s['module'] === 'importer'));
        sort($names);
        $this->assertSame(['importer/import_apply', 'importer/import_detect', 'importer/import_preview', 'importer/import_rollback', 'importer/import_status'], $names);
        $this->assertThrows(\AgeCMS\ForbiddenException::class, fn() => SkillRegistry::call('importer', 'import_status', ['job_id' => 1], Actor::agent('e', 'editor')));
    }

    public function testJobAppliesInPortionsWithImagesRedirectsMenusAndRollsBack(): void
    {
        $this->freshSite();
        SkillRegistry::boot();
        Network::override(new ArrayFetcher(self::staticSite()));
        try {
            $actor = Actor::human('admin', 'admin');
            Content::createPage($actor, 'index', ['title' => 'Главная', 'blocks' => []]);
            Content::setMenu($actor, 'header', [['title' => 'Было', 'url' => '/']]);
            $id = Runner::create('https://bread.example.net/', ['delay_ms' => 0, 'chunk' => 1, 'replace_home' => false], $actor);
            $runner = new Runner();
            $job = $runner->runToEnd($id);
            $this->assertSame('ready', $job['status'], (string)$job['error']);
            $preview = Report::job($id);
            $this->assertSame(3, $preview['counts']['pages']);
            $this->assertSame('skip', $preview['pages'][0]['action'], 'the home page exists');

            $job = $runner->step($id, microtime(true) + 5);
            $this->assertSame('ready', $job['status'], 'nothing happens before the owner says so');
            Runner::startApply($id, null, $actor);
            $job = $runner->step($id, microtime(true) + 5);
            $this->assertSame('applying', $job['status'], 'portions: one page per step part, more steps to go');
            $job = $runner->runToEnd($id);
            $this->assertSame('done', $job['status'], (string)$job['error']);

            $about = Pages::doc('about');
            $this->assertTrue($about !== null);
            $this->assertSame('О нас', $about['title']);
            $body = $about['blocks'][1]['data']['body'];
            $this->assertTrue(preg_match('~<img src="/assets/uploads/[a-f0-9]{20}\.webp"~', $body) === 1, 'the picture is in the media library: ' . $body);
            $this->assertTrue(Pages::doc('dostavka') !== null);
            $this->assertSame('Главная', Pages::doc('index')['title'], 'the existing home page is untouched');
            $history = Database::value('SELECT actor_name FROM revisions WHERE page_slug = ? ORDER BY id LIMIT 1', ['about']);
            $this->assertSame('admin', $history, 'written in the history under the name of the person');
            $menu = json_decode((string)Database::value("SELECT items_json FROM menus WHERE name = 'header'"), true);
            $this->assertSame(['Главная', 'О нас', 'Доставка', 'Telegram'], array_column($menu, 'title'));
            if (Compat::redirects()) {
                $this->assertSame('about', Database::value('SELECT to_slug FROM redirects WHERE from_slug = ?', ['/about.html']));
            }
            $this->assertSame(1, (int)Database::value('SELECT COUNT(*) FROM mod_importer_media'));

            // An edited page survives the rollback.
            Content::updatePage($actor, 'dostavka', ['title' => 'Доставка (правка)']);
            Runner::startRollback($id, $actor);
            $job = $runner->runToEnd($id);
            $this->assertSame('rolled_back', $job['status'], (string)$job['error']);
            $this->assertTrue(Pages::row('about') === null, 'the created page went to the trash');
            $this->assertTrue(in_array('about', array_column(Pages::deleted(), 'slug'), true));
            $this->assertTrue(Pages::row('dostavka') !== null, 'edited after the import: kept');
            $this->assertSame('Было', json_decode((string)Database::value("SELECT items_json FROM menus WHERE name = 'header'"), true)[0]['title'], 'the menu came back');
            if (Compat::redirects()) {
                $this->assertSame(null, Database::value('SELECT to_slug FROM redirects WHERE from_slug = ?', ['/about.html']));
            }
            $this->assertSame(null, \AgeCMS\CircuitBreaker::frozenInfo(), 'the circuit breaker did not trip');

            // The address of a rolled back page can be used again by a new import.
            $again = Runner::create('https://bread.example.net/', ['delay_ms' => 0], $actor);
            $runner->runToEnd($again);
            $row = array_values(array_filter(Report::job($again)['pages'], static fn(array $r): bool => $r['old_path'] === '/about.html'))[0];
            $this->assertSame('about', $row['slug']);
        } finally {
            Network::override(null);
        }
    }

    public function testSkillsPreviewApplyOnlyChosenPagesAndBackgroundTick(): void
    {
        $this->freshSite();
        SkillRegistry::boot();
        Network::override(new ArrayFetcher([]));
        try {
            $admin = Actor::cli('agent-like');
            $r = SkillRegistry::call('importer', 'import_preview', ['file_base64' => base64_encode(self::fx('export.xml')), 'type' => 'wxr'], $admin);
            $this->assertSame('ready', $r['status'], (string)($r['error'] ?? ''));
            $this->assertSame(3, $r['counts']['pages']);
            $this->assertContains('import_apply', $r['next']);
            $id = $r['job_id'];
            $r = SkillRegistry::call('importer', 'import_apply', ['job_id' => $id, 'pages' => ['/o-nas/']], $admin);
            while ($r['status'] === 'applying') {
                $r = SkillRegistry::call('importer', 'import_status', ['job_id' => $id], $admin);
            }
            $this->assertSame('done', $r['status'], (string)($r['error'] ?? ''));
            $this->assertTrue(Pages::row('o-nas') !== null);
            $this->assertTrue(Pages::row('o-nas/vakansii') === null, 'not chosen');
            $this->assertTrue(Pages::row('blog') === null, 'no section without its posts');
            $this->assertContains('cup.jpg', json_encode(Pages::doc('o-nas')), 'a picture that could not be downloaded stays at its https address');

            $agent = Actor::agent('bot', 'admin', 'tok-1');
            $this->assertThrows(\AgeCMS\ForbiddenException::class, fn() => SkillRegistry::call('importer', 'import_preview', ['url' => 'https://bread.example.net/'], Actor::agent('bot', 'editor')));
            $d = SkillRegistry::call('importer', 'import_detect', ['url' => 'https://bread.example.net/'], $agent);
            $this->assertSame('generic', $d['platform'], 'nothing answers: still a result, not a crash');
        } catch (\AgeCMS\AgeCMSException $e) {
            if (!str_contains($e->getMessage(), 'не открылся')) {
                throw $e;
            }
            $this->assertTrue(true);
        } finally {
            Network::override(null);
        }

        // Background: an analysing job goes on by itself on a tick.
        Network::override(new ArrayFetcher(self::staticSite()));
        try {
            $id = Runner::create('https://bread.example.net/', ['delay_ms' => 0], Actor::cli());
            \AgeCMS\EventBus::trigger(\AgeCMS\Background::TICK_EVENT, ['time' => time(), 'deadline' => microtime(true) + 10]);
            $this->assertSame('ready', Jobs::get($id)['status']);
        } finally {
            Network::override(null);
        }
    }

    public function testAdminPageFlowOverHttp(): void
    {
        $this->boot();
        @mkdir(AGECMS_DATA . '/import', 0700, true);
        $wxr = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>Тест</title><wp:base_site_url>https://site.invalid</wp:base_site_url>'
            . '<item><title>Цены</title><link>https://site.invalid/ceny/</link><content:encoded><![CDATA[<!-- wp:paragraph --><p>Прайс.</p><!-- /wp:paragraph -->]]></content:encoded><wp:post_id>3</wp:post_id><wp:status>publish</wp:status><wp:post_type>page</wp:post_type></item></channel></rss>';
        file_put_contents(AGECMS_DATA . '/import/site.xml', $wxr);
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $page = $this->http('GET', '/admin/m/importer/', null, [], true);
        $this->assertSame(200, $page['status']);
        $this->assertContains('Перенести сайт', $page['body']);
        $this->assertContains('site.xml', $page['body'], 'the file in data/import is offered');
        preg_match('/name="csrf" value="([^"]+)"/', $page['body'], $m);
        $csrf = $m[1] ?? '';
        $form = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $start = $this->http('POST', '/admin/m/importer/', http_build_query(['csrf' => $csrf, 'file' => 'site.xml', 'limit' => '10', 'conflict' => 'suffix', 'menus' => '1']), $form, true);
        $this->assertSame(303, $start['status']);
        $job = $start['headers']['location'] ?? '';
        $this->assertTrue(preg_match('~^/admin/m/importer/job\?id=(\d+)$~', $job, $jm) === 1, $job);
        $missing = $this->http('GET', '/admin/m/importer/job?id=999', null, [], true);
        $this->assertSame(200, $missing['status'], 'a job that is not there gets a page of the module');
        $this->assertContains('Задания импорта № 999 нет', $missing['body']);
        $this->assertContains('Задание импорта не выбрано', $this->http('GET', '/admin/m/importer/job', null, [], true)['body']);
        $view = $this->http('GET', $job, null, [], true);
        preg_match('~url=([^"]+)"~', $view['body'], $refresh);
        $this->assertContains('http-equiv="refresh"', $view['body'], 'the analysis page refreshes itself');
        $ready = $this->http('GET', html_entity_decode($refresh[1] ?? $job), null, [], true);
        $this->assertContains('Что будет перенесено', $ready['body']);
        $this->assertContains('/ceny', $ready['body']);
        preg_match('~name="pages\[\]" value="(\d+)"~', $ready['body'], $pm);
        $apply = $this->http('POST', $job, http_build_query(['csrf' => $csrf, 'action' => 'apply', 'pages' => [$pm[1] ?? '0']]), $form, true);
        $this->assertSame(303, $apply['status']);
        $done = $this->http('GET', (string)($apply['headers']['location'] ?? $job), null, [], true);
        for ($i = 0; $i < 5 && str_contains($done['body'], 'http-equiv="refresh"'); $i++) {
            preg_match('~url=([^"]+)"~', $done['body'], $refresh);
            $done = $this->http('GET', html_entity_decode($refresh[1]), null, [], true);
        }
        $this->assertContains('Создано страниц: <b>1</b>', $done['body']);
        $this->assertContains('Отменить импорт', $done['body']);
        $site = $this->http('GET', '/ceny');
        $this->assertSame(200, $site['status']);
        $this->assertContains('Прайс.', $site['body']);
        $this->stop();
    }
}
