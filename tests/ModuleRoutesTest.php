<?php
declare(strict_types=1);

use AgeCMS\{Content, Database, ModuleSigner, ModuleSitemap, Security, SkillRegistry, ValidationException};

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

/** Module addresses (E1): what a verified module may serve and what the core keeps for itself. */
final class ModuleRoutesTest extends HttpCase
{
    private function aged(): string
    {
        $time = (string)(time() - 10);
        return $time . '.' . Security::sign('form', $time);
    }

    private function status(string $module): array
    {
        SkillRegistry::reset();
        return SkillRegistry::modules()[$module];
    }

    private function log(): string
    {
        return (string)@file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json');
    }

    // 1. Only modules with a verified signature get addresses.

    public function testTamperedModulesGetNoAddresses(): void
    {
        $this->boot();
        $sql = ['migrations/001_init.sql' => 'CREATE TABLE mod_rt_tampered_t (id INTEGER PRIMARY KEY);'];
        ModuleFixture::make('rt_signed', ModuleFixture::routes('rt_signed'));
        ModuleFixture::make('rt_tampered', ModuleFixture::routes('rt_tampered'), $sql);
        ModuleFixture::make('rt_unsigned', ModuleFixture::routes('rt_unsigned'));
        file_put_contents(AGECMS_MODULES . '/rt_tampered/Module.php', "<?php // changed after signing\n");
        unlink(AGECMS_MODULES . '/rt_unsigned/manifest.json.sig');

        $ok = $this->http('GET', '/rt_signed/hello?x=1');
        $this->assertSame(200, $ok['status']);
        $this->assertContains('hello 1', $ok['body']);
        $this->assertSame(404, $this->http('GET', '/rt_tampered/hello')['status'], 'file changed after signing');
        $this->assertSame(200, $this->http('GET', '/rt_unsigned/hello')['status'], 'without a signature file the module runs as a user module');
        $this->assertSame(405, $this->http('POST', '/rt_tampered/form', '{}', ['Content-Type' => 'application/json'])['status'], 'a POST finds nothing either');

        Database::reset();
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM sqlite_master WHERE name = 'mod_rt_tampered_t'"), 'a blocked module gets no migrations');
        $this->assertSame(200, $this->http('GET', '/')['status'], 'the site itself is fine');
    }

    // 2. Addresses of the core, pages and other modules stay out of reach.

    public function testCoreAddressesAndMalformedRoutesBlockTheModule(): void
    {
        $this->freshSite();
        $cases = [
            'rt_adm' => '/admin/panel', 'rt_asset' => '/assets/x', 'rt_search' => '/search', 'rt_under' => '/_/x',
            'rt_smap' => '/sitemap', 'rt_ph' => '/{id}/x', 'rt_dots' => '/robots.txt', 'rt_up' => '/Kabinet',
            'rt_deep' => '/a/b/c/d/e/f/g', 'rt_dup' => '/dup',
        ];
        foreach ($cases as $name => $path) {
            $routes = [['path' => $path]];
            if ($name === 'rt_dup') {
                $routes[] = ['path' => $path];
            }
            ModuleFixture::make($name, $routes);
            $info = $this->status($name);
            $this->assertSame('blocked', $info['status'], $path);
        }
        $this->assertContains('belongs to the core', $this->status('rt_adm')['reason']);
        $this->assertContains('declared twice', $this->status('rt_dup')['reason']);

        ModuleFixture::make('rt_methods', [['path' => '/rt-methods', 'methods' => ['DELETE']]]);
        $this->assertSame('blocked', $this->status('rt_methods')['status']);
        ModuleFixture::make('rt_pubpost', [['path' => '/rt-pubpost', 'methods' => ['GET', 'POST'], 'cache' => 'public']]);
        $this->assertSame('blocked', $this->status('rt_pubpost')['status'], 'a public route is GET only');
        ModuleFixture::make('rt_rate', [['path' => '/rt-rate', 'rate' => ['limit' => 100000, 'window' => 60]]]);
        $this->assertSame('blocked', $this->status('rt_rate')['status'], 'limits stay inside sane bounds');
        ModuleFixture::make('rt_unknown', [['path' => '/rt-unknown', 'headers' => ['X-Frame-Options' => 'ALLOWALL']]]);
        $this->assertSame('blocked', $this->status('rt_unknown')['status'], 'a route cannot carry headers');
    }

    public function testRoutesCannotTakeAnExistingPageOrAnotherModulesAddress(): void
    {
        $this->boot();
        $this->createPage('rt-page');
        ModuleFixture::make('rt_overpage', [['path' => '/rt-page']]);
        $this->assertContains("collides with the page 'rt-page'", $this->status('rt_overpage')['reason']);
        $this->assertSame(200, $this->http('GET', '/rt-page')['status'], 'the page stays reachable');

        $this->createPage('rt-section');
        $this->createPage('rt-section/child', ['parent_slug' => 'rt-section']);
        ModuleFixture::make('rt_overchild', [['path' => '/rt-section/{id}']]);
        $this->assertSame('blocked', $this->status('rt_overchild')['status'], 'a placeholder covers the child page');

        ModuleFixture::make('rt_first', [['path' => '/rt-shared/page']]);
        ModuleFixture::make('rt_second', [['path' => '/rt-shared/page']]);
        ModuleFixture::make('rt_third', [['path' => '/rt-shared/{id}']]);
        $this->assertSame('ok', $this->status('rt_first')['status'], 'the first module keeps the address');
        $this->assertContains('collides with route /rt-shared/page of module rt_first', $this->status('rt_second')['reason']);
        $this->assertContains('of module rt_first', $this->status('rt_third')['reason'], 'overlap through a placeholder is a collision too');
        $this->assertContains('collides', $this->log(), 'the refusal is logged');
    }

    public function testPagesCannotTakeAnAddressOfAModule(): void
    {
        $this->freshSite();
        ModuleFixture::make('rt_claim', [['path' => '/rt-claim/room'], ['path' => '/rt-claim/item/{id}']]);
        $this->assertSame('ok', $this->status('rt_claim')['status']);

        $e = $this->assertThrows(ValidationException::class, fn() => $this->createPage('rt-claim/room'));
        $this->assertContains('belongs to the module rt_claim', $e->getMessage());
        $this->assertThrows(ValidationException::class, fn() => $this->createPage('rt-claim/item/77'));
        $this->createPage('rt-claim');
        $this->createPage('rt-other');
        $this->assertThrows(ValidationException::class, fn() => Content::renamePage($this->admin(), 'rt-other', 'rt-claim/room'));
    }

    // 3. POST needs the form token and the same site; every address has a request limit.

    public function testPostNeedsTheFormTokenAndTheSameSite(): void
    {
        $this->boot();
        ModuleFixture::make('rt_form', ModuleFixture::routes('rt_form'));
        $url = '/rt_form/form';
        $post = fn(array $data, array $headers = []) => $this->http('POST', $url, json_encode($data), ['Content-Type' => 'application/json'] + $headers);

        $page = $this->http('GET', $url);
        $this->assertSame(200, $page['status']);
        $this->assertContains('name="token"', $page['body'], 'the form carries the token');

        $this->assertSame(400, $post(['v' => 'a'])['status'], 'no token');
        $this->assertSame(400, $post(['v' => 'a', 'token' => 'garbage'])['status'], 'forged token');
        $this->assertSame(400, $post(['v' => 'a', 'token' => $this->aged() . 'x'])['status'], 'token with a changed signature');
        $json = json_decode($this->http('GET', '/_/token')['body'], true);
        $this->assertSame(400, $post(['v' => 'a', 'token' => $json['token']])['status'], 'a token younger than two seconds');
        $this->assertSame(400, $post(['v' => 'a', 'token' => $this->aged(), 'website_hp' => 'spam'])['status'], 'honeypot');

        $ok = $post(['v' => 'hello', 'token' => $this->aged()]);
        $this->assertSame(200, $ok['status']);
        $body = json_decode($ok['body'], true);
        $this->assertSame('hello', $body['v']);
        $this->assertSame(['v'], $body['fields'], 'the module never sees the token fields');

        $this->assertSame(403, $post(['v' => 'a', 'token' => $this->aged()], ['Origin' => 'https://evil.example'])['status'], 'foreign origin');
        $this->assertSame(403, $post(['v' => 'a', 'token' => $this->aged()], ['Sec-Fetch-Site' => 'cross-site'])['status'], 'cross-site fetch');
        $this->assertSame(405, $this->http('POST', '/rt_form/hello', '{}', ['Content-Type' => 'application/json'])['status'], 'a GET-only address');
    }

    public function testEveryAddressHasARequestLimit(): void
    {
        $this->boot();
        ModuleFixture::make('rt_lim', [
            ['path' => '/rt_lim/hello', 'rate' => ['limit' => 3, 'window' => 60]],
            ['path' => '/rt_lim/form', 'methods' => ['POST'], 'rate' => ['limit' => 2, 'window' => 60]],
            ['path' => '/rt_lim/whoami'],
        ]);
        foreach ([200, 200, 200] as $expected) {
            $this->assertSame($expected, $this->http('GET', '/rt_lim/hello')['status']);
        }
        $over = $this->http('GET', '/rt_lim/hello');
        $this->assertSame(429, $over['status']);
        $this->assertSame('60', $over['headers']['retry-after']);

        $post = fn(string $token) => $this->http('POST', '/rt_lim/form', json_encode(['v' => 1, 'token' => $token]), ['Content-Type' => 'application/json'])['status'];
        $this->assertSame(400, $post('bad'), 'a refused form still counts');
        $this->assertSame(200, $post($this->aged()));
        $this->assertSame(429, $post($this->aged()));

        $defaults = Database::value("SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE 'mod:rt_lim:/rt_lim/whoami:%'");
        $this->http('GET', '/rt_lim/whoami');
        $this->assertSame((int)$defaults + 1, (int)Database::value("SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE 'mod:rt_lim:/rt_lim/whoami:%'"), 'the default limit counts too');
    }

    // 5. The answer of a module goes through the core's headers and error page.

    public function testSecurityHeadersErrorsRedirectsAndCookies(): void
    {
        $this->boot();
        ModuleFixture::make('rt_one', ModuleFixture::routes('rt_one'));

        $page = $this->http('GET', '/rt_one/hello');
        $this->assertSame("default-src 'self'; img-src 'self' data: https:; style-src 'self'; script-src 'self' https://mc.yandex.ru; connect-src 'self' https://mc.yandex.ru https://mc.yandex.com wss://mc.yandex.ru; worker-src 'self' blob:; base-uri 'self'; form-action 'self'; frame-src 'self' https://yandex.ru/map-widget/ https://yandex.com/map-widget/ https://www.google.com/maps/embed https://www.openstreetmap.org/export/embed.html https://mc.yandex.ru; frame-ancestors 'self'", $page['headers']['content-security-policy']);
        $this->assertSame('nosniff', $page['headers']['x-content-type-options']);
        $this->assertSame('SAMEORIGIN', $page['headers']['x-frame-options']);
        $this->assertSame('strict-origin-when-cross-origin', $page['headers']['referrer-policy']);

        $boom = $this->http('GET', '/rt_one/boom');
        $this->assertSame(500, $boom['status']);
        $this->assertSame(false, str_contains($boom['body'], 'secret trace detail'), 'no message for the visitor');
        $this->assertSame(false, str_contains($boom['body'], 'Module.php'), 'no file names');
        $this->assertSame(false, str_contains($boom['body'], 'Stack trace'));
        $this->assertSame('nosniff', $boom['headers']['x-content-type-options'], 'the error page has the headers too');
        $this->assertContains('secret trace detail', $this->log(), 'the details go to the log');
        $this->assertContains('module.route_failed', $this->log());

        $go = $this->http('GET', '/rt_one/go');
        $this->assertSame(303, $go['status']);
        $this->assertSame('/rt_one/hello', $go['headers']['location']);
        $evil = $this->http('GET', '/rt_one/evil');
        $this->assertSame(500, $evil['status'], 'a redirect to another site is refused');
        $this->assertSame(false, isset($evil['headers']['location']));

        $login = $this->http('GET', '/rt_one/login');
        $cookie = $login['headers']['set-cookie'] ?? '';
        $this->assertContains('rt_one_sid=abc123', $cookie, 'the module prefix is added');
        $this->assertContains('HttpOnly', $cookie);
        $this->assertContains('SameSite=Lax', $cookie);
        $who = json_decode($this->http('GET', '/rt_one/whoami', null, ['Cookie' => 'rt_one_sid=abc123; agecms_admin=stolen; other_sid=zzz'])['body'], true);
        $this->assertSame('abc123', $who['sid']);
        $this->assertSame(['sid'], $who['all'], 'the module sees its own cookies only, never the admin session');
        $this->assertContains('item 42', $this->http('GET', '/rt_one/item/42')['body'], 'placeholder values reach the module');
        $this->assertSame(404, $this->http('GET', '/rt_one/item/a.b')['status'], 'a placeholder takes letters, digits, - and _ only');
    }

    // 6. Pages of modules are drawn in the theme and are never cached.

    public function testPagesOfModulesAreInTheThemeAndNeverCached(): void
    {
        $this->boot();
        ModuleFixture::make('rt_priv', ModuleFixture::routes('rt_priv'));

        $this->http('GET', '/');
        $page = $this->http('GET', '/rt_priv/hello');
        $this->assertContains('<header class="site-header">', $page['body'], 'drawn inside the theme');
        $this->assertContains('mod-hello', $page['body']);
        $this->assertSame('private, no-store', $page['headers']['cache-control']);
        $this->assertSame('bypass', $page['headers']['x-agecms-cache']);
        $this->http('GET', '/rt_priv/hello');
        $this->assertSame(false, is_file(AGECMS_CACHE . '/rt_priv/hello.html'), 'not written to the shadow cache');
        $this->assertSame(false, is_dir(AGECMS_CACHE . '/rt_priv'));
        $this->assertSame('private, no-store', $this->http('GET', '/rt_priv/item/7')['headers']['cache-control']);
        $this->assertSame('private, no-store', $this->http('GET', '/rt_priv/boom')['headers']['cache-control'], 'errors are not stored either');

        $pub = $this->http('GET', '/rt_priv/pub');
        $this->assertSame('public, max-age=120', $pub['headers']['cache-control'], 'only when the manifest says so');
        $this->assertSame(false, is_file(AGECMS_CACHE . '/rt_priv/pub.html'), 'even a public page stays out of the shadow cache');
        $this->assertSame(500, $this->http('GET', '/rt_priv/pubcookie')['status'], 'a public page cannot set cookies');
        $this->assertContains('content="noindex, nofollow"', $pub['body'], 'a public page is still noindex unless the route says indexable');
    }

    public function testAnIndexableRouteIsOfferedToSearchEnginesAndOthersAreNot(): void
    {
        $this->boot();
        ModuleFixture::make('rt_idx', ModuleFixture::routes('rt_idx'));
        $page = $this->http('GET', '/rt_idx/idx');
        $this->assertSame(200, $page['status']);
        $this->assertContains('content="index, follow"', $page['body']);
        $this->assertNotContains('noindex', $page['body']);
        $this->assertContains('<meta name="description" content="Описание витрины модуля">', $page['body'], 'tags are stripped from the description');
        $this->assertContains('<link rel="canonical" href="http://127.0.0.1:' . $this->port . '/rt_idx/idx">', $page['body']);
        $this->assertSame('public, max-age=120', $page['headers']['cache-control']);
        $this->assertContains('noindex', $this->http('GET', '/rt_idx/hello')['body'], 'a route without the flag stays out of search');
        $this->assertContains('noindex', $this->http('GET', '/rt_idx/nothing')['body'], 'an error page of an indexable route is not offered either');
    }

    // Addresses of modules in sitemap.xml (E5).

    public function testIndexableAddressesOfAModuleAreInTheSitemap(): void
    {
        $this->boot();
        $routes = [['path' => '/sm-shop/{id}', 'cache' => 'public', 'indexable' => true], ['path' => '/sm-shop/secret/{id}', 'cache' => 'public'], ['path' => '/sm-shop/private/{id}']];
        ModuleFixture::make('sm_shop', $routes, [], [], true, [
            ['path' => '/sm-shop/12', 'lastmod' => '2026-10-20 10:00:00'],
            ['path' => '/sm-shop/13'],
            ['path' => '/sm-shop/secret/5'],       // public but not indexable
            ['path' => '/sm-shop/private/6'],      // private
            ['path' => '/kontakty'],               // a page, not a route of the module
            ['path' => '/admin/'],
            ['path' => 'https://evil.example/x'],
            ['path' => '/sm-shop/12'],             // twice
            ['path' => "/sm-shop/9\n<x>"],
        ]);
        $xml = $this->http('GET', '/sitemap.xml')['body'];
        $base = 'http://127.0.0.1:' . $this->port;
        $this->assertContains('<loc>' . $base . '/sm-shop/12</loc><lastmod>2026-10-20</lastmod>', $xml);
        $this->assertContains('<loc>' . $base . '/sm-shop/13</loc>', $xml);
        $this->assertSame(2, substr_count($xml, '/sm-shop/'), 'only the two indexable addresses, once each');
        $this->assertNotContains('secret', $xml);
        $this->assertNotContains('private', $xml);
        $this->assertNotContains('evil', $xml);
        $this->assertNotContains('/admin', $xml);
    }

    public function testAModuleCannotListAddressesOfAnotherModule(): void
    {
        $this->boot();
        ModuleFixture::make('sm_one', [['path' => '/sm-one/{id}', 'cache' => 'public', 'indexable' => true]], [], [], true, [['path' => '/sm-two/7'], ['path' => '/sm-one/1']]);
        ModuleFixture::make('sm_two', [['path' => '/sm-two/{id}', 'cache' => 'public', 'indexable' => true]]);
        $xml = $this->http('GET', '/sitemap.xml')['body'];
        $this->assertContains('/sm-one/1</loc>', $xml);
        $this->assertNotContains('/sm-two/7', $xml);
        SkillRegistry::reset();
        $this->assertSame(false, ModuleSitemap::allowed('sm_one', '/sm-two/7'));
        $this->assertSame(true, ModuleSitemap::allowed('sm_two', '/sm-two/7'));
        $this->assertSame(1, ModuleSitemap::ping('sm_two', ['/sm-two/7', '/sm-one/1', '/nothing']), 'a ping is accepted only for the own addresses');
        $this->assertSame(0, ModuleSitemap::ping('sm_missing', ['/sm-two/7']), 'an unknown module pings nothing');
    }

    public function testSitemapTakesNoMoreThanTheLimitFromOneModule(): void
    {
        $this->boot();
        $many = [];
        for ($i = 1; $i <= ModuleSitemap::LIMIT + 50; $i++) {
            $many[] = ['path' => '/sm-many/' . $i];
        }
        ModuleFixture::make('sm_many', [['path' => '/sm-many/{id}', 'cache' => 'public', 'indexable' => true]], [], [], true, $many);
        $xml = $this->http('GET', '/sitemap.xml')['body'];
        $this->assertSame(ModuleSitemap::LIMIT, substr_count($xml, '/sm-many/'));
        $this->assertContains('/sm-many/1</loc>', $xml);
        $this->assertNotContains('/sm-many/' . (ModuleSitemap::LIMIT + 1) . '<', $xml);
    }

    public function testABlockedModuleAddsNothingToTheSitemap(): void
    {
        $this->boot();
        $dir = ModuleFixture::make('sm_blocked', [['path' => '/sm-blocked/{id}', 'cache' => 'public', 'indexable' => true]], [], [], true, [['path' => '/sm-blocked/1']]);
        $this->assertContains('/sm-blocked/1</loc>', $this->http('GET', '/sitemap.xml')['body']);
        file_put_contents($dir . '/Module.php', file_get_contents($dir . '/Module.php') . "\n// tampered\n");
        $this->assertSame('blocked', $this->status('sm_blocked')['status']);
        $this->assertNotContains('/sm-blocked/', $this->http('GET', '/sitemap.xml')['body'], 'the signature no longer matches');
        $this->assertSame(0, ModuleSitemap::ping('sm_blocked', ['/sm-blocked/1']));
    }

    public function testAFailingModuleDoesNotBreakTheSitemap(): void
    {
        $this->boot();
        ModuleFixture::make('sm_fail', [['path' => '/sm-fail/{id}', 'cache' => 'public', 'indexable' => true]], [], [], true, [['path' => '/sm-fail/1']]);
        $dir = AGECMS_MODULES . '/sm_fail';
        file_put_contents($dir . '/Module.php', str_replace('return array (', 'throw new \RuntimeException("boom"); return array (', file_get_contents($dir . '/Module.php')));
        ModuleFixture::resign('sm_fail');
        $res = $this->http('GET', '/sitemap.xml');
        $this->assertSame(200, $res['status']);
        $this->assertContains('</urlset>', $res['body']);
        $this->assertNotContains('/sm-fail/', $res['body']);
    }

    public function testIndexableNeedsAPublicRoute(): void
    {
        $this->boot();
        ModuleFixture::make('rt_idxbad', [['path' => '/rt-idxbad', 'indexable' => true]]);
        $this->assertSame('blocked', $this->status('rt_idxbad')['status']);
        ModuleFixture::make('rt_idxtype', [['path' => '/rt-idxtype', 'cache' => 'public', 'indexable' => 'yes']]);
        $this->assertSame('blocked', $this->status('rt_idxtype')['status']);
    }

    // A module whose migration fails is off and costs the site nothing.

    public function testAFailedMigrationTurnsTheModuleOffAndTheSiteStaysUp(): void
    {
        $this->boot();
        ModuleFixture::make('rt_badmig', ModuleFixture::routes('rt_badmig'), ['migrations/001_init.sql' => 'CREATE TABLE mod_rt_badmig_t (id INTEGER PRIMARY KEY); THIS IS NOT SQL;']);
        $this->assertSame(404, $this->http('GET', '/rt_badmig/hello')['status']);
        $this->assertSame(404, $this->http('GET', '/rt_badmig/hello')['status'], 'and again, without trying the migration again');
        $this->assertSame(200, $this->http('GET', '/')['status']);
        $this->assertSame(1, substr_count($this->log(), 'module.migration_failed'), 'logged once');
    }

    public function testAModuleWithRoutesNeedsTheRoutedInterfaceAndKeepsItsSkills(): void
    {
        $this->freshSite();
        ModuleFixture::make('rt_plain', [['path' => '/rt-plain']], [], [], false);
        SkillRegistry::reset();
        SkillRegistry::boot();
        $this->assertSame('blocked', SkillRegistry::modules()['rt_plain']['status']);
        $this->assertContains('RoutedModuleInterface', SkillRegistry::modules()['rt_plain']['reason']);

        ModuleFixture::make('rt_skilled', [['path' => '/rt-skilled']]);
        SkillRegistry::reset();
        $names = array_map(fn($s) => $s['module'] . '/' . $s['name'], SkillRegistry::skills());
        $this->assertTrue(in_array('rt_skilled/ping', $names, true), 'tools <module>__<skill> keep working');
        $this->assertTrue(in_array('telegram_leads/status', $names, true));
        $this->assertSame(['pong' => true], SkillRegistry::call('rt_skilled', 'ping', [], $this->admin()));
    }

    public function testThePolicyInEveryPlaceIsTheSameAndAllowsOnlyMapWidgets(): void
    {
        $root = dirname(__DIR__);
        $php = (require $root . '/core/security-headers.php')['Content-Security-Policy'];
        $this->assertContains("frame-src 'self' https://yandex.ru/map-widget/", $php);
        $this->assertContains('https://www.openstreetmap.org/export/embed.html', $php);
        $this->assertNotContains('https://*', $php, 'no wildcard hosts');
        $this->assertNotContains("'unsafe-inline'", $php);
        foreach (['/.htaccess', '/nginx.vhost.example'] as $file) {
            $this->assertContains($php, (string)file_get_contents($root . $file), $file . ' repeats the policy of core/security-headers.php');
        }
    }
}
