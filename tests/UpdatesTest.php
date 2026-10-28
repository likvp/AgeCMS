<?php
declare(strict_types=1);

use AgeCMS\{Background, Config, Updates};

final class UpdatesTest extends TestCase
{
    private const SOURCE = 'https://updates.example.com/release.json';

    private function reply(string $version, int $status = 200): callable
    {
        return static fn(string $url): array => ['status' => $status, 'body' => json_encode(['version' => $version, 'date' => '2026-11-01', 'notes' => "Строка 1\nСтрока 2", 'url' => 'https://example.com/notes'])];
    }

    private function site(): void
    {
        $this->freshSite();
        Updates::reset(['version' => '1.2.0', 'date' => '2026-10-28', 'notes' => '', 'url' => '']);
    }

    public function testReleaseFileOfThisCopyIsValid(): void
    {
        Updates::reset();
        $release = Updates::parse((string)file_get_contents(AGECMS_ROOT . '/core/release.json'));
        $this->assertTrue($release !== null, 'core/release.json parses');
        $this->assertSame($release['version'], Updates::version());
    }

    public function testParseKeepsOnlyKnownSafeFields(): void
    {
        $this->assertSame(null, Updates::parse('not json'));
        $this->assertSame(null, Updates::parse('{"version":"1.2"}'), 'needs three numbers');
        $this->assertSame(null, Updates::parse('{"version":"1.2.0<script>"}'));
        $r = Updates::parse('{"version":"2.0.1","date":"yesterday","notes":"ok","url":"javascript:alert(1)","extra":1}');
        $this->assertSame(['version' => '2.0.1', 'date' => '', 'notes' => 'ok', 'url' => ''], $r);
    }

    public function testDefaultSourceIsTheReleasesBranch(): void
    {
        $this->freshSite();
        $config = require Config::file();
        unset($config['updates']);
        Config::save($config);
        $this->assertSame('https://raw.githubusercontent.com/likvp/AgeCMS/releases/release.json', Updates::sourceUrl());
        $this->assertSame(false, Config::get('updates.auto'), 'installing by itself stays off');
    }

    public function testNoSourceMeansNoCheckAndNoNotice(): void
    {
        $this->site();
        $this->assertSame('', Updates::sourceUrl());
        $this->assertSame(false, Updates::due());
        $this->assertSame(null, Updates::available());
        Background::run(true);
        $this->assertSame(false, is_file(Updates::file()), 'nothing is asked without an address');
    }

    public function testNewerVersionIsReported(): void
    {
        $this->site();
        Config::set('updates.url', self::SOURCE);
        $this->assertTrue(Updates::due(), 'first check is due');
        $state = Updates::check($this->reply('1.3.0'));
        $this->assertSame('', $state['error']);
        $this->assertSame('1.3.0', Updates::available()['version'] ?? null);
        $this->assertSame("Строка 1\nСтрока 2", Updates::available()['notes']);
        $this->assertSame(false, Updates::due(), 'next check in a day');
    }

    public function testSameOrOlderVersionIsNotANotice(): void
    {
        $this->site();
        Config::set('updates.url', self::SOURCE);
        Updates::check($this->reply('1.2.0'));
        $this->assertSame(null, Updates::available());
        Updates::check($this->reply('1.10.0'));
        $this->assertSame('1.10.0', Updates::available()['version'] ?? null, 'versions compare as numbers, not strings');
        Updates::check($this->reply('1.1.9'));
        $this->assertSame(null, Updates::available());
    }

    public function testFailedCheckKeepsTheLastAnswerAndRetriesSooner(): void
    {
        $this->site();
        Config::set('updates.url', self::SOURCE);
        Updates::check($this->reply('1.3.0'));
        $state = Updates::check($this->reply('9.9.9', 500));
        $this->assertContains('500', $state['error']);
        $this->assertSame('1.3.0', Updates::available()['version'] ?? null, 'an outage does not hide a release already seen');
        $state = Updates::check(static fn(string $url): array => ['status' => 200, 'body' => '<html>']);
        $this->assertContains('не файл версии', $state['error']);
        $state = Updates::check(static fn(string $url): ?array => null);
        $this->assertContains('не ответил', $state['error']);
        $this->assertSame('1.3.0', $state['latest']['version'] ?? null);

        $data = json_decode((string)file_get_contents(Updates::file()), true);
        $data['checked_at'] = time() - 3601;
        file_put_contents(Updates::file(), json_encode($data));
        $this->assertTrue(Updates::due(), 'retried after an hour, not a day');
    }

    public function testChangedSourceForgetsTheOldAnswer(): void
    {
        $this->site();
        Config::set('updates.url', self::SOURCE);
        Updates::check($this->reply('1.3.0'));
        Config::set('updates.url', 'https://other.example.com/release.json');
        $this->assertSame(null, Updates::available(), 'an answer from another source is not trusted');
        $this->assertTrue(Updates::due(), 'the new source is asked at once');
    }

    public function testLocalAddressesAreRefusedByTheRealDownload(): void
    {
        $this->site();
        Config::set('updates.url', 'https://127.0.0.1/release.json');
        $state = Updates::check();
        $this->assertContains('https на публичном адресе', $state['error']);
        $this->assertSame(null, Updates::available());
    }
}
