<?php
declare(strict_types=1);

use AgeCMS\{Installer, ServerSetup};

final class ServerSetupTest extends TestCase
{
    public function testOnlyPackageFixableChecksCanBeInstalledFromTheSite(): void
    {
        foreach (['sodium', 'zip', 'gd с WebP', 'curl', 'pdo_sqlite', 'mbstring', 'openssl', 'zlib'] as $label) {
            $this->assertTrue(ServerSetup::installable($label), $label);
        }
        foreach (['PHP 8.1 или новее', 'PHP может писать в файлы ядра', 'SQLite с полнотекстовым поиском (FTS5)', '; rm -rf /'] as $label) {
            $this->assertTrue(!ServerSetup::installable($label), $label);
        }
        $known = array_column(Installer::checks(), 'label');
        foreach (['sodium', 'zip', 'gd с WebP', 'curl', 'pdo_sqlite', 'mbstring', 'openssl', 'zlib'] as $label) {
            $this->assertTrue(in_array($label, $known, true), "the check '{$label}' exists");
        }
    }

    public function testCommandsAreBuiltFromAFixedTableNotFromTheRequest(): void
    {
        $this->assertSame(['sudo', '-n', 'apt-get', 'install', '-y', '-q', '-o', 'DPkg::Lock::Timeout=120', 'php8.3-common', 'php8.3-zip', 'php8.3-gd'], ServerSetup::command(['sodium', 'zip', 'gd с WebP', 'openssl', 'evil; reboot'], 'apt', true, '8.3'));
        $this->assertSame(['dnf', 'install', '-y', '-q', 'php-sodium', 'php-zip'], ServerSetup::command(['sodium', 'zip'], 'dnf', false, '8.3'));
        $this->assertSame(['apk', 'add', '--no-cache', 'php83-sodium'], ServerSetup::command(['sodium'], 'apk', false, '8.3'));
        $this->assertSame(null, ServerSetup::command(['PHP может писать в файлы ядра'], 'apt', false, '8.3'));
        $this->assertSame(null, ServerSetup::command(['sodium'], null, false, '8.3'));
    }

    public function testManualTextHasCommandsPanelStepsAndAMessageForSupport(): void
    {
        $text = ServerSetup::manualText([['label' => 'sodium', 'ok' => false, 'level' => 'recommended', 'note' => '', 'fix' => '']], 'https://example.test');
        foreach (['sodium', 'apt-get install', 'dnf install', 'apk add', 'docker-php-ext-install', 'Расширения PHP', 'https://example.test'] as $needle) {
            $this->assertContains($needle, $text, $needle);
        }
    }

    public function testNothingIsRunWhenNothingIsMissingOrNamedWrongly(): void
    {
        $this->freshSite();
        $answer = ServerSetup::install(['no such check', 'PHP 8.1 или новее']);
        $this->assertSame(false, $answer['ran']);
        $this->assertSame('Нечего ставить', $answer['title']);
        $caps = ServerSetup::capabilities();
        $this->assertTrue(in_array($caps['method'], ['direct', 'manual'], true));
        $this->assertSame($caps['method'] === 'manual', $caps['why'] !== '');
    }
}
