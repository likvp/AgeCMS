<?php
declare(strict_types=1);

use AgeCMS\{Auth, Database};

/** Starts the real site on `php -S` against the test sandbox and talks to it over HTTP. */
final class HttpIntegrationTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    private int $port = 0;
    private string $jar = '';

    public function __destruct()
    {
        $this->stop();
    }

    private function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
    }

    private function boot(array $options = []): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);

        $this->freshSite($options + ['site_url' => "http://127.0.0.1:{$this->port}", 'demo' => true]);
        $this->jar = TEST_TMP . '/cookies.txt';

        $root = dirname(__DIR__);
        $env = [
            'AGECMS_DATA_DIR' => AGECMS_DATA, 'AGECMS_CACHE_DIR' => AGECMS_CACHE,
            'AGECMS_MODULES_DIR' => AGECMS_MODULES, 'AGECMS_UPLOADS_DIR' => AGECMS_UPLOADS,
            'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => (string)getenv('PATH'),
        ];
        $this->proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $root, $root . '/tests/support/router.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $root, $env);
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1)) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('php -S did not start');
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function http(string $method, string $path, ?string $body = null, array $headers = [], bool $cookies = false): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $out = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$out): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $out[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($cookies) {
            curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar]);
        }
        $text = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'headers' => $out, 'body' => $text];
    }

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

        $page = $this->http('GET', '/', null, [], true);
        $this->assertContains('data-ag-field="h1"', $page['body'], 'editing markup for signed-in users');
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
        $this->assertSame('miss', $anon['headers']['x-agecms-cache'], 'the edit dropped the cached copy');
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
}
