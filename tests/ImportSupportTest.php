<?php
declare(strict_types=1);

use AgeCMS\{Actor, CircuitBreaker, CircuitBreakerException, Config, Database, ForbiddenException, Http, Media, Pages, Queue, Revisions, Schema, ValidationException};

/** What the core gives a module that moves a site from another CMS: images stored once, a raised limit of new pages. */
final class ImportSupportTest extends TestCase
{
    private function png(string $name, int $seed): string
    {
        $img = imagecreatetruecolor(40, 30);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed % 256, 80, 160));
        $path = TEST_TMP . '/' . $name;
        imagepng($img, $path);
        return $path;
    }

    public function testTheSamePictureIsStoredOnce(): void
    {
        $this->freshSite();
        if (!Media::available()) {
            $this->assertTrue(true);
            return;
        }
        $a = $this->png('a.png', 10);
        $copy = TEST_TMP . '/copy-of-a.png';
        copy($a, $copy);
        $b = $this->png('b.png', 200);

        $first = Media::importFile($a);
        $this->assertTrue(preg_match('~^/assets/uploads/[a-f0-9]{20}\.webp$~', $first) === 1);
        $this->assertTrue(is_file($a), 'the source file is left alone');
        $this->assertSame($first, Media::importFile($copy), 'same bytes, same path');
        $this->assertTrue($first !== Media::importFile($b));
        $this->assertSame(2, count(Media::items()));
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM revisions WHERE page_slug = '@media' AND action_desc LIKE ?", ['%' . $first . '%']), 'a repeat is not a new upload in the history');
        $this->assertSame(hash_file('sha256', $a), Database::value('SELECT sha256 FROM media_hashes WHERE url = ?', [$first]));

        // A picture moved to the trash is stored again rather than pointing at a missing file.
        Media::trash(basename($first));
        $again = Media::importFile($copy);
        $this->assertSame($first, $again);
        $this->assertTrue(is_file(Media::dir() . '/' . basename($again)));

        $this->assertThrows(ValidationException::class, fn() => Media::importFile(TEST_TMP . '/missing.png'), 'not there');
        file_put_contents(TEST_TMP . '/fake.png', '<?php echo 1;');
        $this->assertThrows(ValidationException::class, fn() => Media::importFile(TEST_TMP . '/fake.png'), 'Only JPEG');
    }

    public function testDownloadsStayAwayFromTheLocalNetwork(): void
    {
        $this->freshSite();
        $this->assertTrue(Http::guard('http://8.8.8.8/a.png', true) !== null, 'http is allowed for pictures of an old site');
        $this->assertSame(null, Http::guard('http://8.8.8.8/a.png'), 'other callers keep https only');
        foreach (['http://127.0.0.1/a.png', 'http://10.0.0.5/a.png', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/a.png', 'http://8.8.8.8:8080/a.png', 'ftp://8.8.8.8/a.png', 'file:///etc/passwd'] as $url) {
            $this->assertSame(null, Http::guard($url, true), $url);
            $this->assertThrows(ValidationException::class, fn() => Media::importUrl($url), 'could not be downloaded');
        }
        $this->assertSame('https://a.example/x/b.png', Http::resolve('http://a.example/x/y.png', 'https://a.example/x/b.png'));
        $this->assertSame('http://a.example/img/b.png', Http::resolve('http://a.example/x/y.png', '/img/b.png'));
        $this->assertSame('http://a.example/x/b.png', Http::resolve('http://a.example/x/y.png', 'b.png'));
        $this->assertSame('https://cdn.example/b.png', Http::resolve('https://a.example/x/y.png', '//cdn.example/b.png'));
    }

    public function testAPersonMayAllowABulkCreationForTheirSession(): void
    {
        $this->freshSite();
        Config::set('circuit_breaker.max_creates', 5);
        $admin = Actor::human('owner')->withSession('h-import');
        for ($i = 1; $i <= 5; $i++) {
            $this->createPage('n' . $i, [], $admin);
        }

        $this->assertThrows(ValidationException::class, fn() => CircuitBreaker::allowBulkCreate($admin, 10, ' '), 'reason');
        $this->assertThrows(ValidationException::class, fn() => CircuitBreaker::allowBulkCreate($admin, 5001, 'x'), 'between 1 and 5000');
        $this->assertThrows(ValidationException::class, fn() => CircuitBreaker::allowBulkCreate(Actor::human('owner'), 10, 'x'), 'session');
        $this->assertThrows(ForbiddenException::class, fn() => CircuitBreaker::allowBulkCreate(Actor::human('ed', 'editor')->withSession('h-ed'), 10, 'x'));
        $this->assertThrows(ForbiddenException::class, fn() => CircuitBreaker::allowBulkCreate(Actor::agent('bot', 'admin', 'tok-1'), 10, 'x'), 'agent token');

        $granted = CircuitBreaker::allowBulkCreate($admin, 10, 'Перенос блога с WordPress');
        $this->assertSame(['session' => 'h-import', 'limit' => 15, 'created' => 5], $granted);
        $this->assertContains('Перенос блога с WordPress', (string)Database::value("SELECT action_desc FROM revisions WHERE page_slug = '@system' ORDER BY id DESC LIMIT 1"));
        $this->assertSame('owner', Database::value("SELECT actor_name FROM revisions WHERE page_slug = '@system' ORDER BY id DESC LIMIT 1"));
        for ($i = 6; $i <= 15; $i++) {
            $this->createPage('n' . $i, [], $admin);
        }
        $this->assertSame(false, CircuitBreaker::isFrozen());
        // Fixing links between the pages it created does not count as changing the site.
        for ($i = 1; $i <= 15; $i++) {
            Queue::submit($admin, 'update_page', ['slug' => 'n' . $i, 'fields' => ['meta_title' => 'fixed']]);
        }
        $this->assertSame(false, CircuitBreaker::isFrozen(), 'pages created in the session may be fixed afterwards');

        // Other sessions keep the usual limit, and so does this one past the raised one.
        $other = Actor::human('owner')->withSession('h-other');
        for ($i = 1; $i <= 5; $i++) {
            $this->createPage('o' . $i, [], $other);
        }
        $this->assertThrows(CircuitBreakerException::class, fn() => $this->createPage('o6', [], $other));
        $this->assertSame(null, Pages::row('o6'));
        CircuitBreaker::unfreeze();
        $this->assertThrows(CircuitBreakerException::class, fn() => $this->createPage('n16', [], $admin), 'more than 15');
        CircuitBreaker::unfreeze();

        // A second permission adds to what is created, capped at 5000.
        $this->assertSame(5000, CircuitBreaker::allowBulkCreate($admin, 5000, 'большой каталог')['limit']);
        $this->assertSame(true, Revisions::verifyChain()['ok']);
    }

    public function testTheBulkSessionStillGuardsPagesThatWereThereBefore(): void
    {
        $this->freshSite();
        $setup = Actor::cli('setup')->withSession('setup');
        for ($i = 1; $i <= 10; $i++) {
            $this->createPage('p' . $i, [], $setup);
        }
        $admin = Actor::human('owner')->withSession('h-bulk');
        CircuitBreaker::allowBulkCreate($admin, 100, 'импорт');
        Queue::submit($admin, 'update_page', ['slug' => 'p1', 'fields' => ['meta_title' => 'x']]);
        Queue::submit($admin, 'update_page', ['slug' => 'p2', 'fields' => ['meta_title' => 'x']]);
        $this->assertThrows(CircuitBreakerException::class, fn() => Queue::submit($admin, 'update_page', ['slug' => 'p3', 'fields' => ['meta_title' => 'x']]), 'changed 3 of 10');
    }

    public function testADatabaseOfSchemaFiveIsUpgraded(): void
    {
        $this->freshSite();
        $this->createPage('kept');
        Database::write(static function (PDO $pdo): void {
            $pdo->exec('DROP TABLE media_hashes');
            TestEnv::dropColumns($pdo, 'pages', ['published_at']);
            TestEnv::dropColumns($pdo, 'agent_sessions', ['creates_limit']);
        });
        Database::pdo()->exec('PRAGMA user_version = 5');
        Database::reset();

        $this->assertSame(Schema::latest(), (int)Database::value('PRAGMA user_version'));
        $this->assertSame('', Database::value("SELECT published_at FROM pages WHERE slug = 'kept'"));
        $this->assertSame(false, array_key_exists('published_at', Pages::doc('kept')));
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM media_hashes'));
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM agent_sessions WHERE creates_limit <> 0'));

        // Step 6 again on a database that already has its parts changes nothing and does not fail.
        Database::pdo()->exec('PRAGMA user_version = 5');
        Database::reset();
        $this->assertSame(Schema::latest(), (int)Database::value('PRAGMA user_version'));
    }
}
