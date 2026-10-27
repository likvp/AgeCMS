<?php
declare(strict_types=1);

use AgeCMS\{AntiSpam, Config, Database, Pages, Queue, Security, Seo};

final class SeoTest extends TestCase
{
    public function testCanonicalAndSitemapUseConfiguredSiteUrlNotTheHostHeader(): void
    {
        $this->freshSite();
        $this->createPage('about');
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $head = Seo::head('about', Pages::doc('about'), Seo::settings());
        unset($_SERVER['HTTP_HOST']);
        $this->assertContains('<link rel="canonical" href="https://example.test/about">', $head);
        $this->assertNotContains('evil.example', $head);
        $this->assertNotContains('evil.example', Seo::sitemap());
        $this->assertContains('<loc>https://example.test/about</loc>', Seo::sitemap());
    }

    public function testSitemapListsOnlyPublicIndexablePages(): void
    {
        $this->freshSite();
        $this->createPage('index');
        $this->createPage('public');
        $this->createPage('hidden', ['is_published' => 0]);
        $this->createPage('private', ['is_noindex' => 1]);
        $xml = Seo::sitemap();
        $this->assertContains('<loc>https://example.test/</loc>', $xml);
        $this->assertContains('/public</loc>', $xml);
        $this->assertNotContains('hidden', $xml);
        $this->assertNotContains('private', $xml);
        $this->assertNotContains('<loc>https://example.test/index', $xml);
        $this->assertNotContains('hidden', Seo::llms());
    }

    public function testJsonLdHasFaqAndBreadcrumbsAndIsSafeInsideScript(): void
    {
        $this->freshSite();
        $this->createPage('services', ['title' => 'Services']);
        $this->createPage('services/repair', ['title' => 'Repair </script><b>', 'blocks' => [
            ['id' => 'faq_1', 'type' => 'faq', 'data' => ['items' => [['q' => 'How long?', 'a' => 'One day']]]],
        ]]);
        $doc = Pages::doc('services/repair');
        $ld = Seo::jsonLd('services/repair', $doc, Seo::settings());
        $types = array_column($ld['@graph'], '@type');
        $this->assertTrue(in_array('FAQPage', $types, true));
        $this->assertTrue(in_array('BreadcrumbList', $types, true));
        $crumbs = array_values(array_filter($ld['@graph'], fn($n) => $n['@type'] === 'BreadcrumbList'))[0]['itemListElement'];
        $this->assertSame(['Главная', 'Services', 'Repair </script><b>'], array_column($crumbs, 'name'));

        $head = Seo::head('services/repair', $doc, Seo::settings());
        $this->assertSame(1, substr_count($head, '</script>'), 'a hostile title cannot close the JSON-LD script early');
    }

    public function testIndexNowIsOffForLocalSitesAndBatchesUrls(): void
    {
        $this->freshSite();
        $this->assertSame(false, Seo::indexNowEnabled(), '.test is a reserved domain, no ping');
        Config::set('site_url', 'https://example.com');
        $this->assertSame(true, Seo::indexNowEnabled());
        Config::set('site_url', 'http://localhost:8080');
        $this->assertSame(false, Seo::indexNowEnabled(), 'http and localhost never ping');
        Config::set('site_url', 'https://example.com');
        Seo::queuePing('a');
        Seo::queuePing('b');
        $this->assertSame(2, count(glob(Queue::dir() . '/ping/*.json')));
    }

    public function testAntiSpamTokenIsSignedAndTimed(): void
    {
        $this->freshSite();
        $fresh = AntiSpam::issueToken();
        $this->assertSame(false, AntiSpam::checkToken($fresh), 'filled in under 2 seconds');
        $old = (string)(time() - 5);
        $this->assertSame(true, AntiSpam::checkToken($old . '.' . Security::sign('form', $old)));
        $this->assertSame(false, AntiSpam::checkToken($old . '.' . str_repeat('0', 64)), 'forged signature');
        $forgedTime = (string)(time() - 60);
        $this->assertSame(false, AntiSpam::checkToken($forgedTime . '.' . Security::sign('form', $old)), 'timestamp swapped');
        $stale = (string)(time() - 8000);
        $this->assertSame(false, AntiSpam::checkToken($stale . '.' . Security::sign('form', $stale)), 'expired');
        $this->assertSame(false, AntiSpam::checkToken('garbage'));
        $this->assertSame(false, AntiSpam::validate(['token' => $old . '.' . Security::sign('form', $old), 'website_hp' => 'http://spam']), 'honeypot filled');
        $this->assertSame(true, AntiSpam::validate(['token' => $old . '.' . Security::sign('form', $old), 'website_hp' => '']));
    }

    public function testRateLimitWindow(): void
    {
        $this->freshSite();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(true, AntiSpam::rateLimit('t', 3, 60));
        }
        $this->assertSame(false, AntiSpam::rateLimit('t', 3, 60));
        $this->assertSame(true, AntiSpam::rateLimit('other', 3, 60), 'buckets are independent');
        Database::pdo()->exec('UPDATE rate_limits SET ts = ts - 120');
        $this->assertSame(true, AntiSpam::rateLimit('t', 3, 60), 'old entries expire');
    }
}
