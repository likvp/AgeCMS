<?php
declare(strict_types=1);

use AgeCMS\Media;

/** AVIF pictures (Webflow uploads them as is) are read by GD when it can and stored as WebP like any other picture. */
final class MediaAvifTest extends TestCase
{
    public function testAvifIsStoredAsWebpWhenGdReadsIt(): void
    {
        $this->freshSite();
        if (!Media::available() || !function_exists('imageavif')) {
            $this->assertTrue(true, 'GD without AVIF encoder: nothing to make a sample with');
            return;
        }
        $img = imagecreatetruecolor(64, 48);
        imagefill($img, 0, 0, 0x22aa66);
        $src = TEST_TMP . '/photo.avif';
        imageavif($img, $src, 60);
        $this->assertSame(IMAGETYPE_AVIF, getimagesize($src)[2]);
        if (!Media::avifReadable()) {
            $this->assertThrows(\AgeCMS\ValidationException::class, fn() => Media::optimize($src), 'AVIF');
            return;
        }
        $url = Media::importFile($src);
        $this->assertTrue(str_ends_with($url, '.webp'));
        $out = Media::dir() . '/' . basename($url);
        $this->assertSame(IMAGETYPE_WEBP, getimagesize($out)[2]);
        $this->assertSame(64, getimagesize($out)[0]);
    }

    public function testAvifReadableFollowsGd(): void
    {
        $this->assertSame(function_exists('imagecreatefromavif'), Media::avifReadable());
    }
}
