<?php
declare(strict_types=1);

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

/**
 * Live parts of module pages (data-ag-poll, data-ag-step in assets/admin/admin.js): a request with X-AgeCMS-Poll gets the bare
 * fragment of the module page with X-AgeCMS-Fragment, JSON stays JSON, a POST step needs the CSRF token, and the module knows
 * it was asked by the poll (ModuleRequest::$poll).
 */
final class AdminPollTest extends HttpCase
{
    private const FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];
    private const POLL = ['X-AgeCMS-Poll' => '1'];

    private function login(): string
    {
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), self::FORM, true);
        preg_match('/name="csrf" value="([^"]+)"/', $this->http('GET', '/admin/', null, [], true)['body'], $m);
        return $m[1] ?? '';
    }

    /** A module whose page counts steps in a file and says done after the third one. */
    private function module(): void
    {
        $dir = ModuleFixture::make('ad_live', [], [], [], true, null, [
            ['path' => '/', 'title' => 'Живая страница', 'permission' => 'content.write'],
            ['path' => '/step', 'title' => 'Шаг', 'permission' => 'content.write', 'methods' => ['POST'], 'menu' => false],
            ['path' => '/state', 'title' => 'Состояние', 'permission' => 'content.write', 'menu' => false],
        ]);
        file_put_contents($dir . '/Module.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace AgeCMS\Modules\ad_live;

use AgeCMS\{Actor, AdminModuleInterface, ModuleRequest, ModuleResponse};

final class Module implements AdminModuleInterface
{
    public function register(): void
    {
    }

    public function callSkill(string $skill, array $args, Actor $actor): array
    {
        return [];
    }

    public function handleAdmin(ModuleRequest $request, Actor $actor): ModuleResponse
    {
        $file = AGECMS_DATA . '/ad_live_steps';
        $n = (int)@file_get_contents($file);
        if ($request->path === '/admin/m/ad_live/step') {
            file_put_contents($file, (string)++$n);
            $part = '<p class="live">шаг ' . $n . '</p>' . ($n >= 3 ? '<span data-ag-done="reload" hidden></span>' : '');
            return $request->poll ? ModuleResponse::html('Шаг', $part) : ModuleResponse::redirect('/admin/m/ad_live/');
        }
        if ($request->path === '/admin/m/ad_live/state') {
            return ModuleResponse::json(['done' => $n >= 3, 'fields' => ['n' => $n], 'poll' => $request->poll]);
        }
        return ModuleResponse::html('Живая страница', '<div data-ag-step="/admin/m/ad_live/step"><p class="live">шаг ' . $n . '</p></div>');
    }
}
PHP);
        ModuleFixture::resign('ad_live');
    }

    public function testAPollGetsTheBareFragmentOfTheModulePage(): void
    {
        $this->boot();
        $this->module();
        $anon = $this->http('GET', '/admin/m/ad_live/', null, self::POLL, true);
        $this->assertSame(302, $anon['status'], 'not signed in: the usual sign-in redirect');
        $this->assertSame(false, isset($anon['headers']['x-agecms-fragment']), 'not a fragment: admin.js reloads the page');

        $csrf = $this->login();
        $full = $this->http('GET', '/admin/m/ad_live/', null, [], true);
        $this->assertContains('class="side"', $full['body'], 'without the header the admin is around the page');
        $this->assertContains('data-ag-step="/admin/m/ad_live/step"', $full['body']);

        $part = $this->http('GET', '/admin/m/ad_live/', null, self::POLL, true);
        $this->assertSame(200, $part['status']);
        $this->assertSame('1', $part['headers']['x-agecms-fragment'] ?? '');
        $this->assertSame('<div data-ag-step="/admin/m/ad_live/step"><p class="live">шаг 0</p></div>', $part['body'], 'only the body of the module page');
        $this->assertContains('no-store', $part['headers']['cache-control'] ?? '');
        $this->assertContains("script-src 'self'", $part['headers']['content-security-policy'] ?? '', 'the same policy as the page');

        $json = json_decode($this->http('GET', '/admin/m/ad_live/state', null, self::POLL, true)['body'], true);
        $this->assertSame(['done' => false, 'fields' => ['n' => 0], 'poll' => true], $json, 'JSON stays JSON, the module knows it is a poll');
        $this->assertSame(false, json_decode($this->http('GET', '/admin/m/ad_live/state', null, [], true)['body'], true)['poll']);

        // Steps: POST with the CSRF token until the fragment says done.
        $refused = $this->http('POST', '/admin/m/ad_live/step', http_build_query(['csrf' => 'wrong']), self::FORM + self::POLL, true);
        $this->assertSame(403, $refused['status']);
        $this->assertContains('Сессия устарела', $refused['body']);
        $this->assertSame('1', $refused['headers']['x-agecms-fragment'] ?? '', 'the error comes as a fragment too, admin.js shows it and stops');
        $this->assertSame(false, is_file(AGECMS_DATA . '/ad_live_steps'), 'no step without the token');
        for ($i = 1; $i <= 3; $i++) {
            $step = $this->http('POST', '/admin/m/ad_live/step', http_build_query(['csrf' => $csrf]), self::FORM + self::POLL, true);
            $this->assertSame(200, $step['status']);
            $this->assertContains('шаг ' . $i, $step['body']);
        }
        $this->assertContains('data-ag-done="reload"', $step['body']);
        $this->assertSame(true, json_decode($this->http('GET', '/admin/m/ad_live/state', null, self::POLL, true)['body'], true)['done']);
        $plain = $this->http('POST', '/admin/m/ad_live/step', http_build_query(['csrf' => $csrf]), self::FORM, true);
        $this->assertSame(303, $plain['status'], 'without the header the module answers a form post as usual');
    }

    public function testTheScriptKnowsTheAttributes(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__) . '/assets/admin/admin.js');
        foreach (['data-ag-poll', 'data-ag-step', 'data-ag-poll-interval', 'data-ag-step-interval', 'data-ag-done', 'data-ag-field', 'X-AgeCMS-Poll', 'X-AgeCMS-Fragment', "'/admin/m/'"] as $needle) {
            $this->assertContains($needle, $js);
        }
    }
}
