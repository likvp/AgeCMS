<?php
declare(strict_types=1);

use AgeCMS\{EventBus, Http};

final class HttpGuardTest extends TestCase
{
    public function testPrivateAndReservedAddressesAreRefused(): void
    {
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.5', '192.168.1.1', '169.254.169.254', '100.64.0.1', '198.18.0.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', 'not-an-ip', ''] as $ip) {
            $this->assertSame(false, Http::isPublicIp($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111'] as $ip) {
            $this->assertSame(true, Http::isPublicIp($ip), $ip);
        }
    }

    public function testUrlGuard(): void
    {
        $refused = [
            'http://8.8.8.8/x',                       // plain http
            'https://user:pw@8.8.8.8/x',              // credentials in the URL
            'https://8.8.8.8:8080/x',                 // unusual port
            'https://127.0.0.1/x',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/x',
            'https://[::ffff:127.0.0.1]/x',
            'https://localhost/x',
            'ftp://8.8.8.8/x',
            'file:///etc/passwd',
            'gopher://8.8.8.8/x',
            '//8.8.8.8/x',
            'javascript:alert(1)',
        ];
        foreach ($refused as $url) {
            $this->assertSame(null, Http::guard($url), $url);
        }
        $ok = Http::guard('https://8.8.8.8/path?q=1');
        $this->assertSame('8.8.8.8', $ok['ip']);
        $this->assertSame(443, $ok['port']);
        $this->assertSame('8.8.8.8', Http::guard('http://8.8.8.8/x', true)['ip'], 'http only when explicitly allowed');
    }

    public function testRequestToPrivateTargetNeverLeavesTheMachine(): void
    {
        $this->freshSite();
        $this->assertSame(null, Http::postJson('https://127.0.0.1/hook', ['a' => 1]));
        $this->assertSame(null, Http::get('https://10.0.0.1/'));
        $this->assertContains('http.refused', (string)file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json'));
    }

    public function testWebhookTemplateCannotChangeJsonStructure(): void
    {
        $filled = EventBus::fill(
            ['text' => 'New lead {{name}}', 'nested' => ['phone' => '{{phone}}'], 'chat' => 42],
            ['name' => '", "admin": true, "x": "', 'phone' => '+7 900', 'ignored' => ['a']],
        );
        $this->assertSame('New lead ", "admin": true, "x": "', $filled['text']);
        $this->assertSame(['text', 'nested', 'chat'], array_keys($filled), 'no key was injected');
        $this->assertSame(42, $filled['chat']);
        $this->assertSame('{"text":"New lead \", \"admin\": true, \"x\": \"","nested":{"phone":"+7 900"},"chat":42}', json_encode($filled));
        $this->assertSame('', EventBus::fill(['t' => '{{missing}}'], [])['t']);
    }
}
