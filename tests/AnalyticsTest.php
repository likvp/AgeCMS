<?php
declare(strict_types=1);

use AgeCMS\{Analytics, Database, ForbiddenException, Pages, Queue, Seo, ValidationException};
use AgeCMS\Actor;

final class AnalyticsTest extends TestCase
{
    private function set(array $values): array
    {
        return Queue::submit($this->admin(), 'set_settings', ['values' => $values]);
    }

    public function testNothingIsAddedUntilTheAdministratorFillsIt(): void
    {
        $this->freshSite();
        $this->createPage('about');
        $head = Seo::head('about', Pages::doc('about'), Seo::settings());
        $this->assertNotContains('analytics.js', $head);
        $this->assertNotContains('yandex-verification', $head);
    }

    public function testCounterAndCodesGoIntoTheHeadWithoutInlineScript(): void
    {
        $this->freshSite();
        $this->createPage('about');
        $r = $this->set([
            'yandex_metrika_id' => '113843789',
            'yandex_metrika_options' => 'webvisor,clickmap,trackHash',
            'yandex_webmaster' => '31d36f48e3135250',
            'verification_tags' => "google-site-verification=abc_123-XYZ\nmsvalidate.01=ABCDEF0123",
        ]);
        $this->assertSame(true, $r['changed']);
        $head = Seo::head('about', Pages::doc('about'), Seo::settings());
        $this->assertContains('<script src="/assets/analytics.js" defer data-ym="113843789" data-ym-options="webvisor,clickmap,trackHash"></script>', $head);
        $this->assertContains('<meta name="yandex-verification" content="31d36f48e3135250">', $head);
        $this->assertContains('<meta name="google-site-verification" content="abc_123-XYZ">', $head);
        $this->assertContains('<meta name="msvalidate.01" content="ABCDEF0123">', $head);
        $this->assertSame(1, substr_count($head, '<script src="/assets/analytics.js"'));
        $this->assertNotContains('ym(113843789', $head, 'the starter is a file, not an inline script');
        $this->assertTrue(is_file(__DIR__ . '/../assets/analytics.js'));
        $this->assertContains('https://mc.yandex.ru/metrika/tag.js', (string)file_get_contents(__DIR__ . '/../assets/analytics.js'));

        // the same code in the tags list and in the Webmaster field is written once
        $this->set(['verification_tags' => "yandex-verification=31d36f48e3135250\nother=1"]);
        $head = Seo::head('about', Pages::doc('about'), Seo::settings());
        $this->assertSame(1, substr_count($head, 'name="yandex-verification"'));
    }

    public function testPagesNotForSearchEnginesGetNoCounter(): void
    {
        $this->freshSite();
        $this->createPage('about');
        $this->set(['yandex_metrika_id' => '42', 'yandex_webmaster' => 'abcdef1234']);
        $doc = Pages::doc('about');
        $doc['is_noindex'] = 1;
        $head = Seo::head('about', $doc, Seo::settings());
        $this->assertNotContains('analytics.js', $head, 'private and noindex pages are not counted');
        $this->assertContains('yandex-verification', $head);

        $shell = \AgeCMS\Renderer::shell('Кабинет', '<p>личные данные</p>');
        $this->assertNotContains('analytics.js', $shell, 'the cabinet-like shell page gets no counter');
    }

    public function testValuesAreValidatedAndOnlyAdministratorsChangeThem(): void
    {
        $this->freshSite();
        $this->createPage('about');
        $editor = Actor::agent('ed', 'editor');
        $this->assertThrows(ForbiddenException::class, fn() => Queue::submit($editor, 'set_settings', ['values' => ['yandex_metrika_id' => '1']]));
        foreach ([
            ['yandex_metrika_id' => '12ab'], ['yandex_metrika_id' => '0'], ['yandex_metrika_id' => '1234567890123'],
            ['yandex_metrika_id' => '"><script>'], ['yandex_metrika_options' => 'webvisor,eval'], ['yandex_webmaster' => 'short'],
            ['yandex_webmaster' => 'abc"def><x12345'], ['verification_tags' => 'no equals sign'],
            ['verification_tags' => 'robots=noindex'], ['verification_tags' => 'content-security-policy=default-src *'],
            ['verification_tags' => 'a="b"'], ['verification_tags' => str_repeat("a=b\n", 11)],
        ] as $bad) {
            $this->assertThrows(ValidationException::class, fn() => $this->set($bad));
        }
        $this->assertSame(null, Database::value("SELECT 1 FROM settings WHERE key = 'yandex_metrika_id'"), 'nothing was stored by the refused calls');

        $this->set(['yandex_metrika_id' => '77']);
        $this->set(['yandex_metrika_id' => '']);
        $head = Seo::head('about', Pages::doc('about'), Seo::settings());
        $this->assertNotContains('analytics.js', $head, 'an empty value switches the counter off');
        $this->assertSame('@settings', Database::value('SELECT page_slug FROM revisions ORDER BY id DESC LIMIT 1'), 'the change is in the history');
    }

    public function testPolicyLetsTheCounterWorkAndNothingElse(): void
    {
        $csp = (require __DIR__ . '/../core/security-headers.php')['Content-Security-Policy'];
        $this->assertContains("script-src 'self' https://mc.yandex.ru;", $csp);
        $this->assertContains("connect-src 'self' https://mc.yandex.ru https://mc.yandex.com wss://mc.yandex.ru;", $csp);
        $this->assertContains("worker-src 'self' blob:;", $csp);
        $this->assertNotContains("'unsafe-inline'", $csp);
        $this->assertNotContains("'unsafe-eval'", $csp);
        $this->assertNotContains('script-src *', $csp);
    }
}
