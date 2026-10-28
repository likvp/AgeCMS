<?php
declare(strict_types=1);

use AgeCMS\{Actor, AgeCMSException, Api, Background, Config, ForbiddenException, Updater, Updates};

final class UpdaterTest extends TestCase
{
    private const SOURCE = 'https://updates.example.com/agecms/release.json';

    private string $secret = '';

    /** A site at 1.0.0 with a few core files in the sandbox root, a trusted test key and the update source set. */
    private function site(): void
    {
        $this->freshSite();
        Updates::reset(['version' => '1.0.0', 'date' => '2026-10-28', 'notes' => '', 'url' => '']);
        TestEnv::remove(AGECMS_UPDATE_ROOT);
        foreach (['index.php' => 'old index', 'core/bootstrap.php' => 'old bootstrap', 'core/Gone.php' => 'stays', 'assets/uploads/photo.webp' => 'photo', 'themes/mine/theme.json' => 'mine'] as $path => $body) {
            @mkdir(dirname(AGECMS_UPDATE_ROOT . '/' . $path), 0755, true);
            file_put_contents(AGECMS_UPDATE_ROOT . '/' . $path, $body);
        }
        // The P-256 tests also run on a PHP built without sodium.
        if (function_exists('sodium_crypto_sign_keypair')) {
            $pair = sodium_crypto_sign_keypair();
            $this->secret = sodium_crypto_sign_secretkey($pair);
            Config::set('updates.trusted_keys', ['test-key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        }
        Config::set('updates.url', self::SOURCE);
    }

    /** @param array<string,string> $extra */
    private function files(string $version, array $extra = []): array
    {
        return $extra + [
            'index.php' => "new index {$version}",
            'core/bootstrap.php' => "new bootstrap {$version}",
            'core/release.json' => json_encode(['version' => $version, 'date' => '2026-11-01', 'notes' => 'n', 'url' => '']),
            'core/New.php' => 'brand new',
            'assets/admin/admin.css' => 'css',
        ];
    }

    /**
     * Publishes a release: the check sees it, the returned fetcher serves release.json and the package.
     *
     * @return callable(string):array{status:int,body:string}
     */
    private function publish(string $package, string $version = '1.1.0', ?string $secret = null): callable
    {
        $release = ['version' => $version, 'date' => '2026-11-01', 'notes' => 'Что нового', 'url' => '', 'package' => "agecms-{$version}.json.gz", 'sha256' => hash('sha256', $package), 'size' => strlen($package)];
        $release['signature'] = ['alg' => 'ed25519', 'key_id' => 'test-key', 'sig' => base64_encode(sodium_crypto_sign_detached(Updater::signedMessage($release), $secret ?? $this->secret))];
        $fetch = function (string $url) use ($release, $package, $version): array {
            if ($url === self::SOURCE) {
                return ['status' => 200, 'body' => (string)json_encode($release)];
            }
            $this->assertSame('https://updates.example.com/agecms/agecms-' . $version . '.json.gz', $url, 'the package is looked up next to release.json');
            return ['status' => 200, 'body' => $package];
        };
        Updates::check($fetch);
        return $fetch;
    }

    /** A release signed only with ECDSA P-256, as a server without sodium sees it. Returns the fetcher and the release. */
    private function publishP256(string $package, string $version = '1.1.0', bool $forge = false): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        Config::set('updates.trusted_keys', ['test-p256' => openssl_pkey_get_details($key)['key']]);
        $release = ['version' => $version, 'date' => '2026-11-01', 'notes' => '', 'url' => '', 'package' => "agecms-{$version}.json.gz", 'sha256' => hash('sha256', $package), 'size' => strlen($package)];
        openssl_sign($forge ? 'something else' : Updater::signedMessage($release), $sig, $key, OPENSSL_ALGO_SHA256);
        $release['signatures'] = [['alg' => 'ecdsa-p256-sha256', 'key_id' => 'test-p256', 'sig' => base64_encode($sig)]];
        $fetch = static fn(string $url): array => ['status' => 200, 'body' => $url === self::SOURCE ? (string)json_encode($release) : $package];
        Updates::check($fetch);
        return [$fetch, $release];
    }

    public function testP256SignatureWorksWithoutSodium(): void
    {
        $this->site();
        [$fetch, $release] = $this->publishP256(Updater::pack($this->files('1.1.0'), '1.1.0'));
        $this->assertSame(null, Updater::verifyRelease($release, ['ecdsa-p256-sha256']), 'openssl alone is enough');
        if (function_exists('sodium_crypto_sign_verify_detached')) {
            $this->assertContains('не подписан известным ключом', (string)Updater::verifyRelease($release, ['ed25519']), 'sodium alone cannot check a P-256 signature');
        }
        $this->assertSame(1, count(Updates::available()['signatures'] ?? []), 'the signatures list survives the stored state');
        Updater::install($fetch);
        $this->assertSame('new index 1.1.0', $this->read('index.php'), 'a release signed only with P-256 installs');
    }

    public function testForgedP256SignatureIsRefused(): void
    {
        $this->site();
        [$fetch, $release] = $this->publishP256(Updater::pack($this->files('1.1.0'), '1.1.0'), '1.1.0', true);
        $this->assertContains('не сходится', (string)Updater::verifyRelease($release, ['ecdsa-p256-sha256']));
        $this->assertThrows(AgeCMSException::class, fn() => Updater::install($fetch), 'не сходится');
        $this->assertSame('old index', $this->read('index.php'));
    }

    public function testShippedP256KeyIsTrusted(): void
    {
        $this->freshSite();
        $pem = Updater::trustedKeys()['agecms-2026-p256'] ?? '';
        $this->assertTrue(openssl_pkey_get_public($pem) !== false && openssl_pkey_get_details(openssl_pkey_get_public($pem))['ec']['curve_name'] === 'prime256v1', 'core/release-keys.json carries the P-256 release key');
    }

    private function read(string $path): string
    {
        return (string)@file_get_contents(AGECMS_UPDATE_ROOT . '/' . $path);
    }

    public function testAllowedPathsAreOnlyTheCore(): void
    {
        foreach (['index.php', '.htaccess', 'core/Updater.php', 'admin/index.php', 'bin/update.php', 'assets/admin/admin.css', 'assets/app.css', 'themes/default/theme.json'] as $ok) {
            $this->assertTrue(Updater::allowedPath($ok), $ok);
        }
        foreach (['data/config.php', 'assets/uploads/x.php', 'assets/cache/index.html', 'modules/lk/Module.php', 'themes/karat/theme.json', '../index.php', 'core/../data/config.php', '/etc/passwd', 'core/.hidden', 'core\\x.php', 'nginx.vhost.example', '.user.ini', 'core'] as $bad) {
            $this->assertSame(false, Updater::allowedPath($bad), $bad);
        }
    }

    public function testShippedKeyIsTrusted(): void
    {
        $this->freshSite();
        $keys = Updater::trustedKeys();
        $this->assertTrue(isset($keys['agecms-2026']) && strlen((string)base64_decode($keys['agecms-2026'], true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'core/release-keys.json carries the release key');
    }

    public function testSignedPackageIsInstalled(): void
    {
        $this->site();
        @mkdir(AGECMS_UPDATE_ROOT . '/bin', 0755, true);
        file_put_contents(AGECMS_UPDATE_ROOT . '/bin/run.sh', "#!/bin/sh\nold\n");
        chmod(AGECMS_UPDATE_ROOT . '/bin/run.sh', 0755);
        chmod(AGECMS_UPDATE_ROOT . '/index.php', 0640);
        $fetch = $this->publish(Updater::pack($this->files('1.1.0', ['bin/run.sh' => "#!/bin/sh\nnew\n", 'bin/new.sh' => "#!/bin/sh\n"]), '1.1.0'));
        $done = Updater::install($fetch);
        $this->assertSame(['from' => '1.0.0', 'to' => '1.1.0', 'files' => 7], array_diff_key($done, ['rollback' => 1]));
        $this->assertSame('new index 1.1.0', $this->read('index.php'));
        $this->assertSame('brand new', $this->read('core/New.php'));
        $this->assertSame('stays', $this->read('core/Gone.php'), 'files the new version does not have are left alone');
        $this->assertSame('photo', $this->read('assets/uploads/photo.webp'));
        $this->assertSame('mine', $this->read('themes/mine/theme.json'));
        $this->assertTrue(Updater::last()['ok'] === true && Updater::last()['to'] === '1.1.0');
        $this->assertTrue(glob(AGECMS_DATA . '/backups/agecms-*') !== [], 'the database was backed up first');
        $this->assertSame([], glob(AGECMS_UPDATE_ROOT . '/core/.agecms-update-*') ?: [], 'no temporary files left');
        $this->assertSame(0755, fileperms(AGECMS_UPDATE_ROOT . '/bin/run.sh') & 0777, 'a script keeps its execute bit');
        $this->assertSame(0755, fileperms(AGECMS_UPDATE_ROOT . '/bin/new.sh') & 0777, 'a new script (#!) is runnable');
        $this->assertSame(0640, fileperms(AGECMS_UPDATE_ROOT . '/index.php') & 0777, 'permissions of a replaced file are kept');

        $back = Updater::rollback();
        $this->assertSame('1.0.0', $back['version']);
        $this->assertSame('old index', $this->read('index.php'));
        $this->assertSame(false, is_file(AGECMS_UPDATE_ROOT . '/core/New.php'), 'files added by the update are removed on rollback');
    }

    public function testUnsignedOrForeignSignatureIsRefused(): void
    {
        $this->site();
        $package = Updater::pack($this->files('1.1.0'), '1.1.0');
        $fetch = $this->publish($package, '1.1.0', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->assertThrows(AgeCMSException::class, fn() => Updater::install($fetch), 'не сходится');
        $this->assertSame('old index', $this->read('index.php'), 'nothing written');
        $this->assertSame(false, Updater::last()['ok']);

        Config::set('updates.trusted_keys', []);
        $this->publish($package);
        $this->assertThrows(AgeCMSException::class, fn() => Updater::install($fetch), 'известным ключом');
    }

    public function testTamperedPackageIsRefused(): void
    {
        $this->site();
        $fetch = $this->publish(Updater::pack($this->files('1.1.0'), '1.1.0'));
        $evil = static fn(string $url): array => str_ends_with($url, '.gz') ? ['status' => 200, 'body' => Updater::pack(['index.php' => 'evil'], '1.1.0')] : $fetch($url);
        $this->assertThrows(AgeCMSException::class, fn() => Updater::install($evil), 'не совпадает');
        $this->assertSame('old index', $this->read('index.php'));
    }

    public function testSignedPackageWithAPathOutsideTheCoreIsRefused(): void
    {
        $this->site();
        $fetch = $this->publish(Updater::pack($this->files('1.1.0', ['data/config.php' => '<?php return [];']), '1.1.0'));
        $this->assertThrows(AgeCMSException::class, fn() => Updater::install($fetch), 'вне ядра');
        $this->assertSame('old index', $this->read('index.php'));
        $this->assertSame(false, is_file(AGECMS_UPDATE_ROOT . '/data/config.php'));
    }

    public function testPackageForAnotherVersionIsRefused(): void
    {
        $this->site();
        $fetch = $this->publish(Updater::pack($this->files('1.0.5'), '1.0.5'), '1.1.0');
        $this->assertThrows(AgeCMSException::class, fn() => Updater::install($fetch), 'другой версии');
    }

    public function testFailedWriteRestoresTheOldFiles(): void
    {
        $this->site();
        // A directory where the package wants a file: the rename fails half way through.
        mkdir(AGECMS_UPDATE_ROOT . '/core/release.json', 0755, true);
        file_put_contents(AGECMS_UPDATE_ROOT . '/core/release.json/x', 'x');
        $fetch = $this->publish(Updater::pack($this->files('1.1.0'), '1.1.0'));
        $this->assertThrows(AgeCMSException::class, fn() => Updater::install($fetch), 'прежняя версия возвращена');
        $this->assertSame('old index', $this->read('index.php'));
        $this->assertSame('old bootstrap', $this->read('core/bootstrap.php'));
        $this->assertSame(false, is_file(AGECMS_UPDATE_ROOT . '/core/New.php'));
        $this->assertSame(false, is_file(AGECMS_UPDATE_ROOT . '/assets/admin/admin.css'));
    }

    public function testNothingToInstallWithoutANewerVersion(): void
    {
        $this->site();
        $this->publish(Updater::pack($this->files('1.0.0'), '1.0.0'), '1.0.0');
        $this->assertThrows(AgeCMSException::class, fn() => Updater::install(static fn(string $u): ?array => null), 'Новой версии нет');
    }

    public function testAutoUpdateIsOffByDefault(): void
    {
        $this->site();
        $this->publish(Updater::pack($this->files('1.1.0'), '1.1.0'));
        $this->assertSame(false, Updater::autoEnabled());
        $this->assertSame(false, Updater::autoInstallIfDue());
        Background::run(true);
        $this->assertSame('old index', $this->read('index.php'), 'the background never installs unless asked to');
        $this->assertSame(null, Updater::last());
    }

    public function testAgentToolsAreForAdminsOnly(): void
    {
        $this->site();
        $names = static fn(string $role): array => array_column(Api::tools(Actor::agent('a', $role)), 'name');
        foreach (['check_core_update', 'update_core', 'rollback_core', 'set_core_updates'] as $tool) {
            $this->assertTrue(in_array($tool, $names('admin'), true), $tool . ' for admin');
            $this->assertSame(false, in_array($tool, $names('editor'), true), $tool . ' hidden from editor');
        }
        $this->assertThrows(ForbiddenException::class, fn() => Api::call(Actor::agent('e', 'editor'), 'update_core', []));
    }

    public function testAgentSeesTheUpdateAndChangesSettings(): void
    {
        $this->site();
        $this->publish(Updater::pack($this->files('1.1.0'), '1.1.0'));
        $agent = Actor::agent('a', 'admin');
        $structure = Api::call($agent, 'get_site_structure', []);
        $this->assertSame(['version' => '1.0.0', 'newer' => '1.1.0'], $structure['core']);

        $off = Api::call($agent, 'set_core_updates', ['auto' => true]);
        $this->assertSame(true, $off['auto']);
        $this->assertSame('1.1.0', $off['newer']['version'] ?? null, 'without a new url the stored answer is kept');
        $this->assertThrows(AgeCMSException::class, fn() => Api::call($agent, 'set_core_updates', ['url' => 'http://plain.example.com/r.json']), 'https');
        $cleared = Api::call($agent, 'set_core_updates', ['url' => '']);
        $this->assertSame(false, $cleared['auto'], 'no source, no auto-update');
        $this->assertSame(null, $cleared['newer']);
    }

    public function testAutoUpdateFailureWaitsADay(): void
    {
        $this->site();
        Config::set('updates.auto', true);
        // The real download is refused (example.com is not a test server), so the attempt fails and is remembered.
        $this->publish(Updater::pack($this->files('1.1.0'), '1.1.0'));
        $this->assertSame(false, Updater::autoInstallIfDue());
        $first = Updater::last();
        $this->assertTrue($first !== null && $first['ok'] === false && $first['to'] === '1.1.0' && $first['by'] === 'auto');
        file_put_contents(AGECMS_DATA . '/update-last.json', (string)json_encode(['at' => time() - 60] + $first));
        $this->assertSame(false, Updater::autoInstallIfDue());
        $this->assertSame(time() - 60, Updater::last()['at'], 'not tried again within a day');
    }
}
