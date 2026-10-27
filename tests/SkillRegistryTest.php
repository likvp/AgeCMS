<?php
declare(strict_types=1);

use AgeCMS\{Actor, Config, ForbiddenException, ModuleSigner, NotFoundException, SkillRegistry};

final class SkillRegistryTest extends TestCase
{
    private function dir(): string
    {
        return AGECMS_MODULES . '/telegram_leads';
    }

    private function status(): array
    {
        SkillRegistry::reset();
        return SkillRegistry::modules()['telegram_leads'];
    }

    public function testSignedModuleLoadsAndExposesSkills(): void
    {
        $this->freshSite();
        $this->assertSame('ok', $this->status()['status']);
        $names = array_map(fn($s) => $s['module'] . '/' . $s['name'], SkillRegistry::skills());
        sort($names);
        $this->assertSame(['telegram_leads/send_test_message', 'telegram_leads/status'], $names);
    }

    public function testEditedSkillFileBlocksTheModule(): void
    {
        $this->freshSite();
        file_put_contents($this->dir() . '/skills/status.json', '{"name":"status","description":"now malicious","permission":"content.read"}');
        $s = $this->status();
        $this->assertSame('blocked', $s['status']);
        $this->assertContains('File changed after signing: skills/status.json', $s['reason']);
        $this->assertSame([], SkillRegistry::skills());
    }

    public function testEditedPhpFileBlocksTheModule(): void
    {
        $this->freshSite();
        file_put_contents($this->dir() . '/Module.php', "<?php // injected\n" . file_get_contents($this->dir() . '/Module.php'));
        $this->assertContains('File changed after signing: Module.php', $this->status()['reason']);
    }

    public function testUnlistedExecutableFileBlocksTheModule(): void
    {
        $this->freshSite();
        file_put_contents($this->dir() . '/skills/shell.php', '<?php system($_GET["c"]);');
        $this->assertContains('Unlisted executable file: skills/shell.php', $this->status()['reason']);
        unlink($this->dir() . '/skills/shell.php');
        $this->assertSame('ok', $this->status()['status']);
        file_put_contents($this->dir() . '/.htaccess', 'x');
        $this->assertContains('Unlisted executable file', $this->status()['reason']);
    }

    public function testMissingOrForgedSignatureBlocksTheModule(): void
    {
        $this->freshSite();
        $sig = file_get_contents($this->dir() . '/manifest.json.sig');
        unlink($this->dir() . '/manifest.json.sig');
        $this->assertContains('is missing', $this->status()['reason']);
        file_put_contents($this->dir() . '/manifest.json.sig', json_encode(['alg' => 'hmac-sha256', 'key_id' => 'local', 'sig' => str_repeat('a', 64)]));
        $this->assertContains('Signature does not match', $this->status()['reason']);
        file_put_contents($this->dir() . '/manifest.json.sig', $sig);
        $this->assertSame('ok', $this->status()['status']);
    }

    public function testManifestEditedAfterSigningBlocksTheModule(): void
    {
        $this->freshSite();
        $manifest = json_decode(file_get_contents($this->dir() . '/manifest.json'), true);
        $manifest['class'] = 'Evil\\Other';
        file_put_contents($this->dir() . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $this->assertContains('Signature does not match', $this->status()['reason']);
    }

    public function testPathTraversalInManifestIsRefusedEvenWhenSigned(): void
    {
        $this->freshSite();
        $manifest = json_decode(file_get_contents($this->dir() . '/manifest.json'), true);
        $manifest['files']['../../../etc/passwd'] = hash('sha256', 'x');
        file_put_contents($this->dir() . '/manifest.json', json_encode($manifest));
        // Re-sign by hand with the site key, as an attacker with the key would.
        file_put_contents($this->dir() . '/manifest.json.sig', json_encode(['alg' => 'hmac-sha256', 'key_id' => 'local', 'sig' => \AgeCMS\Security::sign('module', file_get_contents($this->dir() . '/manifest.json'))]));
        $this->assertContains('Unsafe file path', $this->status()['reason']);
    }

    public function testPublisherSignatureNeedsATrustedKey(): void
    {
        $this->freshSite();
        $secretFile = TEST_TMP . '/publisher.key';
        $public = ModuleSigner::keygen($secretFile)['public'];
        ModuleSigner::sign('telegram_leads', $secretFile, 'acme');
        $this->assertContains('Signature does not match', $this->status()['reason'], 'unknown publisher is not trusted');

        Config::set('trusted_publishers.acme', $public);
        $this->assertSame('ok', $this->status()['status']);

        $otherSecret = TEST_TMP . '/other.key';
        $otherPublic = ModuleSigner::keygen($otherSecret)['public'];
        Config::set('trusted_publishers.acme', $otherPublic);
        $this->assertContains('Signature does not match', $this->status()['reason'], 'a different key cannot vouch for the module');
    }

    public function testSkillCallsRespectRolesAndBlockedModules(): void
    {
        $this->freshSite();
        $this->assertSame(['configured' => false], SkillRegistry::call('telegram_leads', 'status', [], Actor::agent('v', 'viewer')));
        $this->assertThrows(ForbiddenException::class, fn() => SkillRegistry::call('telegram_leads', 'send_test_message', [], Actor::agent('e', 'editor')));
        $this->assertThrows(\AgeCMS\AgeCMSException::class, fn() => SkillRegistry::call('telegram_leads', 'send_test_message', [], $this->admin()), 'not configured');
        $this->assertThrows(NotFoundException::class, fn() => SkillRegistry::call('telegram_leads', 'nope', [], $this->admin()));

        file_put_contents($this->dir() . '/Module.php', '<?php // changed');
        SkillRegistry::reset();
        $this->assertThrows(NotFoundException::class, fn() => SkillRegistry::call('telegram_leads', 'status', [], $this->admin()), 'blocked');
    }

    public function testModuleListensToEvents(): void
    {
        $this->freshSite();
        SkillRegistry::boot();
        // Without Telegram configured the listener must be silent and must not break the event.
        \AgeCMS\EventBus::trigger('lead.created', ['name' => 'A', 'phone' => '1']);
        $this->assertTrue(true);
    }
}
