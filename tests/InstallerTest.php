<?php
declare(strict_types=1);

use AgeCMS\{AgeCMSException, Auth, Config, Database, Installer, NotInstalledException, Pages, Security, ValidationException};

final class InstallerTest extends TestCase
{
    public function testInstallCreatesEverythingAndLocksItself(): void
    {
        $this->freshSite(['demo' => true]);
        $this->assertTrue(Installer::installed());
        $this->assertSame(3, Pages::count());
        $this->assertSame(0600, fileperms(Config::file()) & 0777, 'config with secrets is private');
        $this->assertSame(64, strlen((string)Config::get('secret')));
        $this->assertContains('Require all denied', (string)file_get_contents(AGECMS_DATA . '/.htaccess'));
        $this->assertThrows(AgeCMSException::class, fn() => Installer::install(['site_url' => 'https://other.test']), 'Already installed');
    }

    public function testNoInstallationHappensOnFirstRequest(): void
    {
        TestEnv::wipe();
        Config::reset();
        Database::reset();
        $this->assertThrows(NotInstalledException::class, fn() => Database::pdo());
        $this->assertSame(false, is_file(AGECMS_DATA . '/data.sqlite'), 'opening the database does not create it');
    }

    public function testInputIsValidated(): void
    {
        TestEnv::wipe();
        Config::reset();
        Database::reset();
        $this->assertThrows(ValidationException::class, fn() => Installer::install(['site_url' => 'example.com']), 'site_url');
        $this->assertThrows(ValidationException::class, fn() => Installer::install(['site_url' => 'https://example.com/path']), 'site_url');
        $this->assertSame(false, Installer::installed());
    }

    public function testWeakPasswordsAndBadUsernamesAreRefused(): void
    {
        $this->freshSite();
        $this->assertThrows(ValidationException::class, fn() => Auth::createUser('bob', 'short'), 'at least 10');
        $this->assertThrows(ValidationException::class, fn() => Auth::createUser('b o b', 'long-enough-password'));
        $this->assertThrows(ValidationException::class, fn() => Auth::createUser('bob', 'long-enough-password', 'root'), 'Unknown role');
    }

    public function testGeneratedPasswordIsStrongAndWorks(): void
    {
        TestEnv::wipe();
        Config::reset();
        Database::reset();
        TestEnv::copyModules();
        $result = Installer::install(['site_url' => 'https://example.test', 'demo' => false]);
        $this->assertSame(true, $result['generated']);
        $this->assertSame(16, strlen($result['admin_password']));
        $row = Database::row('SELECT password_hash FROM users WHERE username = ?', [$result['admin_user']]);
        $this->assertSame(true, password_verify($result['admin_password'], $row['password_hash']));
        $this->assertNotContains($result['admin_password'], (string)file_get_contents(Config::file()), 'password is never written to config');
    }

    public function testWebInstallerKeyFile(): void
    {
        TestEnv::wipe();
        $this->assertSame(null, Installer::webKey(), 'no key file, web installer stays closed');
        mkdir(AGECMS_DATA, 0700, true);
        file_put_contents(AGECMS_DATA . '/install.key', 'too-short');
        $this->assertSame(null, Installer::webKey());
        file_put_contents(AGECMS_DATA . '/install.key', str_repeat('k', 32) . "\n");
        $this->assertSame(str_repeat('k', 32), Installer::webKey());
    }

    public function testInstallRemovesTheWebKey(): void
    {
        TestEnv::wipe();
        Config::reset();
        Database::reset();
        TestEnv::copyModules();
        mkdir(AGECMS_DATA, 0700, true);
        file_put_contents(AGECMS_DATA . '/install.key', str_repeat('k', 40));
        Installer::install(['site_url' => 'https://example.test', 'admin_password' => 'Passw0rd-test-1', 'demo' => false]);
        $this->assertSame(false, is_file(AGECMS_DATA . '/install.key'));
    }

    public function testApiTokensAreStoredHashedAndRevocable(): void
    {
        $this->freshSite();
        $token = Auth::createToken('seo-agent', 'editor');
        $this->assertTrue(str_starts_with($token, 'agc_'));
        $this->assertSame(null, Database::value('SELECT 1 FROM api_tokens WHERE token_hash = ?', [$token]), 'plain token is not stored');
        $this->assertSame(Security::hashToken($token), Database::value('SELECT token_hash FROM api_tokens'));

        $actor = Auth::tokenActor($token);
        $this->assertSame('seo-agent', $actor->name);
        $this->assertSame('editor', $actor->role);
        $this->assertTrue(str_starts_with($actor->session, 'tok-1-' . gmdate('YmdH')), 'session is bound to token and hour');
        $this->assertSame(null, Auth::tokenActor($token . 'x'));
        $this->assertSame(true, Auth::revokeToken('seo-agent'));
        $this->assertSame(null, Auth::tokenActor($token), 'revoked tokens stop working at once');
        $this->assertThrows(ValidationException::class, fn() => Auth::createToken('x', 'editor'));
        $this->assertThrows(ValidationException::class, fn() => Auth::createToken('good-name', 'superuser'));
    }
}
