<?php
declare(strict_types=1);

use AgeCMS\Modules\Importer\Importer;
use AgeCMS\Modules\Importer\Extract\Gutenberg;
use AgeCMS\Modules\Importer\Net\ArrayFetcher;

require_once dirname(__DIR__) . '/src/autoload.php';

/**
 * WordPress sources as real sites have them: robots.txt that closes the REST API, page templates that keep the text in
 * theme fields, SEO plugins that hide their fields from the API, server-rendered lists, captions inside paragraphs,
 * social posts, audio, password-protected posts; an export with [gallery] without ids, a page list, footnotes and
 * the old caption attribute. No network: pages come from ArrayFetcher.
 */
final class WordPressRealTest extends TestCase
{
    private const FX = __DIR__ . '/fixtures/wordpress';
    private const B = 'https://smile.example.ru';

    /** @return array<string,mixed> */
    private static function site(string $robots = "User-agent: *\nDisallow: /wp-admin/\n"): array
    {
        $b = self::B;
        return [
            "$b/" => (string)file_get_contents(self::FX . '/real-home.html'),
            "$b/robots.txt" => [200, $robots, ['content-type' => 'text/plain']],
            "$b/wp-json/wp/v2/pages?*" => (string)file_get_contents(self::FX . '/real-pages.json'),
            "$b/wp-json/wp/v2/posts?*" => (string)file_get_contents(self::FX . '/real-posts.json'),
            "$b/tseny/" => (string)file_get_contents(self::FX . '/real-tseny.html'),
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>,2:ArrayFetcher} analysis, pages by old path, fetcher */
    private static function analyze(string $input, array $map): array
    {
        $fetcher = new ArrayFetcher($map);
        $analysis = (new Importer(['delay_ms' => 0, 'limit' => 50], $fetcher))->analyze($input);
        $byPath = [];
        foreach ($analysis['pages'] as $p) {
            $byPath[$p['old_path']] = $p;
        }
        return [$analysis, $byPath, $fetcher];
    }

    /** @param list<array<string,mixed>> $els @return list<string> */
    private static function types(array $els): array
    {
        return array_map(static fn(array $e): string => (string)$e['type'], $els);
    }

    public function testRestRenderedContentOfARealSite(): void
    {
        [, $p] = self::analyze(self::B . '/', self::site());
        $home = $p['/']['elements'];
        $this->assertSame(['hero', 'button', 'image', 'paragraph', 'embed', 'embed', 'heading', 'paragraph'], self::types($home));
        $this->assertSame('Записаться', $home[0]['cta_text'], 'the first button of the cover is its call to action');
        $this->assertSame(['type' => 'button', 'text' => 'Цены', 'url' => self::B . '/tseny/'], array_diff_key($home[1], ['source' => 1]), 'the second button is kept');
        $this->assertSame(self::B . '/wp-content/uploads/2024/01/doctor.jpg', $home[2]['src'], 'the caption figure inside <p> is a picture, the full size from the link');
        $this->assertSame('Главный врач Ольга Петрова', $home[2]['caption']);
        $this->assertSame('https://rutube.ru/play/embed/abc123/', $home[4]['src'], 'an iframe wrapped in <p> by wpautop');
        $this->assertSame('https://x.com/smile/status/123456789', $home[5]['src'], 'the address of the tweet, not its text');
        $this->assertSame('<a href="' . self::B . '/wp-content/uploads/2024/01/intro.mp3">Голосовое приветствие</a>', $home[7]['html'], 'audio stays a link to the file');
        $notes = json_encode($p['/']['notes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertContains('core/latest-posts', $notes, 'the server-rendered list is dropped with a note');
        $this->assertContains('core/search', $notes);
        $this->assertNotContains('form', json_encode(self::types($home)), 'the search form is not a lead form');
        $this->assertSame('Стоматология Улыбка - лечение зубов в Кирове', $p['/']['meta_title'], 'Rank Math hides its fields from REST: taken from the page head');
    }

    public function testTemplatePageProtectedPageAndPost(): void
    {
        [, $p, $fetcher] = self::analyze(self::B . '/', self::site());
        $prices = $p['/tseny/'];
        $this->assertSame(['heading', 'heading', 'table', 'heading', 'table', 'paragraph'], self::types($prices['elements']), 'the empty REST content of a page template is taken from the page');
        $this->assertContains('page-prices.php', json_encode($prices['notes'], JSON_UNESCAPED_UNICODE));
        $this->assertNotContains('Ленина', json_encode($prices['elements'], JSON_UNESCAPED_UNICODE), 'the contacts the home page shows too are left out at the end of the page');
        // The line with "все права защищены" goes as the copyright of the frame already; the shared parts are not in the text.
        $this->assertNotContains('все права защищены', json_encode($prices['elements'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('Цены на лечение и протезирование зубов в стоматологии Улыбка.', $prices['meta_description']);
        $this->assertSame(1, count(array_filter($fetcher->requested, static fn(string $u): bool => $u === self::B . '/tseny/')), 'the page is fetched once for the text and the SEO fields');

        $closed = $p['/dlya-vrachey/'];
        $this->assertSame('Для врачей', $closed['title'], 'the "Protected:" prefix of the rendered title is removed');
        $this->assertSame([], $closed['elements']);
        $this->assertContains('паролем', json_encode($closed['notes'], JSON_UNESCAPED_UNICODE));

        $post = $p['/blog/novyj-kabinet/'];
        $this->assertSame('Новый кабинет «Детский»', $post['title']);
        $this->assertSame('2024-03-05', $post['published_at']);
        $this->assertSame(['heading', 'hero', 'paragraph', 'paragraph'], self::types($post['elements']), 'the title opens a post that starts with a cover; the featured image is in the cover already');
        $this->assertSame(1, $post['elements'][0]['level']);
        $this->assertContains('song.mp3">song.mp3</a>', $post['elements'][3]['html'], 'the ?_=1 counter of the audio shortcode is removed');
    }

    public function testRobotsClosingTheRestApiMakesACrawl(): void
    {
        $map = self::site("User-agent: *\nDisallow: /wp-\nAllow: /wp-content/uploads/\n");
        [$a, $p, $fetcher] = self::analyze(self::B . '/', $map);
        $this->assertContains('robots.txt запрещает', implode(' ', $a['warnings']));
        $this->assertSame([], array_values(array_filter($fetcher->requested, static fn(string $u): bool => str_contains($u, 'per_page=50'))), 'no API listing is requested');
        $this->assertTrue(isset($p['/tseny/']), 'the menu links are crawled');

        [$a] = self::analyze(self::B . '/', self::site("User-agent: *\nDisallow: *?\n"));
        $this->assertContains('robots.txt запрещает', implode(' ', $a['warnings']), 'addresses with a query are closed too');

        $fetcher = new ArrayFetcher(self::site("User-agent: *\nDisallow: /wp-\n"));
        $a = (new Importer(['delay_ms' => 0, 'limit' => 50, 'robots' => false], $fetcher))->analyze(self::B . '/');
        $this->assertSame('', implode(' ', $a['warnings']), 'with robots off the API is read');
        $this->assertSame(4, count($a['pages']));
    }

    public function testWxrOfARealSite(): void
    {
        [$a, $p] = self::analyze(self::FX . '/real-export.xml', []);
        $this->assertSame(['/uslugi/', '/uslugi/kuhnya/', '/uslugi/vannaya/', '/2024/03/nashi-raboty/', '/2024/03/zakrytaya/'], array_keys($p));
        $services = $p['/uslugi/']['elements'];
        $this->assertSame(['paragraph', 'list', 'list', 'paragraph', 'list'], self::types($services), 'the button without a link is left out, not kept as raw HTML');
        $this->assertSame(['<a href="https://remont.example.ru/uslugi/vannaya/">Ремонт ванной</a>', '<a href="https://remont.example.ru/uslugi/kuhnya/">Ремонт кухни</a>'], $services[1]['items'], 'the page list shows the child pages in menu order');
        $this->assertSame(['<a href="https://vk.com/remontlab">Vk</a>', '<a href="https://t.me/remontlab">Наш Telegram</a>'], $services[2]['items'], 'social links');
        $this->assertSame(['Выезд мастера бесплатный в пределах города.'], $services[4]['items'], 'footnotes from the post meta');

        $post = $p['/2024/03/nashi-raboty/']['elements'];
        $this->assertSame(['paragraph', 'image', 'gallery', 'embed', 'paragraph', 'paragraph'], self::types($post), 'the featured image is in the text already');
        $this->assertSame('Кухня после ремонта', $post[1]['caption'], 'the caption from the attribute of old exports');
        $this->assertSame(['kitchen.jpg', 'bath.jpg'], array_map(static fn(array $i): string => basename($i['src']), $post[2]['images']), '[gallery] without ids shows the attached pictures, exclude is kept');
        $this->assertSame('Кухня после ремонта', $post[2]['images'][0]['alt'], 'the alt text of the attachment');
        $this->assertSame(['src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'kind' => 'video'], array_intersect_key($post[3], ['src' => 1, 'kind' => 1]), 'a video address on its own line');
        $this->assertContains('rasskaz.mp3', $post[4]['html']);
        $this->assertContains('https://example.org/ в тексте', $post[5]['html'], 'an address inside the text stays text');

        $this->assertSame('Ремонт кухни под ключ.', $p['/uslugi/kuhnya/']['meta_description'], 'without an excerpt the description is the start of the text, as WordPress does');

        $closed = $p['/2024/03/zakrytaya/'];
        $this->assertSame([], $closed['elements'], 'the text of a password-protected post is not published');
        $this->assertSame('', $closed['meta_description'], 'nor its excerpt');
        $this->assertSame([], $a['warnings']);
    }

    public function testHelpers(): void
    {
        $els = Gutenberg::postTop([['type' => 'paragraph', 'html' => 'x', 'source' => '']], 'post', 'Заголовок', 'https://a.test/wp-content/uploads/p.jpg', '', 'f');
        $this->assertSame(['image', 'paragraph'], self::types($els), 'the featured image goes first');
        $this->assertSame('Заголовок', $els[0]['alt'], 'with the title as alt');
        $this->assertSame(1, count(Gutenberg::postTop([['type' => 'image', 'src' => 'https://a.test/wp-content/uploads/p-300x200.jpg', 'alt' => '', 'caption' => '', 'source' => '']], 'post', 'T', 'https://a.test/wp-content/uploads/p.jpg', '', 'f')), 'a smaller size of the same picture counts');
        $this->assertSame(1, count(Gutenberg::postTop([['type' => 'paragraph', 'html' => 'x', 'source' => '']], 'page', 'T', 'https://a.test/p.jpg', '', 'f')), 'pages keep their content as is');
        $this->assertSame("<iframe src=\"https://youtu.be/abc\"></iframe>\nтекст https://youtu.be/abc", Gutenberg::embeds("https://youtu.be/abc\nтекст https://youtu.be/abc"));
        $this->assertSame([], Gutenberg::renderedNotes('<p class="wp-block-query-pagination-like">x</p>'), 'a class is matched whole');
    }
}
