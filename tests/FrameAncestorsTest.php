<?php
declare(strict_types=1);

use AgeCMS\{Config, Embedding, SkillRegistry};

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

/**
 * Who may show the site in a frame (security.frame_ancestors): nobody but the site itself by default; with the setting the
 * public pages name the listed sites and drop X-Frame-Options; the admin, signed-in users and private module pages stay closed.
 */
final class FrameAncestorsTest extends HttpCase
{
    private const FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];
    private const TELEGRAM = "frame-ancestors 'self' https://web.telegram.org https://webk.telegram.org https://webz.telegram.org";

    private function closed(array $reply, string $what): void
    {
        $this->assertSame('SAMEORIGIN', $reply['headers']['x-frame-options'] ?? '', $what . ': X-Frame-Options');
        $csp = $reply['headers']['content-security-policy'] ?? '';
        $this->assertContains("frame-ancestors 'self'", $csp, $what);
        $this->assertNotContains('telegram', $csp, $what . ': no foreign sites');
    }

    private function open(array $reply, string $what): void
    {
        $this->assertSame('', $reply['headers']['x-frame-options'] ?? '', $what . ': no X-Frame-Options');
        $csp = $reply['headers']['content-security-policy'] ?? '';
        $this->assertContains(self::TELEGRAM, $csp, $what);
        $this->assertSame(1, substr_count($csp, 'frame-ancestors'), $what . ': one frame-ancestors');
        $this->assertContains("default-src 'self'", $csp, $what . ': the rest of the policy stays');
    }

    // 1. The setting itself.

    public function testSettingIsParsedStrictly(): void
    {
        $this->assertSame([], Embedding::parse('')['sources']);
        $this->assertSame([], Embedding::parse(null)['sources']);
        $this->assertSame(Embedding::PRESETS['telegram'], Embedding::parse('telegram')['sources']);
        $this->assertSame(Embedding::PRESETS['telegram'], Embedding::parse(' Telegram, telegram ')['sources'], 'case and repeats do not matter');
        $this->assertSame(['https://a.example.com', 'https://*.b.example', 'https://c.example:8443'], Embedding::parse('https://a.example.com https://*.b.example,https://c.example:8443')['sources']);
        $this->assertSame(['http://localhost:9000', 'http://127.0.0.1'], Embedding::parse(['http://localhost:9000', 'http://127.0.0.1'])['sources'], 'own machine for tests');
        foreach (['*', 'https://*', 'https://*.com', 'http://evil.example', 'https://evil.example/path', "https://evil.example; script-src *", "https://a.example 'unsafe-inline'", 'data:', "'none'", 'https://localhost', 'ftp://x.example', 'https://x', 'javascript:1'] as $bad) {
            $parsed = Embedding::parse([$bad]);
            $this->assertSame([], $parsed['sources'], $bad . ' opens nothing');
            $this->assertTrue($parsed['invalid'] !== [], $bad . ' is reported');
        }
        $many = Embedding::parse(implode(' ', array_map(fn($i) => "https://s{$i}.example.com", range(1, 40))));
        $this->assertSame(20, count($many['sources']), 'a short list');
    }

    public function testOpenKeepsTheRestOfThePolicy(): void
    {
        $strict = require dirname(__DIR__) . '/core/security-headers.php';
        $this->assertSame($strict, Embedding::open($strict, []), 'no sources: nothing changes');
        $open = Embedding::open($strict, ['https://web.telegram.org']);
        $this->assertTrue(!isset($open['X-Frame-Options']), 'no X-Frame-Options');
        $this->assertSame(str_replace("frame-ancestors 'self'", "frame-ancestors 'self' https://web.telegram.org", $strict['Content-Security-Policy']), $open['Content-Security-Policy']);
        $this->assertSame($strict['X-Content-Type-Options'], $open['X-Content-Type-Options']);
    }

    // 2. Public pages, from PHP and from the shadow cache.

    public function testByDefaultNothingChanges(): void
    {
        $this->boot();
        $first = $this->http('GET', '/');
        $second = $this->http('GET', '/');
        $this->assertSame('hit', $second['headers']['x-agecms-cache'] ?? '', 'the second visit comes from the shadow cache');
        $this->closed($first, 'page from PHP');
        $this->closed($second, 'page from the cache');
        $this->closed($this->http('GET', '/admin/'), 'admin');
    }

    public function testPublicPagesNameTheSitesAndLoseXFrameOptions(): void
    {
        $this->boot();
        Config::set('security.frame_ancestors', ['telegram']);
        $first = $this->http('GET', '/');
        $this->assertSame('miss', $first['headers']['x-agecms-cache'] ?? '');
        $this->open($first, 'page from PHP');
        $second = $this->http('GET', '/');
        $this->assertSame('hit', $second['headers']['x-agecms-cache'] ?? '');
        $this->open($second, 'page from the cache');
        $this->open($this->http('GET', '/?x=1'), 'page with a query');
        $missing = $this->http('GET', '/no-such-page-here');
        $this->assertSame(404, $missing['status']);
        $this->closed($missing, 'a 404 is not a page of the site');
    }

    public function testASingleStringAndTypoDoNotOpenTooMuch(): void
    {
        $this->boot();
        Config::set('security.frame_ancestors', 'https://shop.example.com');
        $csp = $this->http('GET', '/')['headers']['content-security-policy'] ?? '';
        $this->assertContains("frame-ancestors 'self' https://shop.example.com;", $csp . ';');
        Config::set('security.frame_ancestors', ['*']);
        $this->closed($this->http('GET', '/'), 'a wildcard in the file is ignored');
        Config::set('security.frame_ancestors', ['https://shop.example.com/page']);
        $this->closed($this->http('GET', '/'), 'an address with a path is ignored');
    }

    // 3. Closed pages stay closed.

    public function testAdminAndSignedInUsersStayClosed(): void
    {
        $this->boot();
        Config::set('security.frame_ancestors', ['telegram']);
        $this->closed($this->http('GET', '/admin/'), 'admin sign-in page');
        $this->closed($this->http('GET', '/admin/index.php?s=forgot'), 'admin page');
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), self::FORM, true);
        $this->closed($this->http('GET', '/admin/', null, [], true), 'admin panel');
        $this->closed($this->http('GET', '/admin/?s=system', null, [], true), 'admin system page');
        $page = $this->http('GET', '/', null, [], true);
        $this->assertSame('private, no-store', $page['headers']['cache-control'] ?? '');
        $this->closed($page, 'public page seen by a signed-in user');
        $this->closed($this->http('GET', '/_/token'), 'form token');
        $this->closed($this->http('GET', '/sitemap.xml'), 'sitemap');
    }

    public function testModulePagesOpenOnlyWhenPublicOrMarked(): void
    {
        $this->boot();
        ModuleFixture::make('fa_shop', [
            ['path' => '/fa_shop/hello'],
            ['path' => '/fa_shop/pub', 'cache' => 'public', 'max_age' => 120],
            ['path' => '/fa_shop/item/{id}', 'embed' => true],
            ['path' => '/fa_shop/form', 'methods' => ['GET', 'POST'], 'embed' => true],
            ['path' => '/api/fa_shop/html', 'methods' => ['GET'], 'api' => true],
        ]);
        SkillRegistry::reset();
        $this->assertSame('ok', SkillRegistry::modules()['fa_shop']['status']);

        $this->closed($this->http('GET', '/fa_shop/pub'), 'before the setting: public module page');
        $this->closed($this->http('GET', '/fa_shop/item/1'), 'before the setting: marked module page');

        Config::set('security.frame_ancestors', ['telegram']);
        $this->closed($this->http('GET', '/fa_shop/hello'), 'a private module page (a cabinet) stays closed');
        $this->open($this->http('GET', '/fa_shop/pub'), 'a public module page');
        $item = $this->http('GET', '/fa_shop/item/7');
        $this->assertSame(200, $item['status']);
        $this->open($item, 'a module page marked embed');
        $this->open($this->http('GET', '/fa_shop/form'), 'a form page marked embed');
        $api = $this->http('GET', '/api/fa_shop/html');
        $this->closed($api, 'an api address');
    }

    public function testEmbedMarkIsCheckedWhenTheModuleLoads(): void
    {
        $this->freshSite();
        $cases = [
            'fa_bad1' => ['path' => '/fa-bad1', 'embed' => 'yes'],
            'fa_bad2' => ['path' => '/fa-bad2', 'embed' => true, 'api' => true],
            'fa_bad3' => ['path' => '/fa-bad3', 'embed' => true, 'methods' => ['POST']],
            'fa_good' => ['path' => '/fa-good', 'embed' => true],
        ];
        foreach ($cases as $name => $route) {
            ModuleFixture::make($name, [$route]);
            SkillRegistry::reset();
            $this->assertSame($name === 'fa_good' ? 'ok' : 'blocked', SkillRegistry::modules()[$name]['status'], $name);
        }
    }

    // 4. Console, files for the web servers.

    public function testConsoleRefusesATypo(): void
    {
        $this->freshSite();
        $env = 'AGECMS_DATA_DIR=' . escapeshellarg(AGECMS_DATA) . ' AGECMS_CACHE_DIR=' . escapeshellarg(AGECMS_CACHE) . ' AGECMS_MODULES_DIR=' . escapeshellarg(AGECMS_MODULES) . ' AGECMS_UPLOADS_DIR=' . escapeshellarg(AGECMS_UPLOADS);
        $run = static fn(string $args): string => (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/config.php') . ' ' . $args . ' 2>&1');
        $this->assertContains('Not accepted: http://evil.example', $run('set security.frame_ancestors http://evil.example'));
        Config::reset();
        $this->assertSame([], Config::get('security.frame_ancestors'), 'nothing saved');
        $this->assertContains('Saved: https://web.telegram.org', $run('set security.frame_ancestors telegram'));
        Config::reset();
        $this->assertSame(Embedding::PRESETS['telegram'], Config::get('security.frame_ancestors'));
        $this->assertContains('Saved: (nobody)', $run("set security.frame_ancestors ''"));
        Config::reset();
        $this->assertSame([], Config::get('security.frame_ancestors'));
    }

    public function testWebServerTemplatesLetPhpDecideAndSkipTheDiskCacheForFrames(): void
    {
        $root = dirname(__DIR__);
        $nginx = (string)file_get_contents($root . '/nginx.vhost.example');
        $this->assertNotContains('agecms_csp', $nginx, 'no policy map: PHP sends the policy');
        $this->assertNotContains('fastcgi_hide_header Content-Security-Policy', $nginx);
        $this->assertContains('$http_sec_fetch_dest', $nginx);
        $this->assertContains('$agecms_framed', $nginx);
        $before = substr($nginx, 0, (int)strpos($nginx, 'location ^~ /assets/cache/'));
        $this->assertNotContains('add_header', preg_replace('/^\s*#.*$/m', '', $before), 'no add_header at server level');
        $htaccess = (string)file_get_contents($root . '/.htaccess');
        $this->assertSame(2, substr_count($htaccess, '%{HTTP:Sec-Fetch-Dest}'), 'both cache rules skip frames');
        $this->assertContains('expr=-z %{resp:Content-Security-Policy}', $htaccess);
    }
}
