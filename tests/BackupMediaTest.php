<?php
declare(strict_types=1);

use AgeCMS\{Backup, Config, Database, Media, ValidationException};

final class BackupMediaTest extends TestCase
{
    public function testBackupIsAConsistentOpenableSnapshotAndPrunes(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $this->createPage('b');
        $file = Backup::create();
        $this->assertTrue(is_file($file));
        $this->assertSame(0600, fileperms($file) & 0777);

        $zip = new ZipArchive();
        $this->assertSame(true, $zip->open($file));
        $copy = TEST_TMP . '/restored.sqlite';
        file_put_contents($copy, $zip->getFromName('data.sqlite'));
        $zip->close();
        $db = new PDO('sqlite:' . $copy);
        $this->assertSame('2', (string)$db->query('SELECT COUNT(*) FROM pages')->fetchColumn());
        $this->assertSame('ok', $db->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('2', (string)$db->query('SELECT COUNT(*) FROM revisions')->fetchColumn());

        Config::set('backup.keep', 2);
        for ($i = 0; $i < 3; $i++) {
            usleep(1100000);
            Backup::create();
        }
        $this->assertSame(2, count(Backup::list()), 'only the newest backups are kept');
    }

    public function testBackupDoesNotContainConfigSecrets(): void
    {
        $this->freshSite();
        $file = Backup::create();
        $zip = new ZipArchive();
        $zip->open($file);
        $this->assertSame(1, $zip->numFiles);
        $this->assertNotContains((string)Config::get('secret'), (string)$zip->getFromName('data.sqlite'));
        $zip->close();
    }

    public function testImageIsReencodedToWebpAndStripped(): void
    {
        $this->freshSite();
        if (!Media::available()) {
            $this->assertTrue(true);
            return;
        }
        $img = imagecreatetruecolor(3000, 200);
        imagefilledrectangle($img, 0, 0, 3000, 200, imagecolorallocate($img, 200, 30, 30));
        $src = TEST_TMP . '/big.png';
        imagepng($img, $src);
        $url = Media::optimize($src);
        $this->assertTrue(str_starts_with($url, '/assets/uploads/'));
        $this->assertTrue(str_ends_with($url, '.webp'));
        $out = Media::dir() . '/' . basename($url);
        $this->assertTrue(is_file($out));
        $this->assertSame(IMAGETYPE_WEBP, getimagesize($out)[2]);
        $this->assertSame(2400, getimagesize($out)[0], 'wide images are scaled down');
    }

    public function testNonImagesAreRefused(): void
    {
        $this->freshSite();
        if (!Media::available()) {
            $this->assertTrue(true);
            return;
        }
        $src = TEST_TMP . '/evil.png';
        file_put_contents($src, "<?php echo 'shell'; ?>");
        $this->assertThrows(ValidationException::class, fn() => Media::optimize($src), 'Only JPEG');
        $svg = TEST_TMP . '/x.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertThrows(ValidationException::class, fn() => Media::optimize($svg), 'Only JPEG');
        $this->assertThrows(ValidationException::class, fn() => Media::importUrl('https://127.0.0.1/a.png'), 'could not be downloaded');
    }
}
