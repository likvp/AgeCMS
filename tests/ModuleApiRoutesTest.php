<?php
declare(strict_types=1);

use AgeCMS\{Security, SkillRegistry};

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

/**
 * Addresses for programs (E3): "api": true. No form token, headers the manifest names, a JSON body, no cookies,
 * and every answer of the core, errors included, is JSON.
 */
final class ModuleApiRoutesTest extends HttpCase
{
    private const JSON = ['Content-Type' => 'application/json'];

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

    /** @return list<array<string,mixed>> */
    private function routes(string $name, array $extra = []): array
    {
        return [
            ['path' => "/api/{$name}/echo", 'methods' => ['GET', 'POST'], 'api' => true, 'headers' => ['authorization', 'x-hook']] + $extra,
            ['path' => "/api/{$name}/cookie", 'methods' => ['GET'], 'api' => true],
            ['path' => "/api/{$name}/html", 'methods' => ['GET'], 'api' => true],
            ['path' => "/api/{$name}/go", 'methods' => ['GET'], 'api' => true],
            ['path' => "/api/{$name}/boom", 'methods' => ['GET'], 'api' => true],
            ['path' => "/api/{$name}/gone", 'methods' => ['GET'], 'api' => true],
            ['path' => "/api/{$name}/hook", 'methods' => ['POST'], 'api' => true],
            ['path' => "/{$name}/form", 'methods' => ['GET', 'POST']],
        ];
    }

    private function json(array $reply): array
    {
        $this->assertContains('application/json', $reply['headers']['content-type'] ?? '', 'JSON: ' . substr($reply['body'], 0, 120));
        $data = json_decode($reply['body'], true);
        $this->assertTrue(is_array($data), 'a JSON document: ' . substr($reply['body'], 0, 120));
        return $data;
    }

    // 1. The body reaches the module without a form token, the same post on an ordinary address is refused.

    public function testAPostWithoutAFormTokenReachesTheModule(): void
    {
        $this->boot();
        ModuleFixture::make('api_ok', $this->routes('api_ok'));

        $r = $this->http('POST', '/api/api_ok/echo?x=1', json_encode(['update_id' => 7, 'message' => ['text' => 'hi'], 'token' => 'mine', 'website_hp' => 'kept']), self::JSON);
        $this->assertSame(200, $r['status'], $r['body']);
        $d = $this->json($r);
        $this->assertSame('POST', $d['method']);
        $this->assertSame(['update_id' => 7, 'message' => ['text' => 'hi'], 'token' => 'mine', 'website_hp' => 'kept'], $d['body'], 'the fields are the module\'s own, nothing is taken out');
        $this->assertSame('private, no-store', $r['headers']['cache-control']);
        $this->assertSame(false, isset($r['headers']['set-cookie']), 'no cookie');
        $this->assertSame('nosniff', $r['headers']['x-content-type-options']);

        $this->assertSame(400, $this->http('POST', '/api_ok/form', json_encode(['v' => 'a']), self::JSON)['status'], 'the same post on an ordinary address');
        $this->assertSame(200, $this->http('POST', '/api_ok/form', json_encode(['v' => 'a', 'token' => $this->aged()]), self::JSON)['status'], 'an ordinary address is unchanged');

        $get = $this->http('GET', '/api/api_ok/echo?x=1');
        $this->assertSame(200, $get['status']);
        $this->assertSame(['x' => '1'], $this->json($get)['query']);
        $form = $this->http('POST', '/api/api_ok/echo', http_build_query(['a' => '1']), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(['a' => '1'], $this->json($form)['body'], 'a form post is read as PHP reads it');
    }

    // 2. Origin, headers, cookies.

    public function testOriginHeadersAndCookies(): void
    {
        $this->boot();
        ModuleFixture::make('api_hd', $this->routes('api_hd'));
        $post = fn(array $headers) => $this->http('POST', '/api/api_hd/echo', '{}', self::JSON + $headers);

        $foreign = $post(['Origin' => 'https://evil.example']);
        $this->assertSame(403, $foreign['status']);
        $this->assertSame(['ok' => false, 'error' => 'forbidden'], $this->json($foreign));
        $this->assertSame(403, $post(['Sec-Fetch-Site' => 'cross-site'])['status']);
        $this->assertSame(200, $post(['Origin' => "http://127.0.0.1:{$this->port}"])['status'], 'the own origin passes');
        $this->assertSame(false, isset($foreign['headers']['access-control-allow-origin']), 'the core adds no CORS');

        $d = $this->json($post(['Authorization' => 'Bearer abc.def', 'X-Hook' => 'secret-1', 'X-Other' => 'nope', 'Cookie' => 'api_hd_sid=1; agecms_edit=1']));
        $this->assertSame('Bearer abc.def', $d['auth'], 'a listed header');
        $this->assertSame('secret-1', $d['hook']);
        $this->assertSame('', $d['other'], 'not in the list');
        $this->assertSame('', $d['cookie'], 'cookie is never handed over');
        $this->assertSame([], $d['cookies'], 'neither as cookies of the module');

        $none = $this->json($post([]));
        $this->assertSame(['', ''], [$none['auth'], $none['hook']], 'no header sent, an empty string');
    }

    public function testACookieOrAPageFromAnApiAddressIsAServerError(): void
    {
        $this->boot();
        ModuleFixture::make('api_bd', $this->routes('api_bd'));
        foreach (['cookie' => 'an api route cannot set cookies', 'html' => 'an api route answers only with JSON', 'go' => 'an api route answers only with JSON'] as $path => $why) {
            $r = $this->http('GET', "/api/api_bd/{$path}");
            $this->assertSame(500, $r['status'], $path);
            $this->assertSame(['ok' => false, 'error' => 'server_error'], $this->json($r), $path);
            $this->assertSame(false, isset($r['headers']['set-cookie']), $path);
            $this->assertContains($why, $this->log(), $path);
        }
        $this->assertContains('module.route_failed', $this->log());
    }

    public function testAnExceptionIsAJsonErrorAndTheDetailsStayInTheLog(): void
    {
        $this->boot();
        ModuleFixture::make('api_ex', $this->routes('api_ex'));
        $r = $this->http('GET', '/api/api_ex/boom');
        $this->assertSame(500, $r['status']);
        $this->assertSame(['ok' => false, 'error' => 'server_error'], $this->json($r));
        $this->assertNotContains('secret trace detail', $r['body']);
        $this->assertContains('secret trace detail', $this->log());

        $gone = $this->http('GET', '/api/api_ex/gone');
        $this->assertSame(404, $gone['status']);
        $this->assertSame(['ok' => false, 'error' => 'not_found'], $this->json($gone));
    }

    // 3. Limits and methods.

    public function testTheRequestLimitAnswersInJson(): void
    {
        $this->boot();
        ModuleFixture::make('api_rt', $this->routes('api_rt', ['rate' => ['limit' => 2, 'window' => 30]]));
        $this->assertSame(200, $this->http('POST', '/api/api_rt/echo', '{}', self::JSON)['status']);
        $this->assertSame(200, $this->http('POST', '/api/api_rt/echo', '{}', self::JSON)['status']);
        $r = $this->http('POST', '/api/api_rt/echo', '{}', self::JSON);
        $this->assertSame(429, $r['status']);
        $this->assertSame(['ok' => false, 'error' => 'rate_limited'], $this->json($r));
        $this->assertSame('30', $r['headers']['retry-after']);
    }

    public function testTheDefaultRateOfAnApiAddressIsTheSameAsOfAnOrdinaryOne(): void
    {
        $this->freshSite();
        ModuleFixture::make('api_dr', [['path' => '/api/api_dr/a', 'methods' => ['POST'], 'api' => true], ['path' => '/api/api_dr/b', 'api' => true]]);
        SkillRegistry::reset();
        $rates = array_column(\AgeCMS\ModuleRoutes::all(), 'rate', 'path');
        $this->assertSame(['limit' => 10, 'window' => 60], $rates['/api/api_dr/a']);
        $this->assertSame(['limit' => 120, 'window' => 60], $rates['/api/api_dr/b']);
    }

    public function testAWrongMethodIsAJson405(): void
    {
        $this->boot();
        ModuleFixture::make('api_mt', $this->routes('api_mt'));
        $get = $this->http('GET', '/api/api_mt/hook');
        $this->assertSame(405, $get['status']);
        $this->assertSame('POST', $get['headers']['allow']);
        $this->assertSame(['ok' => false, 'error' => 'method_not_allowed'], $this->json($get));
        $post = $this->http('POST', '/api/api_mt/boom', '{}', self::JSON);
        $this->assertSame(405, $post['status']);
        $this->assertSame('method_not_allowed', $this->json($post)['error']);
        $this->assertSame(405, $this->http('POST', '/api_mt/hello', '{}', self::JSON)['status'], 'an ordinary address keeps its plain 405');
        $this->assertContains('Method not allowed', $this->http('POST', '/api_mt/hello', '{}', self::JSON)['body']);
    }

    // 4. The body.

    public function testTheBodyIsJsonOrAnEmptyArrayAndTheLimitIsKept(): void
    {
        $this->boot();
        ModuleFixture::make('api_bo', $this->routes('api_bo', ['max_body_kb' => 8]));
        $body = fn(string $raw, array $h = self::JSON) => $this->json($this->http('POST', '/api/api_bo/echo', $raw, $h))['body'];
        $this->assertSame([], $body('{broken'), 'broken JSON');
        $this->assertSame([], $body('plain text', ['Content-Type' => 'text/plain']), 'not JSON');
        $this->assertSame([], $body('"just a string"'), 'a JSON value that is not an object or a list');
        $this->assertSame([], $body(''), 'no body');
        $this->assertSame([1, 2], $body('[1,2]'), 'a list');
        $this->assertSame(['a' => 1], $body('{"a":1}', ['Content-Type' => 'text/plain']), 'JSON is JSON whatever the type says');

        $big = json_encode(['pad' => str_repeat('x', 9000)]);
        $r = $this->http('POST', '/api/api_bo/echo', $big, self::JSON);
        $this->assertSame(413, $r['status'], 'over max_body_kb of the route');
        $this->assertSame(['ok' => false, 'error' => 'payload_too_large'], $this->json($r));
        $ok = $this->http('POST', '/api/api_bo/echo', json_encode(['pad' => str_repeat('x', 7000)]), self::JSON);
        $this->assertSame(200, $ok['status']);

        ModuleFixture::make('api_df', $this->routes('api_df'));
        $this->assertSame(200, $this->http('POST', '/api/api_df/echo', json_encode(['pad' => str_repeat('x', 200000)]), self::JSON)['status'], '256 KB by default');
        $this->assertSame(413, $this->http('POST', '/api/api_df/echo', json_encode(['pad' => str_repeat('x', 270000)]), self::JSON)['status']);
    }

    // 5. The manifest.

    public function testWrongApiDeclarationsBlockTheModule(): void
    {
        $this->freshSite();
        $cases = [
            'api_ix' => [['path' => '/api/api-ix', 'api' => true, 'cache' => 'public', 'indexable' => true], 'never public or indexable'],
            'api_pb' => [['path' => '/api/api-pb', 'api' => true, 'cache' => 'public'], 'never public or indexable'],
            'api_nb' => [['path' => '/api/api-nb', 'api' => 'yes'], 'api is true or false'],
            'api_up' => [['path' => '/api/api-up', 'api' => true, 'headers' => ['Authorization']], 'bad header name'],
            'api_ck' => [['path' => '/api/api-ck', 'api' => true, 'headers' => ['cookie']], 'cookie, host'],
            'api_ho' => [['path' => '/api/api-ho', 'api' => true, 'headers' => ['host']], 'cookie, host'],
            'api_xf' => [['path' => '/api/api-xf', 'api' => true, 'headers' => ['x-csrf-token']], 'cookie, host'],
            'api_sp' => [['path' => '/api/api-sp', 'api' => true, 'headers' => ['x y']], 'bad header name'],
            'api_six' => [['path' => '/api/api-six', 'api' => true, 'headers' => ['a', 'b', 'c', 'd', 'e', 'f']], 'at most 5'],
            'api_dup' => [['path' => '/api/api-dup', 'api' => true, 'headers' => ['a', 'a']], 'different names'],
            'api_nol' => [['path' => '/api/api-nol', 'api' => true, 'headers' => 'authorization'], 'headers is a list'],
            'api_hn' => [['path' => '/api/api-hn', 'headers' => ['authorization']], 'only go with an api route'],
            'api_mb' => [['path' => '/api/api-mb', 'max_body_kb' => 10], 'only goes with an api route'],
            'api_mx' => [['path' => '/api/api-mx', 'api' => true, 'max_body_kb' => 5000], 'max_body_kb is 1..1024'],
            'api_mz' => [['path' => '/api/api-mz', 'api' => true, 'max_body_kb' => 0], 'max_body_kb is 1..1024'],
        ];
        foreach ($cases as $name => [$route, $reason]) {
            ModuleFixture::make($name, [$route]);
            $info = $this->status($name);
            $this->assertSame('blocked', $info['status'], $name);
            $this->assertContains($reason, (string)$info['reason'], $name);
        }
        $this->assertContains('never public or indexable', $this->log(), 'the reason is logged');

        ModuleFixture::make('api_good', [['path' => '/api/api-good', 'api' => true, 'headers' => ['authorization', 'x-telegram-bot-api-secret-token', 'content-type', 'x-a', 'x-b'], 'max_body_kb' => 1024]]);
        $this->assertSame('ok', $this->status('api_good')['status'], 'the example of the task');
    }

    public function testTheFirstSegmentApiIsOpenToModulesAndTheAddressIsNotInTheSitemap(): void
    {
        $this->boot();
        ModuleFixture::make('api_sm', $this->routes('api_sm'));
        $this->assertSame('ok', $this->status('api_sm')['status']);
        $this->assertSame(200, $this->http('GET', '/api/api_sm/echo')['status']);
        $this->assertNotContains('/api/', $this->http('GET', '/sitemap.xml')['body']);
        $this->assertSame(404, $this->http('GET', '/api/unknown/echo')['status'], 'an address nobody owns is a page 404');
    }

    // 6. A picture on an API address: multipart with a header, no token, errors in JSON.

    public function testAPictureOnAnApiAddress(): void
    {
        $this->boot();
        ModuleFixture::make('api_ph', [['path' => '/api/api_ph/photo', 'methods' => ['POST'], 'api' => true, 'headers' => ['authorization'], 'upload' => ['kinds' => ['image'], 'max_kb' => 800, 'max_files' => 2]]]);
        $img = imagecreatetruecolor(16, 16);
        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        $send = function (string $content, string $name) {
            $tmp = TEST_TMP . '/ph-' . bin2hex(random_bytes(3));
            file_put_contents($tmp, $content);
            $ch = curl_init("http://127.0.0.1:{$this->port}/api/api_ph/photo");
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['media' => '1', 'file' => new CURLFile($tmp, 'application/octet-stream', $name)], CURLOPT_HTTPHEADER => ['Authorization: Bearer abc'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
            $body = (string)curl_exec($ch);
            return ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body, 'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
        };
        $ok = $send($png, 'p.png');
        $this->assertSame(200, $ok['status'], $ok['body']);
        $d = json_decode($ok['body'], true);
        $this->assertTrue(preg_match('~^/assets/uploads/m/api_ph/[a-f0-9]{20}\.webp$~', (string)$d['files'][0]['media']) === 1);

        $bad = $send('<?php echo 1;', 'a.png');
        $this->assertSame(400, $bad['status']);
        $this->assertContains('application/json', $bad['type']);
        $j = json_decode($bad['body'], true);
        $this->assertSame(false, $j['ok']);
        $this->assertSame('invalid_upload', $j['error']);
        $this->assertContains('такой тип файла не принимается', $j['message']);
    }
}
