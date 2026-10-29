<?php
declare(strict_types=1);

use AgeCMS\{Auth, Database};

require_once __DIR__ . '/support/HttpCase.php';

final class HttpIntegrationTest extends HttpCase
{
    /** @return array<string,mixed> */
    private function rpc(string $token, array $payload): array
    {
        $r = $this->http('POST', '/admin/mcp.php', json_encode($payload), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
        $json = json_decode($r['body'], true);
        return ['status' => $r['status'], 'json' => $json];
    }

    private function toolText(array $response): array
    {
        return json_decode($response['json']['result']['content'][0]['text'], true);
    }

    public function testCacheMissThenHitAndSystemRoutes(): void
    {
        $this->boot();
        $first = $this->http('GET', '/');
        $this->assertSame(200, $first['status']);
        $this->assertSame('miss', $first['headers']['x-agecms-cache']);
        $this->assertContains('Добро пожаловать', $first['body']);
        $this->assertTrue(is_file(AGECMS_CACHE . '/index.html'));

        $second = $this->http('GET', '/');
        $this->assertSame('hit', $second['headers']['x-agecms-cache']);
        $this->assertSame($first['body'], $second['body']);
        $this->assertSame('nosniff', $second['headers']['x-content-type-options']);
        $this->assertContains("default-src 'self'", $second['headers']['content-security-policy']);
        $this->assertSame(304, $this->http('GET', '/', null, ['If-Modified-Since' => $second['headers']['last-modified']])['status']);

        $this->assertSame(200, $this->http('GET', '/about')['status']);
        $this->assertSame(['status' => 301, 'to' => '/about'], ['status' => ($r = $this->http('GET', '/about/'))['status'], 'to' => $r['headers']['location']]);
        $this->assertSame('/', $this->http('GET', '/index')['headers']['location']);

        $missing = $this->http('GET', '/no-such-page');
        $this->assertSame(404, $missing['status']);
        $this->assertSame(false, is_file(AGECMS_CACHE . '/no-such-page.html'), '404 is never cached');
        foreach (['/../etc/passwd', '/.env', '/data/config.php', '/admin/../data/data.sqlite'] as $path) {
            $this->assertTrue(in_array($this->http('GET', $path)['status'], [301, 400, 404], true), $path);
        }

        $this->assertContains('<urlset', $this->http('GET', '/sitemap.xml')['body']);
        $this->assertContains('Sitemap:', $this->http('GET', '/robots.txt')['body']);
        $this->assertContains('# ', $this->http('GET', '/llms.txt')['body']);
        $this->assertSame(405, $this->http('DELETE', '/')['status']);
    }

    public function testEditorFlowChangesPublicPageAndRefreshesCache(): void
    {
        $this->boot();
        $this->http('GET', '/');
        $this->assertTrue(is_file(AGECMS_CACHE . '/index.html'));

        $this->assertSame(401, $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']))['status'], 'anonymous');

        $wrong = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'wrong']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertContains('Неверный логин', $wrong['body']);

        $login = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertSame(302, $login['status']);

        $view = $this->http('GET', '/', null, [], true);
        $this->assertNotContains('data-ag-field', $view['body'], 'a signed-in user first sees the plain page');
        $this->assertContains('class="ag-fab"', $view['body'], 'with the pencil button');
        $this->assertContains('/_/edit?to=%2F', $view['body']);
        $this->assertSame('private, no-store', $view['headers']['cache-control']);
        $this->assertNotContains('ag-fab', $this->http('GET', '/')['body'], 'visitors never get the button');
        $this->assertSame('hit', $this->http('GET', '/')['headers']['x-agecms-cache'], 'and the cached page stays clean');

        $toggle = $this->http('GET', '/_/edit?to=%2F', null, [], true);
        $this->assertSame(302, $toggle['status']);
        $this->assertSame('/', $toggle['headers']['location']);
        $page = $this->http('GET', '/', null, [], true);
        $this->assertContains('data-ag-field="h1"', $page['body'], 'editing markup after the pencil');
        $this->assertContains('Выйти из режима редактирования', $page['body']);
        $this->assertSame('private, no-store', $page['headers']['cache-control']);
        preg_match('/name="ag-csrf" content="([^"]+)"/', $page['body'], $m);
        $csrf = $m[1] ?? '';
        $this->assertTrue($csrf !== '');

        $edit = json_encode(['action' => 'update_block_field', 'slug' => 'index', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'Новый заголовок']);
        $noCsrf = $this->http('POST', '/admin/api.php', $edit, ['Content-Type' => 'application/json'], true);
        $this->assertSame(403, $noCsrf['status'], 'session without CSRF header is refused');
        $ok = $this->http('POST', '/admin/api.php', $edit, ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf], true);
        $this->assertSame(200, $ok['status']);
        $this->assertTrue(is_int(json_decode($ok['body'], true)['result']['revision_id']));

        $anon = $this->http('GET', '/');
        $this->assertContains('Новый заголовок', $anon['body']);
        $this->assertSame('hit', $anon['headers']['x-agecms-cache'], 'the save re-cached the page itself, no cron needed');
        $this->assertSame('hit', $this->http('GET', '/')['headers']['x-agecms-cache']);

        $bad = $this->http('POST', '/admin/api.php', json_encode(['action' => 'update_block_field', 'slug' => 'index', 'block_id' => 'hero_1', 'field_path' => 'nope', 'value' => 'x']), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf], true);
        $this->assertSame(422, $bad['status'], 'unknown field is rejected by the schema');
    }

    public function testMcpAuthRolesAndErrors(): void
    {
        $this->boot();
        $viewer = Auth::createToken('viewer-bot', 'viewer');
        $editor = Auth::createToken('editor-bot', 'editor');

        $this->assertSame(401, $this->http('POST', '/admin/mcp.php', '{}', ['Content-Type' => 'application/json'])['status']);
        $this->assertSame(401, $this->rpc('agc_bogus', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['status']);
        $this->assertSame(405, $this->http('GET', '/admin/mcp.php')['status']);

        $init = $this->rpc($editor, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        $this->assertSame('agecms', $init['json']['result']['serverInfo']['name']);

        $names = fn(string $t) => array_column($this->rpc($t, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['json']['result']['tools'], 'name');
        $this->assertTrue(in_array('get_page', $names($viewer), true));
        $this->assertSame(false, in_array('update_block_field', $names($viewer), true), 'viewer sees no write tools');
        $this->assertTrue(in_array('update_block_field', $names($editor), true));
        $this->assertSame(false, in_array('clear_cache', $names($viewer), true));
        $this->assertTrue(in_array('clear_cache', $names($editor), true), 'editors may rebuild the cache');

        $args = ['name' => 'update_block_field', 'arguments' => ['slug' => 'about', 'block_id' => 'text_1', 'field_path' => 'heading', 'value' => 'От агента']];
        $denied = $this->rpc($viewer, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => $args]);
        $this->assertSame(true, $denied['json']['result']['isError'] ?? $denied['json']['error'] !== null, 'viewer cannot write');

        $done = $this->rpc($editor, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => $args]);
        $this->assertSame(false, $done['json']['result']['isError']);
        $this->assertContains('От агента', $this->http('GET', '/about')['body']);

        $invalid = $this->rpc($editor, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'update_block_field', 'arguments' => ['slug' => 'about', 'block_id' => 'text_1', 'field_path' => 'hallucinated', 'value' => 'x']]]);
        $this->assertSame(true, $invalid['json']['result']['isError']);
        $this->assertSame('validation', $this->toolText($invalid)['error']);

        $direct = $this->rpc($editor, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'get_page', 'params' => ['slug' => 'about']]);
        $this->assertSame('About' === 'x' ? '' : 'От агента', $direct['json']['result']['page']['blocks'][0]['data']['heading']);

        $batch = $this->http('POST', '/admin/mcp.php', json_encode([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'nope']]), ['Authorization' => "Bearer {$editor}", 'Content-Type' => 'application/json']);
        $responses = json_decode($batch['body'], true);
        $this->assertSame(2, count($responses), 'notifications get no response');
        $this->assertSame(-32601, $responses[1]['error']['code']);

        $this->assertSame(400, $this->http('POST', '/admin/mcp.php', 'not json', ['Authorization' => "Bearer {$editor}"])['status']);
    }

    public function testAgentMassEditTripsTheBreakerOverHttp(): void
    {
        $this->boot();
        $token = Auth::createToken('mass-bot', 'editor');
        $slugs = [];
        for ($i = 1; $i <= 10; $i++) {
            $this->createPage("p{$i}");
            $slugs[] = "p{$i}";
        }
        $statuses = [];
        foreach ($slugs as $slug) {
            $r = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'update_block_field', 'arguments' => ['slug' => $slug, 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'Spam ' . $slug]]]);
            $statuses[$slug] = $r['json']['result']['isError'] ?? true;
        }
        $this->assertSame(false, $statuses['p1']);
        $this->assertSame(false, $statuses['p2'], 'under the threshold, edits pass');
        $this->assertSame(true, $statuses['p10'], 'mass edit was stopped');
        $this->assertSame('1', (string)Database::value("SELECT value FROM settings WHERE key = 'frozen'") !== '' ? '1' : '0', 'site is frozen');

        $page = $this->http('GET', '/p10');
        $this->assertSame(200, $page['status']);
        $this->assertNotContains('Spam p10', $page['body'], 'the page that tripped the breaker was rolled back');
        $again = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'update_block_field', 'arguments' => ['slug' => 'p1', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'again']]]);
        $this->assertContains('frozen', strtolower($again['json']['result']['content'][0]['text']));
    }

    public function testLeadFormTimingHoneypotAndRateLimit(): void
    {
        $this->boot();
        $token = fn() => json_decode($this->http('GET', '/_/token')['body'], true)['token'];
        $post = fn(array $data, array $headers = []) => $this->http('POST', '/_/lead', json_encode($data), ['Content-Type' => 'application/json'] + $headers);

        $fast = $post(['token' => $token(), 'phone' => '+7 900 000-00-00', 'name' => 'Bot']);
        $this->assertSame(400, $fast['status'], 'submitted in under 2 seconds');

        $old = $token();
        sleep(3);
        $this->assertSame(400, $post(['token' => 'garbage', 'phone' => '+7 900 000-00-00'])['status']);

        $hp = $post(['token' => $old, 'phone' => '+7 900 000-00-00', 'website_hp' => 'http://spam']);
        $this->assertSame(400, $hp['status'], 'honeypot');
        $this->assertSame(403, $post(['token' => $old, 'phone' => '+7 900 000-00-00'], ['Origin' => 'https://evil.example'])['status'], 'foreign origin');
        $this->assertSame(422, $post(['token' => $old, 'phone' => 'abc'])['status'], 'invalid phone');

        $ok = $post(['token' => $old, 'phone' => '+7 900 000-00-00', 'name' => 'Иван', 'page' => 'index']);
        $this->assertSame(200, $ok['status']);
        $this->assertSame('Иван', Database::value('SELECT name FROM leads'));
        $this->assertSame(1, (int)Database::value('SELECT leads_count FROM analytics_daily'));

        // Attempts count, not only saved leads: the invalid one above used one of the 3 per minute.
        $this->assertSame(200, $post(['token' => $old, 'phone' => '+7 900 000-00-01'])['status']);
        $this->assertSame(429, $post(['token' => $old, 'phone' => '+7 900 000-00-02'])['status'], 'over 3 attempts per minute');
        $this->assertSame(2, (int)Database::value('SELECT COUNT(*) FROM leads'));
    }

    public function testLoginIsRateLimited(): void
    {
        $this->boot();
        for ($i = 0; $i < 5; $i++) {
            $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'bad' . $i]), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        }
        $locked = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertNotContains('Location', json_encode($locked['headers']), 'correct password is also refused while locked');
        $this->assertSame(200, $locked['status']);
    }

    public function testInstallerEndpointIsClosedAfterInstall(): void
    {
        $this->boot();
        $r = $this->http('GET', '/admin/install.php?key=' . str_repeat('k', 40));
        $this->assertTrue(in_array($r['status'], [403, 404], true), 'status ' . $r['status']);
        $r = $this->http('GET', '/admin/install.php');
        $this->assertTrue(in_array($r['status'], [403, 404], true));
    }

    private function adminLogin(): string
    {
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $page = $this->http('GET', '/admin/', null, [], true);
        preg_match('/name="csrf" value="([^"]+)"/', $page['body'], $m);
        return $m[1] ?? '';
    }

    private function form(array $data, string $csrf): array
    {
        return $this->http('POST', '/admin/index.php', http_build_query($data + ['csrf' => $csrf]), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
    }

    public function testAdminSectionsPageCreationAndSettings(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $this->assertTrue($csrf !== '');

        foreach (['pages' => 'Новая страница', 'menus' => 'ag-editor-data', 'history' => 'История изменений', 'overview' => 'Просмотры по дням', 'leads' => 'Посещаемость', 'settings' => 'Название сайта', 'system' => 'Токены для ИИ-агентов'] as $section => $marker) {
            $r = $this->http('GET', '/admin/?s=' . $section, null, [], true);
            $this->assertSame(200, $r['status'], $section);
            $this->assertContains($marker, $r['body'], $section);
        }
        $edit = $this->http('GET', '/admin/?s=edit&slug=about', null, [], true);
        $this->assertContains('"slug":"about"', $edit['body']);
        $this->assertContains('/assets/admin/editor.js', $edit['body']);
        $this->assertContains('Страница не найдена', $this->http('GET', '/admin/?s=edit&slug=nope', null, [], true)['body']);
        $this->assertSame(200, $this->http('GET', '/assets/admin/editor.js')['status']);

        $created = $this->form(['action' => 'page_create', 'slug' => 'uslugi/remont', 'title' => 'Ремонт <b>', 'schema_type' => 'Service'], $csrf);
        $this->assertSame(302, $created['status']);
        $this->assertContains('s=edit', $created['headers']['location']);
        $this->assertSame('0', (string)Database::value("SELECT is_published FROM pages WHERE slug = 'uslugi/remont'"), 'new pages start hidden');
        $this->assertSame(404, $this->http('GET', '/uslugi/remont')['status']);
        $bad = $this->form(['action' => 'page_create', 'slug' => 'Bad Slug', 'title' => 'x'], $csrf);
        $this->assertContains('not a valid slug', $bad['body']);

        $saved = $this->form(['action' => 'settings_save', 'site_name' => 'Сайт Кати', 'site_description' => 'Описание'], $csrf);
        $this->assertContains('Настройки сохранены', $saved['body']);
        $this->assertContains('Сайт Кати', $this->http('GET', '/')['body']);
        $this->assertContains('page_slug', (string)json_encode(Database::rows("SELECT page_slug FROM revisions WHERE page_slug = '@settings'")));

        $editor = Auth::createToken('ed-bot', 'editor');
        $denied = $this->http('POST', '/admin/api.php', json_encode(['action' => 'set_settings', 'values' => ['site_name' => 'Hacked']]), ['Authorization' => "Bearer {$editor}", 'Content-Type' => 'application/json']);
        $this->assertSame(403, $denied['status'], 'only administrators change settings');
    }

    public function testWebInstallerWizard(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        TestEnv::wipe();
        \AgeCMS\Config::reset();
        \AgeCMS\Database::reset();
        TestEnv::copyModules();
        mkdir(AGECMS_DATA, 0700, true);
        $this->jar = TEST_TMP . '/cookies.txt';
        $root = dirname(__DIR__);
        $env = ['AGECMS_DATA_DIR' => AGECMS_DATA, 'AGECMS_CACHE_DIR' => AGECMS_CACHE, 'AGECMS_MODULES_DIR' => AGECMS_MODULES, 'AGECMS_UPLOADS_DIR' => AGECMS_UPLOADS, 'PATH' => (string)getenv('PATH')];
        $this->proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $root, $root . '/tests/support/router.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $root, $env);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $this->port, $e, $s, 0.1); $i++) {
            usleep(100000);
        }
        $key = str_repeat('k', 40);

        $this->assertSame(404, $this->http('GET', "/admin/install.php?key={$key}")['status'], 'closed without data/install.key');
        $this->assertSame(503, $this->http('GET', '/')['status'], 'nothing is installed by a plain visit');
        $this->assertSame(false, is_file(AGECMS_DATA . '/data.sqlite'));

        file_put_contents(AGECMS_DATA . '/install.key', $key);
        $this->assertSame(404, $this->http('GET', '/admin/install.php?key=wrong')['status']);
        $step1 = $this->http('GET', "/admin/install.php?key={$key}");
        $this->assertSame(200, $step1['status']);
        $this->assertContains('Проверка среды', $step1['body']);
        $this->assertContains('Всё необходимое есть', $step1['body']);
        $step2 = $this->http('GET', "/admin/install.php?key={$key}&step=2");
        $this->assertContains('127.0.0.1:' . $this->port, $step2['body'], 'address is prefilled from the request');
        $this->assertContains('confirm_url', $step2['body']);

        $form = fn(array $d) => $this->http('POST', '/admin/install.php', http_build_query(['key' => $key] + $d), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $base = ['site_url' => "http://127.0.0.1:{$this->port}", 'site_name' => 'Мастер', 'admin_user' => 'boss', 'password_mode' => 'generate', 'confirm_url' => '1'];
        $this->assertContains('Подтвердите адрес', $form(array_diff_key($base, ['confirm_url' => 1]))['body']);
        $this->assertContains('at least 10', $form(['password_mode' => 'own', 'admin_password' => 'short'] + $base)['body']);
        $this->assertContains('Username must be', $form(['admin_user' => 'a b'] + $base)['body']);
        $this->assertSame(false, is_file(AGECMS_DATA . '/data.sqlite'), 'failed attempts leave nothing behind');

        $done = $form($base + ['demo' => '1']);
        $this->assertContains('AgeCMS установлена', $done['body']);
        $this->assertContains('cron не требуется', $done['body']);
        $this->assertNotContains('* * * * *', $done['body']);
        preg_match('~id="c-pass">([A-Za-z0-9]{16})</dd>~', $done['body'], $m);
        $this->assertTrue(isset($m[1]), 'generated password is shown once');
        $this->assertSame(false, is_file(AGECMS_DATA . '/install.key'), 'key file is removed');
        $this->assertSame(404, $this->http('GET', "/admin/install.php?key={$key}")['status'], 'closed for good');
        $this->assertSame(404, $form($base)['status']);

        $login = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'boss', 'password' => $m[1]]), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertSame(302, $login['status'], 'the shown password works');
        $this->assertSame(200, $this->http('GET', '/')['status']);
    }

    public function testPasswordRecoveryByEmailOverHttp(): void
    {
        $this->boot();
        \AgeCMS\Config::set('mail', ['transport' => 'log', 'from' => 'noreply@example.test', 'from_name' => '', 'smtp' => ['host' => '', 'port' => 465, 'secure' => 'ssl', 'user' => '', 'pass' => '']]);
        $csrf = $this->adminLogin();
        $this->assertTrue($csrf !== '');
        $adminId = (int)\AgeCMS\Database::value("SELECT id FROM users WHERE username = 'admin'");

        // two roles for people, the address is set through the admin
        $users = $this->http('GET', '/admin/?s=users', null, [], true)['body'];
        $this->assertNotContains('value="viewer"', $users, 'people choose between an administrator and an editor');
        $this->assertContains('Почта для восстановления', $users);
        $this->assertContains('Почта сохранена', $this->form(['action' => 'user_email', 'id' => (string)$adminId, 'email' => 'boss@example.test'], $csrf)['body']);
        $this->assertContains('Настройки почты сохранены', $this->form(['action' => 'mail_save', 'mail_transport' => 'mail', 'mail_from' => 'noreply@example.test', 'smtp_host' => '', 'smtp_port' => '465', 'smtp_secure' => 'ssl'], $csrf)['body']);
        \AgeCMS\Config::set('mail.transport', 'log');
        $this->assertContains('Тестовое письмо отправлено', $this->form(['action' => 'mail_test'], $csrf)['body']);
        foreach (glob(AGECMS_DATA . '/outbox/*.eml') ?: [] as $f) {
            unlink($f);
        }

        // the login page offers recovery and the request answers the same for everyone
        $this->assertContains('Забыли пароль?', $this->http('GET', '/admin/')['body']);
        $this->assertContains('Восстановление пароля', $this->http('GET', '/admin/?s=forgot')['body']);
        $ghost = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'forgot', 'identifier' => 'ghost']), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertContains('Если такой пользователь есть', $ghost['body']);
        $this->assertSame([], glob(AGECMS_DATA . '/outbox/*.eml') ?: [], 'an unknown login gets nothing');
        $real = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'forgot', 'identifier' => 'admin']), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertContains('Если такой пользователь есть', $real['body'], 'the answer does not reveal whether the account exists');
        $files = glob(AGECMS_DATA . '/outbox/*.eml') ?: [];
        $this->assertSame(1, count($files));
        $raw = (string)file_get_contents($files[0]);
        $text = (string)base64_decode(str_replace("\r\n", '', explode("\r\n\r\n", $raw, 2)[1]));
        preg_match('~/admin/\?s=reset&token=([A-Za-z0-9_-]+)~', $text, $m);
        $this->assertTrue(isset($m[1]), 'the mail carries the link');

        $page = $this->http('GET', '/admin/?s=reset&token=' . $m[1]);
        $this->assertSame('no-referrer', $page['headers']['referrer-policy'], 'the secret link is not leaked through Referer');
        $this->assertContains('name="new_password"', $page['body']);
        $this->assertContains('Ссылка не работает', $this->http('GET', '/admin/?s=reset&token=wrong')['body']);
        $form = static fn(array $d): string => http_build_query(['action' => 'reset_password', 'token' => $d['token']] + $d);
        $mismatch = $this->http('POST', '/admin/index.php', $form(['token' => $m[1], 'new_password' => 'brand-new-secret', 'new_password2' => 'other-new-secret']), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertContains('Пароли не совпадают', $mismatch['body']);
        $this->assertContains('name="new_password"', $mismatch['body'], 'the form stays open after a typo');
        $done = $this->http('POST', '/admin/index.php', $form(['token' => $m[1], 'new_password' => 'brand-new-secret', 'new_password2' => 'brand-new-secret']), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(302, $done['status']);
        $this->assertContains('Пароль изменён. Войдите', $this->http('GET', '/admin/?reset=ok')['body']);
        $this->assertContains('Ссылка не работает', $this->http('GET', '/admin/?s=reset&token=' . $m[1])['body'], 'a link works once');

        // the session opened before the reset is ended, the new password works
        $this->assertContains('Вход в админку', $this->http('GET', '/admin/', null, [], true)['body'], 'old sessions are signed out');
        $old = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertSame(200, $old['status'], 'the old password no longer works');
        $new = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'brand-new-secret']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertSame(302, $new['status']);
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function issue(array $fields): array
    {
        $r = $this->http('POST', '/admin/api.php', json_encode($fields), ['Content-Type' => 'application/json']);
        return ['status' => $r['status'], 'json' => json_decode($r['body'], true)];
    }

    public function testInstallButtonsAnswerWithoutRunningAnythingWhenNothingIsMissing(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $page = $this->form(['action' => 'env_install', 'which' => '*'], $csrf);
        $this->assertSame(200, $page['status']);
        $missing = array_filter(\AgeCMS\Installer::checks(), static fn(array $c): bool => !$c['ok'] && \AgeCMS\ServerSetup::installable($c['label']));
        if ($missing === []) {
            $this->assertContains('Нечего ставить', $page['body']);
        }
        $this->assertContains('Требования сервера', $page['body']);
    }

    public function testTheHistoryPageShowsAdminActionsNextToPageEdits(): void
    {
        $this->boot();
        \AgeCMS\Auth::createUser('helper', 'Helper-Passw0rd-1', 'editor');
        \AgeCMS\Audit::record(\AgeCMS\Audit::CORE, 'Обновление ядра: 1.1.1 -> 1.2.0 (файлов: 3)', 'human:admin');
        $this->adminLogin();
        $page = $this->http('GET', '/admin/?s=history', null, [], true);
        $this->assertSame(200, $page['status']);
        $this->assertContains('Обновление ядра: 1.1.1 -&gt; 1.2.0', $page['body']);
        $this->assertContains('Создан пользователь helper', $page['body']);
        $this->assertContains('Ядро', $page['body']);
        $this->assertNotContains('Helper-Passw0rd-1', $page['body']);
    }

    public function testAnAgentIssuesItselfATokenFromTheOwnersLoginAndPassword(): void
    {
        $this->boot();
        $base = "http://127.0.0.1:{$this->port}";

        // the instructions are reachable over the web, carry the real address and name the way in
        $doc = $this->http('GET', '/AGENT.md');
        $this->assertSame(200, $doc['status']);
        $this->assertContains('text/markdown', $doc['headers']['content-type']);
        $this->assertContains($base . '/admin/api.php', $doc['body']);
        $this->assertContains('issue_token', $doc['body']);
        $this->assertNotContains('{{SITE_URL}}', $doc['body']);
        $this->assertContains('/AGENT.md', $this->http('GET', '/llms.txt')['body']);

        // a request without credentials is told where to read
        $anon = $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']));
        $this->assertSame(401, $anon['status']);
        $this->assertContains($base . '/AGENT.md', $anon['body']);
        $this->assertContains('/AGENT.md', $this->http('POST', '/admin/mcp.php', '{}', ['Content-Type' => 'application/json'])['body']);

        // the right pair gives a token that works over REST and MCP
        $ok = $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'claude-agent', 'role' => 'editor']);
        $this->assertSame(200, $ok['status']);
        $token = $ok['json']['result']['token'];
        $this->assertSame('editor', $ok['json']['result']['role']);
        $this->assertContains($base . '/admin/mcp.php', $ok['json']['result']['mcp']);
        $structure = $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
        $this->assertSame(200, $structure['status']);
        $decoded = json_decode($structure['body'], true); $env = ($decoded['result'] ?? $decoded)['environment'] ?? null;
        $this->assertTrue(is_array($env) && isset($env['php'], $env['missing_required'], $env['missing_recommended']), 'the web PHP reports what it lacks');
        $this->assertSame([], $env['missing_required'], 'the test server has what is required');
        $init = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        $this->assertContains($base . '/AGENT.md', $init['json']['result']['instructions']);
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM api_tokens WHERE name = 'claude-agent' AND revoked_at IS NULL"));
        $this->assertSame(false, str_contains((string)Database::value("SELECT token_hash FROM api_tokens WHERE name = 'claude-agent'"), $token), 'only the hash is stored');

        // the default role of an administrator's login is an editor token, never a stronger one by accident
        $dflt = $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'second-agent']);
        $this->assertSame('editor', $dflt['json']['result']['role']);
        $this->assertSame(422, $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'claude-agent'])['status'], 'a name is used once');
        $this->assertSame(422, $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'x'])['status'], 'name too short');

        // a token cannot be stronger than the person behind it
        Auth::createUser('anna', 'Anna-pass-12345', 'editor');
        $this->assertSame(403, $this->issue(['action' => 'issue_token', 'username' => 'anna', 'password' => 'Anna-pass-12345', 'name' => 'anna-admin', 'role' => 'admin'])['status'], 'role above the user');
        $annaToken = $this->issue(['action' => 'issue_token', 'username' => 'anna', 'password' => 'Anna-pass-12345', 'name' => 'anna-agent'])['json']['result'];
        $this->assertSame('editor', $annaToken['role']);

        // the owner revokes it and it stops working
        Auth::revokeToken('claude-agent');
        $this->assertSame(401, $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'])['status']);

        // the owner can switch self-service off
        // switched in the admin, in the server's own process, as the owner would do it
        $csrf = $this->adminLogin();
        $this->form(['action' => 'agent_access_save', 'token_days' => '90'], $csrf);
        $off = $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'late-agent']);
        $this->assertSame(403, $off['status'], 'switched off');
        $this->assertContains('self_issue_disabled', $off['json']['error']);
        $this->form(['action' => 'agent_access_save', 'self_issue' => '1', 'token_days' => '90'], $csrf);

        // wrong passwords are counted like failed logins, and then even the right one is refused
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(422, $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'bad' . $i, 'name' => 'guess-agent'])['status'], 'locked after five misses');
        }
        $this->assertSame(403, $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'guess-agent'])['status']);
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM api_tokens WHERE name IN ('guess-agent','late-agent')"));
    }

    public function testTheSystemPageExplainsSelfServiceAndSavesTheSwitch(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $page = $this->http('GET', '/admin/?s=system', null, [], true)['body'];
        $this->assertContains('Подключить ИИ-агента', $page);
        $this->assertContains('Правила для агентов', $page);
        $this->assertContains('/AGENT.md', $page);

        $off = $this->form(['action' => 'agent_access_save', 'token_days' => '90', 'before_agent' => '1'], $csrf);
        $this->assertContains('Настройки доступа для агентов сохранены', $off['body']);
        \AgeCMS\Config::reset();
        $this->assertSame(false, \AgeCMS\Config::get('agent_access.self_issue'));
        $this->form(['action' => 'agent_access_save', 'self_issue' => '1', 'token_days' => '90', 'before_agent' => '1'], $csrf);
        \AgeCMS\Config::reset();
        $this->assertSame(true, \AgeCMS\Config::get('agent_access.self_issue'));
        $this->assertContains('/AGENT.md', $this->http('GET', '/admin/', null, [], false)['body'], 'the login page points agents to the instructions');
    }

    public function testInvitationCodeGivesAnAgentATokenOnceAndWithAnExpiry(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $made = $this->form(['action' => 'invite_create', 'role' => 'editor', 'token_days' => '30', 'note' => 'seo'], $csrf)['body'];
        $this->assertContains('Код подключения: agi_', $made, 'the owner gets a ready text for the chat');
        $this->assertContains('/AGENT.md', $made);
        preg_match('/agi_[A-Za-z0-9_-]+/', $made, $m);
        $code = $m[0] ?? '';
        $this->assertSame(false, str_contains((string)Database::value('SELECT code_hash FROM agent_invites LIMIT 1'), $code), 'only the hash of the code is stored');

        $this->assertSame(422, $this->issue(['action' => 'redeem_invite', 'code' => 'agi_wrong', 'name' => 'inv-agent'])['status'], 'a wrong code');
        $ok = $this->issue(['action' => 'redeem_invite', 'code' => $code, 'name' => 'inv-agent']);
        $this->assertSame(200, $ok['status']);
        $token = $ok['json']['result']['token'];
        $this->assertSame('editor', $ok['json']['result']['role']);
        $days = (strtotime($ok['json']['result']['expires_at']) - time()) / 86400;
        $this->assertTrue($days > 29 && $days < 31, 'the owner chose 30 days');
        $this->assertSame(422, $this->issue(['action' => 'redeem_invite', 'code' => $code, 'name' => 'inv-agent-2'])['status'], 'the code works once');

        $structure = $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
        $this->assertSame(200, $structure['status']);
        $info = json_decode($structure['body'], true)['result']['token'];
        $this->assertSame('inv-agent', $info['name']);
        $this->assertTrue($info['days_left'] >= 29);

        // an expired invitation is refused
        $code2 = Auth::createInvite('editor', 0, '', 'admin');
        Database::write(static fn(PDO $pdo) => $pdo->exec('UPDATE agent_invites SET expires_at = ' . (time() - 10) . ' WHERE used_at IS NULL'));
        $this->assertSame(422, $this->issue(['action' => 'redeem_invite', 'code' => $code2, 'name' => 'late-agent'])['status']);

        // an expired token says so
        Database::write(static fn(PDO $pdo) => $pdo->exec("UPDATE api_tokens SET expires_at = '2020-01-01T00:00:00Z' WHERE name = 'inv-agent'"));
        $dead = $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
        $this->assertSame(401, $dead['status']);
        $this->assertContains('expired', $dead['body']);

        // wrong codes are counted
        for ($i = 0; $i < 10; $i++) {
            $this->issue(['action' => 'redeem_invite', 'code' => 'agi_guess' . $i, 'name' => 'g' . $i . 'x']);
        }
        $fresh = Auth::createInvite('editor', 0, '', 'admin');
        $this->assertSame(403, $this->issue(['action' => 'redeem_invite', 'code' => $fresh, 'name' => 'after-guess'])['status'], 'the address is locked after ten misses');
    }

    public function testAnAgentWithTheOwnersLoginGetsAUserOfItsOwn(): void
    {
        $this->boot();
        $res = $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'claude-seo']);
        $this->assertSame(200, $res['status']);
        $this->assertSame('agent-claude-seo', $res['json']['result']['user'], 'a separate user named after the agent');
        $token = $res['json']['result']['token'];
        $row = Database::row("SELECT id, role FROM users WHERE username = 'agent-claude-seo'");
        $this->assertSame('editor', $row['role']);
        $this->assertSame((int)$row['id'], (int)Database::value("SELECT user_id FROM api_tokens WHERE name = 'claude-seo'"), 'the token belongs to the agent user, not to the owner');
        $this->assertSame(null, Auth::checkCredentials('agent-claude-seo', 'guess-guess-guess'), 'nobody knows the agent user password');

        // the owner stays out of it: blocking the agent user stops the token, the owner still signs in
        $csrf = $this->adminLogin();
        $this->assertTrue($csrf !== '', 'the owner is not affected');
        $call = fn() => $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
        $this->assertSame(200, $call()['status']);
        $this->form(['action' => 'user_block', 'id' => (string)$row['id'], 'blocked' => '1'], $csrf);
        $this->assertSame(401, $call()['status']);
        $this->assertContains('agent-claude-seo', $this->http('GET', '/admin/?s=users', null, [], true)['body'], 'it is visible in the users list');

        // a second agent with the same stem gets a numbered login
        $second = $this->issue(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'claude-seo-2', 'role' => 'viewer']);
        $this->assertSame('agent-claude-seo-2', $second['json']['result']['user']);
        $this->assertSame('viewer', Database::value("SELECT role FROM users WHERE username = 'agent-claude-seo-2'"));
    }

    public function testAgentsFindTheInstructionsFromAnyPageAndTheOwnerSeesExpiringTokens(): void
    {
        $this->boot();
        $base = "http://127.0.0.1:{$this->port}";
        $home = $this->http('GET', '/')['body'];
        $this->assertContains('<meta name="generator" content="AgeCMS">', $home);
        $this->assertContains('<link rel="help" type="text/markdown" href="' . $base . '/AGENT.md"', $home, 'a public page points to the instructions');
        $this->assertContains($base . '/AGENT.md', $this->http('GET', '/robots.txt')['body']);

        $token = Auth::createToken('soon-agent', 'editor', 3);
        $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'get_site_structure', 'params' => []]);
        Auth::createToken('far-agent', 'editor', 60);
        $this->adminLogin();
        $page = $this->http('GET', '/admin/?s=overview', null, [], true)['body'];
        $this->assertContains('Скоро заканчивается токен агента', $page);
        $this->assertContains('soon-agent', $page);
        $this->assertNotContains('far-agent (', $page, 'a token with plenty of time left is not mentioned');
    }

    public function testBlockingAUserStopsTheLoginAndTheTokensOfThatUser(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $made = $this->form(['action' => 'agent_user_create'], $csrf)['body'];
        $this->assertContains('Логин: agent-', $made);
        preg_match('/Логин: (agent-[a-z0-9]+)/', $made, $l);
        preg_match('/Пароль: (\S+)/', $made, $p);
        $login = $l[1];
        $got = $this->issue(['action' => 'issue_token', 'username' => $login, 'password' => html_entity_decode($p[1]), 'name' => 'user-agent', 'own_user' => false]);
        $this->assertSame(200, $got['status']);
        $token = $got['json']['result']['token'];
        $call = fn() => $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
        $this->assertSame(200, $call()['status']);

        $id = (int)Database::value('SELECT id FROM users WHERE username = ?', [$login]);
        $this->form(['action' => 'user_block', 'id' => (string)$id, 'blocked' => '1'], $csrf);
        $r = $call();
        $this->assertSame(401, $r['status']);
        $this->assertContains('blocked', $r['body']);
        $this->assertSame(403, $this->issue(['action' => 'issue_token', 'username' => $login, 'password' => html_entity_decode($p[1]), 'name' => 'other-agent'])['status']);

        $this->form(['action' => 'user_block', 'id' => (string)$id, 'blocked' => '0'], $csrf);
        $this->assertSame(200, $call()['status'], 'unblocked, the token works again');

        // the last active administrator cannot be blocked
        $adminId = (int)Database::value("SELECT id FROM users WHERE username = 'admin'");
        $self = $this->form(['action' => 'user_block', 'id' => (string)$adminId, 'blocked' => '1'], $csrf)['body'];
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM users WHERE blocked_at IS NOT NULL AND id = ?', [$adminId]));
        $this->assertContains('role="alert"', $self);
    }

    public function testAnAgentEditCreatesASafetyCopyAndLeadsAreMailedFromNoreply(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $token = Auth::createToken('copy-agent', 'editor', 30);
        $before = count(glob(AGECMS_DATA . '/backups/agecms-auto-*') ?: []);
        $r = $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'create_page', 'params' => ['slug' => 'copy-test', 'fields' => ['title' => 'Копия']]]);
        $this->assertSame(200, $r['status']);
        clearstatcache();
        $this->assertSame($before + 1, count(glob(AGECMS_DATA . '/backups/agecms-auto-*') ?: []), 'a copy is made before the first agent edit');
        $this->rpc($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'create_page', 'params' => ['slug' => 'copy-test-2', 'fields' => ['title' => 'Копия 2']]]);
        clearstatcache();
        $this->assertSame($before + 1, count(glob(AGECMS_DATA . '/backups/agecms-auto-*') ?: []), 'one copy per session, not per edit');
        // a page field next to the slug is refused, not silently dropped (it once published a page meant to stay hidden)
        $bad = $this->http('POST', '/admin/api.php', json_encode(['action' => 'create_page', 'slug' => 'misplaced', 'is_published' => false, 'fields' => ['title' => 'X']]), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
        $this->assertSame(422, $bad['status']);
        $this->assertContains("inside 'fields'", $bad['body']);
        $this->assertSame(null, Database::row("SELECT slug FROM pages WHERE slug = 'misplaced'"), 'nothing was created');
        $ok = $this->http('POST', '/admin/api.php', json_encode(['action' => 'create_page', 'slug' => 'hidden-one', 'fields' => ['title' => 'Скрытая', 'is_published' => false, 'blocks' => [['id' => 'intro', 'type' => 'text', 'data' => ['heading' => 'Скрытая', 'body' => 'Текст.']]]]]), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $this->assertSame(0, (int)Database::value("SELECT is_published FROM pages WHERE slug = 'hidden-one'"));
        $this->assertSame(404, $this->http('GET', '/hidden-one')['status'], 'a hidden page is not public');
        $page = $this->http('GET', '/admin/?s=system', null, [], true)['body'];
        $this->assertContains('перед правкой агента', $page);

        $settings = $this->http('GET', '/admin/?s=settings', null, [], true)['body'];
        $this->assertContains('noreply@127.0.0.1', $settings, 'the sender comes from the site address');
        $this->assertContains('Присылать новые заявки на почту', $settings);
    }

    public function testSavingRebuildsTheCacheAndLeftoversAreFinishedOnVisits(): void
    {
        $this->boot();
        $editor = Auth::createToken('cache-bot', 'editor');
        $this->http('GET', '/about');
        $this->assertTrue(is_file(AGECMS_CACHE . '/about.html'));

        // a save through the API: the changed page is cached again right after the answer, without a worker
        $args = ['name' => 'update_block_field', 'arguments' => ['slug' => 'about', 'block_id' => 'text_1', 'field_path' => 'heading', 'value' => 'Сразу в кэше']];
        $this->assertSame(false, $this->rpc($editor, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $args])['json']['result']['isError']);
        $this->assertTrue(is_file(AGECMS_CACHE . '/about.html'), 'cached again by the save');
        $this->assertContains('Сразу в кэше', (string)file_get_contents(AGECMS_CACHE . '/about.html'));
        $this->assertSame([], glob(AGECMS_DATA . '/queue/rebuild/*.json') ?: [], 'nothing left to rebuild');

        // work left behind (a crash, a failed ping) is picked up by an ordinary visit that reaches PHP
        \AgeCMS\Cache::invalidate(['all']);
        $this->assertSame(false, is_file(AGECMS_CACHE . '/about.html'));
        @unlink(AGECMS_DATA . '/queue/.tick');
        $this->http('GET', '/no-such-page');
        $this->assertSame([], glob(AGECMS_DATA . '/queue/rebuild/*.json') ?: [], 'a visit finished the leftovers');
        $this->assertTrue(is_file(AGECMS_CACHE . '/about.html'), 'about was re-rendered in the background');
    }

    public function testNewPageGetsItsAddressFromTheTitle(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $made = $this->form(['action' => 'page_create', 'slug' => '', 'title' => 'Ремонт квартир под ключ', 'schema_type' => 'Service'], $csrf);
        $this->assertSame(302, $made['status']);
        $this->assertContains('slug=remont-kvartir-pod-klyuch', $made['headers']['location']);
        $again = $this->form(['action' => 'page_create', 'slug' => '', 'title' => 'Ремонт квартир под ключ', 'schema_type' => 'Service'], $csrf);
        $this->assertContains('slug=remont-kvartir-pod-klyuch-2', $again['headers']['location'], 'a taken address gets a number');
        $child = $this->form(['action' => 'page_create', 'slug' => '', 'title' => 'Цены', 'parent_slug' => 'remont-kvartir-pod-klyuch', 'schema_type' => 'WebPage'], $csrf);
        $this->assertContains('slug=remont-kvartir-pod-klyuch%2Ftseny', $child['headers']['location'], 'under the parent page');
        $own = $this->form(['action' => 'page_create', 'slug' => 'moy-adres', 'title' => 'Что-то', 'schema_type' => 'WebPage'], $csrf);
        $this->assertContains('slug=moy-adres', $own['headers']['location'], 'a typed address wins');
        $form = $this->http('GET', '/admin/?s=pages', null, [], true)['body'];
        $this->assertContains('data-slug-from', $form);
        $this->assertContains('необязательно', $form);
    }

    public function testMovedPageRedirectsAndTheAdminCanChangeAnAddress(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $this->assertSame(200, $this->http('GET', '/about')['status']);
        $this->assertTrue(is_file(AGECMS_CACHE . '/about.html'));
        $this->assertContains('Изменить адрес страницы', $this->http('GET', '/admin/?s=edit&slug=about', null, [], true)['body']);
        $this->assertNotContains('Изменить адрес страницы', $this->http('GET', '/admin/?s=edit&slug=index', null, [], true)['body'], 'the home page keeps its address');

        $r = $this->form(['action' => 'page_rename', 'slug' => 'about', 'new_slug' => 'o-kompanii'], $csrf);
        $this->assertSame(302, $r['status']);
        $this->assertContains('slug=o-kompanii', $r['headers']['location']);
        $this->assertContains('Адрес изменён', $this->http('GET', '/admin/?s=edit&slug=o-kompanii&renamed=about', null, [], true)['body']);

        $old = $this->http('GET', '/about?utm=1');
        $this->assertSame(301, $old['status']);
        $this->assertSame('/o-kompanii?utm=1', $old['headers']['location'], 'the redirect keeps the query');
        clearstatcache();
        $this->assertSame(false, is_file(AGECMS_CACHE . '/about.html'), 'no stale copy of the old address is left for the web server');
        $this->assertSame(200, $this->http('GET', '/o-kompanii')['status']);
        $sitemap = $this->http('GET', '/sitemap.xml')['body'];
        $this->assertContains('/o-kompanii', $sitemap);
        $this->assertNotContains('/about<', $sitemap);

        $bad = $this->form(['action' => 'page_rename', 'slug' => 'o-kompanii', 'new_slug' => 'index'], $csrf);
        $this->assertSame(200, $bad['status']);
        $this->assertSame(200, $this->http('GET', '/o-kompanii')['status'], 'a refused change leaves the page where it was');

        $editor = Auth::createToken('rename-bot', 'editor');
        $tool = $this->rpc($editor, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'rename_page', 'arguments' => ['slug' => 'o-kompanii', 'new_slug' => 'about-us']]]);
        $this->assertSame(false, $tool['json']['result']['isError']);
        $this->assertSame('/about-us', $this->http('GET', '/about')['headers']['location']);
        $this->assertSame('/about-us', $this->http('GET', '/o-kompanii')['headers']['location']);
    }

    public function testAdminDeletePagesMediaAndUsers(): void
    {
        $this->boot();
        $csrf = $this->adminLogin();
        $this->assertTrue($csrf !== '');

        // delete and restore a page from the admin
        $this->form(['action' => 'page_create', 'slug' => 'temp-page', 'title' => 'Temp', 'schema_type' => 'WebPage'], $csrf);
        $del = $this->form(['action' => 'page_delete', 'slug' => 'temp-page'], $csrf);
        $this->assertContains('в корзину', $del['body']);
        $trash = $this->http('GET', '/admin/?s=trash', null, [], true)['body'];
        $this->assertContains('/temp-page', $trash);
        $this->assertContains('Восстановить', $trash);
        $this->assertContains('Удалить навсегда', $trash, 'an administrator can purge');
        $restored = $this->form(['action' => 'page_restore', 'slug' => 'temp-page'], $csrf);
        $this->assertSame(302, $restored['status']);
        $this->assertContains('restored=1', $restored['headers']['location']);
        $this->assertContains('Страница восстановлена', $this->http('GET', '/admin/?s=edit&slug=temp-page&restored=1', null, [], true)['body']);
        $this->assertContains('Удалить страницу', $this->http('GET', '/admin/?s=edit&slug=temp-page', null, [], true)['body']);
        $this->assertNotContains('ag-delete-form', $this->http('GET', '/admin/?s=edit&slug=index', null, [], true)['body'], 'the home page has no delete button');
        $this->assertContains('home page cannot be deleted', $this->form(['action' => 'page_delete', 'slug' => 'index'], $csrf)['body'], 'home page deletion is refused');

        // image upload, media page, use in a block, delete protection
        if (\AgeCMS\Media::available()) {
            $png = TEST_TMP . '/up.png';
            $im = imagecreatetruecolor(64, 48);
            imagepng($im, $png);
            $upload = static function (string $url, ?string $csrf, string $file): array {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => TEST_TMP . '/cookies.txt', CURLOPT_COOKIEJAR => TEST_TMP . '/cookies.txt',
                    CURLOPT_HTTPHEADER => $csrf !== null ? ['X-CSRF-Token: ' . $csrf] : [], CURLOPT_POSTFIELDS => ['file' => new CURLFile($file, 'image/png', 'up.png')]]);
                $body = (string)curl_exec($ch);
                return ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode($body, true)];
            };
            $base = "http://127.0.0.1:{$this->port}/admin/upload.php";
            $this->assertSame(403, $upload($base, 'wrong-token', $png)['status'], 'upload needs the CSRF token');
            $ok = $upload($base, $csrf, $png);
            $this->assertSame(200, $ok['status']);
            $path = $ok['json']['result']['path'];
            $this->assertSame(1, preg_match('~^/assets/uploads/[a-f0-9]{20}\\.webp$~', $path));
            $this->assertSame(200, $this->http('GET', $path)['status'], 'the image is served');
            $notImage = TEST_TMP . '/fake.png';
            file_put_contents($notImage, '<?php echo 1;');
            $this->assertSame(422, $upload($base, $csrf, $notImage)['status'], 'only real images are accepted');

            $media = $this->http('GET', '/admin/?s=media', null, [], true)['body'];
            $this->assertContains($path, $media);
            $this->assertContains('data-upload', $media);

            $set = $this->http('POST', '/admin/api.php', json_encode(['action' => 'update_page', 'slug' => 'temp-page', 'fields' => ['blocks' => [['id' => 'image_1', 'type' => 'image', 'data' => ['src' => $path, 'alt' => 'Картинка', 'caption' => 'Подпись']]]]]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf], true);
            $this->assertSame(200, $set['status']);
            $this->http('POST', '/admin/api.php', json_encode(['action' => 'update_page', 'slug' => 'temp-page', 'fields' => ['is_published' => 1]]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf], true);
            $public = $this->http('GET', '/temp-page')['body'];
            $this->assertContains('<img src="' . $path . '" alt="Картинка"', $public);
            $this->assertContains('Подпись', $public);

            $blocked = $this->form(['action' => 'media_delete', 'name' => basename($path)], $csrf);
            $this->assertContains('/temp-page', $blocked['body'], 'a used image cannot be deleted');
            $this->assertSame(200, $this->http('GET', $path)['status']);
            $this->http('POST', '/admin/api.php', json_encode(['action' => 'update_page', 'slug' => 'temp-page', 'fields' => ['blocks' => []]]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf], true);
            $this->assertContains('в корзину', $this->form(['action' => 'media_delete', 'name' => basename($path)], $csrf)['body']);
            $this->assertSame(404, $this->http('GET', $path)['status']);
            $preview = $this->http('GET', '/admin/upload.php?trash=' . basename($path), null, [], true);
            $this->assertSame(200, $preview['status'], 'the trash preview is served to the signed-in admin');
            $this->assertSame('image/webp', $preview['headers']['content-type']);
            $this->assertSame(401, $this->http('GET', '/admin/upload.php?trash=' . basename($path))['status'], 'and to nobody else');
            $this->assertContains('Картинка возвращена', $this->form(['action' => 'media_restore', 'name' => basename($path)], $csrf)['body']);
            $this->assertSame(200, $this->http('GET', $path)['status']);
            $this->form(['action' => 'media_delete', 'name' => basename($path)], $csrf);
            $this->assertContains('удалена навсегда', $this->form(['action' => 'media_purge', 'name' => basename($path)], $csrf)['body']);
            $this->assertSame(404, $this->http('GET', '/admin/upload.php?trash=' . basename($path), null, [], true)['status']);
        }

        // users
        $users = $this->http('GET', '/admin/?s=users', null, [], true)['body'];
        $this->assertContains('Новый пользователь', $users);
        $created = $this->form(['action' => 'user_create', 'username' => 'anna', 'role' => 'editor', 'password_mode' => 'generate'], $csrf);
        $this->assertContains('Пользователь anna создан', $created['body']);
        preg_match('~id="new-password">([A-Za-z0-9]{16})<~', $created['body'], $pm);
        $this->assertTrue(isset($pm[1]), 'a generated password is shown once');
        $this->assertNotContains($pm[1], $this->http('GET', '/admin/?s=users', null, [], true)['body']);
        $this->assertContains('at least 10', $this->form(['action' => 'user_create', 'username' => 'bob', 'role' => 'editor', 'password_mode' => 'own', 'password' => 'short'], $csrf)['body']);
        $annaId = (int)\AgeCMS\Database::value("SELECT id FROM users WHERE username = 'anna'");
        $this->assertContains('Роль изменена', $this->form(['action' => 'user_role', 'id' => (string)$annaId, 'role' => 'viewer'], $csrf)['body']);
        $this->assertContains('Новый пароль для anna', $this->form(['action' => 'user_reset', 'id' => (string)$annaId], $csrf)['body']);
        $adminId = (int)\AgeCMS\Database::value("SELECT id FROM users WHERE username = 'admin'");
        $this->assertContains('own account', $this->form(['action' => 'user_delete', 'id' => (string)$adminId], $csrf)['body']);
        $this->assertContains('не совпадают', $this->form(['action' => 'password_change', 'current_password' => 'Passw0rd-test-1', 'new_password' => 'another-long-pw', 'new_password2' => 'different-long-pw'], $csrf)['body']);
        $this->assertContains('Пароль изменён', $this->form(['action' => 'password_change', 'current_password' => 'Passw0rd-test-1', 'new_password' => 'another-long-pw', 'new_password2' => 'another-long-pw'], $csrf)['body']);
        $this->assertContains('Пользователь удалён', $this->form(['action' => 'user_delete', 'id' => (string)$annaId], $csrf)['body']);
    }
}
