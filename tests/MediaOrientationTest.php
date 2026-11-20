<?php
declare(strict_types=1);

use AgeCMS\Media;

/** A phone photo lies in the sensor orientation with the turn in EXIF: Media must apply it, not save the picture sideways. */
final class MediaOrientationTest extends TestCase
{
    /** JPEG 80x40: red top-left, green top-right, blue bottom-left, yellow bottom-right; EXIF orientation set when $orientation > 0. */
    private function sample(int $orientation): string
    {
        $img = imagecreatetruecolor(80, 40);
        imagefilledrectangle($img, 0, 0, 39, 19, 0xff0000);
        imagefilledrectangle($img, 40, 0, 79, 19, 0x00ff00);
        imagefilledrectangle($img, 0, 20, 39, 39, 0x0000ff);
        imagefilledrectangle($img, 40, 20, 79, 39, 0xffff00);
        $path = TEST_TMP . "/orient-{$orientation}.jpg";
        imagejpeg($img, $path, 95);
        if ($orientation > 0) {
            $tiff = "MM\x00\x2a\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
            $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
            $bytes = (string)file_get_contents($path);
            file_put_contents($path, substr($bytes, 0, 2) . $app1 . substr($bytes, 2));
        }
        return $path;
    }

    /** @return array{0:int,1:int,2:string} width, height and the corner of the stored result that holds the red block */
    private function redCorner(string $url): array
    {
        $im = imagecreatefromwebp(Media::dir() . '/' . basename($url));
        $w = imagesx($im);
        $h = imagesy($im);
        $spots = ['top-left' => [$w / 4, $h / 4], 'top-right' => [$w * 3 / 4, $h / 4], 'bottom-left' => [$w / 4, $h * 3 / 4], 'bottom-right' => [$w * 3 / 4, $h * 3 / 4]];
        foreach ($spots as $name => [$x, $y]) {
            $c = imagecolorat($im, (int)$x, (int)$y);
            if ((($c >> 16) & 255) > 200 && (($c >> 8) & 255) < 90 && ($c & 255) < 90) {
                return [$w, $h, $name];
            }
        }
        return [$w, $h, 'none'];
    }

    public function testExifOrientationIsApplied(): void
    {
        $this->freshSite();
        if (!Media::available() || !function_exists('exif_read_data')) {
            $this->assertTrue(true, 'no GD WebP or exif extension');
            return;
        }
        // orientation => [width, height, where the red top-left block of the sensor ends up]
        $expect = [
            0 => [80, 40, 'top-left'],
            1 => [80, 40, 'top-left'],
            2 => [80, 40, 'top-right'],
            3 => [80, 40, 'bottom-right'],
            4 => [80, 40, 'bottom-left'],
            5 => [40, 80, 'top-left'],
            6 => [40, 80, 'top-right'],
            7 => [40, 80, 'bottom-right'],
            8 => [40, 80, 'bottom-left'],
        ];
        foreach ($expect as $orientation => $want) {
            $url = Media::importFile($this->sample($orientation));
            $this->assertSame($want, $this->redCorner($url), "EXIF orientation {$orientation}");
        }
    }

    public function testNoExifLeavesPictureAsIs(): void
    {
        $this->freshSite();
        $this->assertSame(1, Media::orientation($this->sample(0)));
        $this->assertSame(6, Media::orientation($this->sample(6)));
        $this->assertSame(1, Media::orientation(TEST_TMP . '/missing.jpg'));
    }

    public function testWidthLimitUsesTheTurnedPicture(): void
    {
        $this->freshSite();
        if (!Media::available() || !function_exists('exif_read_data')) {
            $this->assertTrue(true, 'no GD WebP or exif extension');
            return;
        }
        // 3000 wide and 100 high in the sensor, turned to 100 wide and 3000 high: it must not be shrunk as if it were 3000 wide.
        $img = imagecreatetruecolor(3000, 100);
        $path = TEST_TMP . '/wide.jpg';
        imagejpeg($img, $path, 80);
        $tiff = "MM\x00\x2a\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', 6) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
        $bytes = (string)file_get_contents($path);
        file_put_contents($path, substr($bytes, 0, 2) . $app1 . substr($bytes, 2));
        $url = Media::importFile($path);
        $size = getimagesize(Media::dir() . '/' . basename($url));
        $this->assertSame(100, $size[0]);
        $this->assertSame(3000, $size[1]);
    }
}
