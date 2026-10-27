<?php
declare(strict_types=1);

use AgeCMS\{Auth, SkillRegistry};

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

/** Pages of modules inside the admin (E6): who may open them, what the module sees, what it cannot do. */
final class ModuleAdminTest extends HttpCase
{
    private const FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];

    /** @return list<array<string,mixed>> */
    private function pages(string $permission = 'content.write'): array
    {
        return [
            ['path' => '/', 'title' => 'Рассылки', 'permission' => $permission],
            ['path' => '/save', 'title' => 'Сохранить', 'permission' => $permission, 'methods' => ['POST']],
            ['path' => '/item/{id}', 'title' => 'Позиция', 'permission' => $permission],
            ['path' => '/go', 'title' => 'Переход', 'permission' => $permission, 'menu' => false],
            ['path' => '/evil', 'title' => 'Чужой раздел', 'permission' => $permission, 'menu' => false],
            ['path' => '/evil2', 'title' => 'Чужой сайт', 'permission' => $permission, 'menu' => false],
            ['path' => '/cookie', 'title' => 'Печенье', 'permission' => $permission, 'menu' => false],
            ['path' => '/boom', 'title' => 'Сбой', 'permission' => $permission, 'menu' => false],
        ];
    }

    private function login(string $user = 'admin', string $password = 'Passw0rd-test-1'): string
    {
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => $user, 'password' => $password]), self::FORM, true);
        $page = $this->http('GET', '/admin/', null, [], true);
        preg_match('/name="csrf" value="([^"]+)"/', $page['body'], $m);
        return $m[1] ?? '';
    }

    private function post(string $path, array $data): array
    {
        return $this->http('POST', $path, http_build_query($data), self::FORM, true);
    }

    private function status(string $module): array
    {
        SkillRegistry::reset();
        return SkillRegistry::modules()[$module];
    }

    public function testPagesNeedASignedInUserAndNothingLeaksWithout(): void
    {
        $this->boot();
        ModuleFixture::make('ad_one', [], [], [], true, null, $this->pages());
        $get = $this->http('GET', '/admin/m/ad_one/');
        $this->assertSame(302, $get['status'] === 302 ? 302 : $get['status'], 'status ' . $get['status']);
        $this->assertSame('/admin/', $get['headers']['location'] ?? '');
        $this->assertNotContains('Страница', $get['body']);
        $this->assertSame(403, $this->post('/admin/m/ad_one/save', ['v' => 'x', 'csrf' => 'whatever'])['status'], 'a POST without a session is refused');
        $this->assertSame(403, $this->http('POST', '/admin/m/ad_one/save', 'v=1', self::FORM)['status']);
        $this->login();
        $this->assertSame(404, $this->http('GET', '/admin/m/nothing/', null, [], true)['status'], 'an unknown module');
        $this->assertSame(404, $this->http('GET', '/admin/m/ad_one/missing', null, [], true)['status'], 'an undeclared page');
        $this->assertSame(404, $this->http('GET', '/admin/m/ad_one/save', null, [], true)['status'], 'a POST page does not answer GET');
        $this->assertSame(404, $this->http('GET', '/admin/m/ad_one/item/a%20b', null, [], true)['status'], 'a bad placeholder value');
        $this->assertSame(405, $this->http('PUT', '/admin/m/ad_one/', null, [], true)['status']);
    }

    public function testThePageIsDrawnInsideTheAdminWithAMenuItem(): void
    {
        $this->boot();
        ModuleFixture::make('ad_two', [], [], [], true, null, $this->pages());
        $this->login();
        $r = $this->http('GET', '/admin/m/ad_two/', null, [], true);
        $this->assertSame(200, $r['status']);
        $this->assertContains('no-store', $r['headers']['cache-control'] ?? '');
        $this->assertContains('<h1>Страница &lt;модуля&gt;</h1>', $r['body'], 'the title is escaped by the layout');
        $this->assertContains('class="mod-admin">who admin admin', $r['body']);
        $this->assertContains('<a href="/admin/m/ad_two/" aria-current="page"><svg', $r['body'], 'menu item of the page');
        $this->assertContains('<span>Рассылки</span>', $r['body']);
        $this->assertNotContains('/admin/m/ad_two/save"><svg', $r['body'], 'a page that is not for the menu is not there');
        $this->assertContains('class="side"', $r['body'], 'the admin layout');
        $item = $this->http('GET', '/admin/m/ad_two/item/42', null, [], true);
        $this->assertContains('item 42', $item['body']);
        $this->assertNotContains('aria-current="page"><svg class="i" aria-hidden="true"><use href="/assets/admin/icons.svg#squares-four"/></svg><span>Позиция', $item['body']);
    }

    public function testPostNeedsTheAdminCsrfTokenAndTheModuleSeesNoCookies(): void
    {
        $this->boot();
        ModuleFixture::make('ad_post', [], [], [], true, null, $this->pages());
        $csrf = $this->login();
        $page = $this->http('GET', '/admin/m/ad_post/', null, [], true)['body'];
        $this->assertContains('name="csrf" value="' . $csrf . '"', $page, 'the form carries the admin token');

        $no = $this->post('/admin/m/ad_post/save', ['v' => 'x']);
        $this->assertSame(403, $no['status']);
        $this->assertContains('Сессия устарела', $no['body']);
        $this->assertSame(403, $this->post('/admin/m/ad_post/save', ['v' => 'x', 'csrf' => 'wrong'])['status']);
        // the public form token of modules is not the admin token
        $this->assertSame(403, $this->post('/admin/m/ad_post/save', ['v' => 'x', 'token' => 'abc'])['status']);

        // the browser sends the admin session and a cookie that looks like the module's own: the module gets neither
        preg_match('/\t(\w+)\t([^\t\r\n]+)\s*$/m', (string)file_get_contents($this->jar), $session);
        $cookie = ($session[1] ?? 'PHPSESSID') . '=' . ($session[2] ?? '') . '; ad_post_sid=zzz';
        $ok = $this->http('POST', '/admin/m/ad_post/save', http_build_query(['v' => 'hello', 'csrf' => $csrf]), self::FORM + ['Cookie' => $cookie]);
        $this->assertSame(200, $ok['status']);
        $this->assertContains('application/json', $ok['headers']['content-type'] ?? '');
        $data = json_decode($ok['body'], true);
        $this->assertSame(['v'], $data['fields'], 'the csrf field is not passed on');
        $this->assertSame('hello', $data['v']);
        $this->assertSame('admin', $data['actor']);
        $this->assertSame('ad_post', $data['module']);
        $this->assertSame([], $data['cookies'], 'no cookie reaches the module');
    }

    public function testPermissionsDecideWhoOpensAPage(): void
    {
        $this->boot();
        ModuleFixture::make('ad_perm', [], [], [], true, null, [
            ['path' => '/', 'title' => 'Для редакторов', 'permission' => 'content.write'],
            ['path' => '/admin-only', 'title' => 'Только админ', 'permission' => 'system.manage'],
        ]);
        Auth::createUser('editor1', 'long-enough-pass-1', 'editor');
        Auth::createUser('viewer1', 'long-enough-pass-1', 'viewer');

        $this->login('editor1', 'long-enough-pass-1');
        $page = $this->http('GET', '/admin/m/ad_perm/', null, [], true);
        $this->assertSame(200, $page['status']);
        $this->assertContains('<span>Для редакторов</span>', $page['body']);
        $this->assertNotContains('Только админ', $page['body'], 'an editor does not see the menu item of an admin page');
        $this->assertSame(403, $this->http('GET', '/admin/m/ad_perm/admin-only', null, [], true)['status'], 'an editor cannot open a page that needs system.manage');

        $this->login('viewer1', 'long-enough-pass-1');
        $denied = $this->http('GET', '/admin/m/ad_perm/', null, [], true);
        $this->assertSame(403, $denied['status']);
        $this->assertContains('lacks permission', $denied['body']);
        $this->assertNotContains('who ', $denied['body'], 'the module was not called');
    }

    public function testBlockedUserAndBlockedModuleAreRefused(): void
    {
        $this->boot();
        $dir = ModuleFixture::make('ad_block', [], [], [], true, null, $this->pages());
        $this->login();
        $this->assertSame(200, $this->http('GET', '/admin/m/ad_block/', null, [], true)['status']);
        $id = (int)AgeCMS\Database::value("SELECT id FROM users WHERE username = 'admin'");
        // a second administrator blocks the first one
        Auth::createUser('boss', 'long-enough-pass-1', 'admin');
        Auth::setBlocked($id, true, (int)AgeCMS\Database::value("SELECT id FROM users WHERE username = 'boss'"));
        $this->assertSame(302, $this->http('GET', '/admin/m/ad_block/', null, [], true)['status'], 'a blocked user is signed out');
        Auth::setBlocked($id, false, (int)AgeCMS\Database::value("SELECT id FROM users WHERE username = 'boss'"));
        $this->login();
        $this->assertSame(200, $this->http('GET', '/admin/m/ad_block/', null, [], true)['status']);

        file_put_contents($dir . '/Module.php', file_get_contents($dir . '/Module.php') . "\n// tampered\n");
        $this->assertSame('blocked', $this->status('ad_block')['status']);
        $this->assertSame(404, $this->http('GET', '/admin/m/ad_block/', null, [], true)['status'], 'a module whose signature no longer matches has no pages');
        $this->assertNotContains('Рассылки', $this->http('GET', '/admin/', null, [], true)['body'], 'and no menu item');
    }

    public function testAnswersAreChecked(): void
    {
        $this->boot();
        ModuleFixture::make('ad_ans', [], [], [], true, null, $this->pages());
        $this->login();
        $go = $this->http('GET', '/admin/m/ad_ans/go', null, [], true);
        $this->assertSame(303, $go['status']);
        $this->assertSame('/admin/m/ad_ans/', $go['headers']['location'] ?? '');
        foreach (['evil', 'evil2'] as $path) {
            $r = $this->http('GET', '/admin/m/ad_ans/' . $path, null, [], true);
            $this->assertSame(500, $r['status'], $path . ': a redirect out of the module is refused');
            $this->assertSame('', $r['headers']['location'] ?? '');
        }
        $cookie = $this->http('GET', '/admin/m/ad_ans/cookie', null, [], true);
        $this->assertSame(500, $cookie['status']);
        $this->assertNotContains('ad_ans_sid', $cookie['headers']['set-cookie'] ?? '');
        $boom = $this->http('GET', '/admin/m/ad_ans/boom', null, [], true);
        $this->assertSame(500, $boom['status']);
        $this->assertNotContains('secret trace detail', $boom['body']);
        $this->assertContains('class="side"', $boom['body'], 'the admin around it still works');
        $log = (string)@file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json');
        $this->assertContains('module.admin_failed', $log);
    }

    public function testBadAdminDeclarationsBlockTheModule(): void
    {
        $this->boot();
        $bad = [
            'ad_bad1' => [['path' => '/', 'title' => 'X']],                                                            // no permission
            'ad_bad2' => [['path' => '/', 'title' => 'X', 'permission' => 'anything.goes']],                           // unknown permission
            'ad_bad3' => [['path' => 'no-slash', 'title' => 'X', 'permission' => 'content.write']],
            'ad_bad4' => [['path' => '/a/b/c/d/e', 'title' => 'X', 'permission' => 'content.write']],
            'ad_bad5' => [['path' => '/../x', 'title' => 'X', 'permission' => 'content.write']],
            'ad_bad6' => [['path' => '/x', 'title' => '', 'permission' => 'content.write']],
            'ad_bad7' => [['path' => '/x', 'title' => 'X', 'permission' => 'content.write', 'methods' => ['DELETE']]],
            'ad_bad8' => [['path' => '/x/{id}', 'title' => 'X', 'permission' => 'content.write', 'menu' => true]],      // placeholder in the menu
            'ad_bad9' => [['path' => '/x/{id}', 'title' => 'X', 'permission' => 'content.write'], ['path' => '/x/{n}', 'title' => 'Y', 'permission' => 'content.write']],
            'ad_bad10' => [['path' => '/x', 'title' => 'X', 'permission' => 'content.write', 'secret' => 1]],
        ];
        foreach ($bad as $name => $pages) {
            ModuleFixture::make($name, [], [], [], true, null, $pages);
            $this->assertSame('blocked', $this->status($name)['status'], $name . ' should be blocked');
        }
        foreach (['ad_bad11' => 'not a list', 'ad_bad12' => ['a' => 1]] as $name => $pages) {
            ModuleFixture::make($name, [], [], ['admin' => $pages]);
            $this->assertSame('blocked', $this->status($name)['status'], $name . ' should be blocked');
        }
        // a module that declares pages but does not implement the interface
        ModuleFixture::make('ad_noiface', [], [], ['admin' => [['path' => '/', 'title' => 'X', 'permission' => 'content.write']]], false);
        SkillRegistry::reset();
        SkillRegistry::boot();
        $this->assertSame('blocked', SkillRegistry::modules()['ad_noiface']['status']);
        $this->assertContains('does not implement AdminModuleInterface', (string)SkillRegistry::modules()['ad_noiface']['reason']);
        $this->login();
        $this->assertSame(404, $this->http('GET', '/admin/m/ad_noiface/', null, [], true)['status']);
        // a good one next to the bad ones works
        ModuleFixture::make('ad_good', [], [], [], true, null, $this->pages());
        $this->assertSame(200, $this->http('GET', '/admin/m/ad_good/', null, [], true)['status']);
    }
}
