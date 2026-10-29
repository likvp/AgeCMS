<?php
declare(strict_types=1);

use AgeCMS\{AdminTabs, AgeCMSException, Api, Database, Queue, Renderer, SiteSettings, Theme, ValidationException};

/** Settings of the site in the table "settings": contacts, the cookie notice, fields of the theme; and the tabs of admin pages. */
final class SiteSettingsTest extends TestCase
{
    public function testContactsOfTheOrganisationAreCheckedStoredAndSeenByAgents(): void
    {
        $this->freshSite();
        $result = Queue::submit($this->admin(), 'set_settings', ['values' => [
            'org_phone' => '+7 (912) 000-11-22', 'org_phone2' => '8 800 100-00-00', 'org_email' => 'info@example.test',
            'org_inn' => '434500000000', 'org_legal_name' => 'ООО «Карат»', 'org_address' => 'г. Киров', 'org_logo' => '/assets/uploads/logo.webp',
        ]]);
        $this->assertTrue($result['changed']);
        $this->assertSame('434500000000', (string)Database::value("SELECT value FROM settings WHERE key = 'org_inn'"));

        foreach ([['org_inn', '123'], ['org_email', 'not-mail'], ['org_phone', 'звоните'], ['org_logo', 'javascript:alert(1)'], ['cookie_banner', 'yes'], ['cookie_policy_url', '//evil.test']] as [$key, $value]) {
            $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => [$key => $value]]), $key);
        }
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => ['org_whatever' => 'x']]), "Unknown setting 'org_whatever'");

        $structure = Api::call($this->admin(), 'get_site_structure', []);
        $this->assertSame('info@example.test', $structure['settings']['org_email']);
        $this->assertSame(['header', 'footer'], array_keys($structure['theme_settings']), 'the fields of the theme are listed for agents');
        $this->assertSame('bool', $structure['theme_settings']['header']['fields']['show_phone']['type']);

        $ld = \AgeCMS\Seo::jsonLd('index', ['title' => 'Главная', 'meta_description' => '', 'schema_type' => 'WebPage', 'blocks' => []], \AgeCMS\Seo::settings())['@graph'][0];
        $this->assertSame('434500000000', $ld['taxID']);
        $this->assertSame('info@example.test', $ld['email']);
        $this->assertSame('https://example.test/assets/uploads/logo.webp', $ld['logo']);
        $this->assertSame('ООО «Карат»', $ld['legalName']);
    }

    public function testThemeFieldsAreValidatedLikeBlockFieldsAndComeBackTyped(): void
    {
        $this->freshSite();
        Queue::submit($this->admin(), 'set_settings', ['values' => ['theme.footer.note' => "Строка 1\nСтрока 2", 'theme.header.show_phone' => false]]);
        $this->assertSame('0', (string)Database::value("SELECT value FROM settings WHERE key = 'theme.header.show_phone'"), 'bool is kept as 0/1');
        $values = Theme::settingValues('default', \AgeCMS\Seo::settings());
        $this->assertSame(false, $values['header']['show_phone']);
        $this->assertSame('', $values['header']['tagline'], 'a string never saved is empty');
        $this->assertSame(true, $values['footer']['show_contacts'], 'default from theme.json');
        $this->assertSame("Строка 1\nСтрока 2", $values['footer']['note']);

        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => ['theme.header.tagline' => str_repeat('x', 81)]]), 'longer than 80');
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => ['theme.header.show_phone' => 'maybe']]), 'true or false');
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => ['theme.header.logo' => '/x.png']]), 'not declared by theme');
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => ['theme.header.tagline' => "две\nстроки"]]), 'control characters');

        $editor = \AgeCMS\Actor::agent('ed', 'editor');
        $this->assertThrows(AgeCMSException::class, fn() => Queue::submit($editor, 'set_settings', ['values' => ['theme.footer.note' => 'x']]));
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM revisions WHERE page_slug = '@settings' AND action_desc LIKE '%theme.footer.note%'"), 'the change is in the history');
    }

    public function testATypoInTheManifestIsLeftOutInsteadOfBreakingThePage(): void
    {
        $this->freshSite();
        $manifest = ['settings' => ['header' => ['fields' => ['ok' => ['type' => 'string'], 'items' => ['type' => 'list'], 'Bad-Name' => ['type' => 'string']]], 'sidebar' => ['fields' => ['x' => ['type' => 'string']]], 'footer' => 'nope']];
        $reflection = new ReflectionProperty(Theme::class, 'manifests');
        $saved = $reflection->getValue();
        $reflection->setValue(null, ['default' => $manifest + Theme::manifest('default')]);
        try {
            $settings = Theme::settings('default');
            $this->assertSame(['header'], array_keys($settings), 'only known sections');
            $this->assertSame(['ok'], array_keys($settings['header']['fields']), 'lists and bad names are left out');
            $this->assertSame('Шапка', $settings['header']['title']);
        } finally {
            $reflection->setValue(null, $saved);
        }
    }

    public function testTheCookieNoticeIsOnByDefaultAndKeepsOutOfTheEditor(): void
    {
        $this->freshSite();
        $this->createPage('cookie-probe', ['title' => 'Проба', 'blocks' => [['id' => 't', 'type' => 'text', 'data' => ['body' => 'Текст']]]]);
        $page = Renderer::renderSlug('cookie-probe', false)['html'];
        $this->assertSame(1, substr_count($page, 'data-ag-cookie '));
        $this->assertContains(SiteSettings::COOKIE_TEXT, $page);
        $this->assertNotContains('Подробнее', $page, 'no link without a policy');
        $this->assertTrue(strpos($page, '/assets/cookie.css') < strpos($page, '</head>'));
        $this->assertTrue(strpos($page, '/assets/cookie.js') > strpos($page, '<main>'));
        $this->assertNotContains('data-ag-cookie', Renderer::renderSlug('cookie-probe', true, 'csrf')['html'], 'not in the editor');
        $this->assertContains('data-ag-cookie', Renderer::shell('Не найдено', '<p>404</p>'), 'module pages and errors have it too');

        Queue::submit($this->admin(), 'set_settings', ['values' => ['cookie_text' => 'Свой <текст>', 'cookie_policy_url' => '/privacy']]);
        $page = Renderer::renderSlug('cookie-probe', false)['html'];
        $this->assertContains('Свой &lt;текст&gt; <a href="/privacy">Подробнее</a>', $page);

        $own = Renderer::withCookieNotice('<html><head></head><body><div data-ag-cookie>своя</div></body></html>', ['cookie_banner' => '1']);
        $this->assertSame(1, substr_count($own, 'data-ag-cookie'), 'the theme draws its own, the core does not add a second one');

        Queue::submit($this->admin(), 'set_settings', ['values' => ['cookie_banner' => '0']]);
        $this->assertNotContains('data-ag-cookie', Renderer::renderSlug('cookie-probe', false)['html'], 'switched off');
    }

    public function testTabsAreLinksAndAnUnknownTabIsTheFirst(): void
    {
        $tabs = ['key' => 'Ключ', 'login' => 'Вход <b>'];
        $this->assertSame('key', AdminTabs::current($tabs, null));
        $this->assertSame('key', AdminTabs::current($tabs, 'nope'));
        $this->assertSame('key', AdminTabs::current($tabs, ['login']));
        $this->assertSame('login', AdminTabs::current($tabs, 'login'));
        $nav = AdminTabs::nav($tabs, 'login', '/admin/m/lk/settings');
        $this->assertContains('<a href="/admin/m/lk/settings?tab=key">Ключ</a>', $nav);
        $this->assertContains('<a href="/admin/m/lk/settings?tab=login" aria-current="page">Вход &lt;b&gt;</a>', $nav);
        $this->assertContains('href="/admin/?s=settings&amp;tab=key"', AdminTabs::nav($tabs, 'key', '/admin/?s=settings'));
        $this->assertSame('<input type="hidden" name="tab" value="login">', AdminTabs::field('login'));
        $this->assertThrows(AgeCMSException::class, fn() => AdminTabs::current([], 'x'));
    }
}
