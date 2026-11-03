<?php
declare(strict_types=1);

use AgeCMS\{Media, Picture, Queue, Renderer};

/** ag_img(): srcset in several widths, real size, lazy loading, the preload of the main picture. */
final class PictureTest extends TestCase
{
    /** A picture of the library: the file the upload makes, with the name of 20 hex digits. */
    private function library(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, 0x2266aa);
        imageline($img, 0, 0, $w, $h, 0xffffff);
        if (!is_dir(Media::dir())) {
            mkdir(Media::dir(), 0755, true);
        }
        $name = substr(sha1("pic{$w}x{$h}"), 0, 20);
        imagewebp($img, Media::dir() . "/{$name}.webp", 80);
        if (function_exists('imageavif')) {
            imageavif($img, Media::dir() . "/{$name}.avif", 50);
        }
        return "/assets/uploads/{$name}.webp";
    }

    private function gd(): bool
    {
        if (!Media::available()) {
            $this->assertTrue(true, 'GD without WebP: nothing to build a picture from');
            return false;
        }
        return true;
    }

    public function testLargePictureGetsSrcsetRealSizeAndLazyLoading(): void
    {
        $this->freshSite();
        if (!$this->gd()) {
            return;
        }
        Picture::reset();
        $src = $this->library(1600, 900);
        $html = ag_img($src, 'Класс', ['sizes' => '(min-width: 800px) 760px, 100vw', 'class' => 'pic']);
        $this->assertContains('width="1600" height="900"', $html, 'the size of the file, no layout shift');
        $this->assertContains('loading="lazy" decoding="async"', $html);
        $this->assertNotContains('fetchpriority', $html);
        $this->assertContains('sizes="(min-width: 800px) 760px, 100vw"', $html);
        $this->assertContains('class="pic"', $html);
        $this->assertContains('alt="Класс"', $html);
        $this->assertTrue(preg_match('~srcset="[^"]*/assets/uploads/r/[a-f0-9]{20}-480\.webp 480w, [^"]*-768\.webp 768w, [^"]*-1200\.webp 1200w, ' . preg_quote($src, '~') . ' 1600w"~', $html) === 1, 'copies and the original: ' . $html);
        $name = substr(basename($src), 0, 20);
        foreach ([480, 768, 1200] as $w) {
            $file = Media::dir() . "/r/{$name}-{$w}.webp";
            $this->assertTrue(is_file($file), "copy {$w} exists");
            $this->assertSame($w, getimagesize($file)[0]);
        }
        $this->assertTrue(filesize(Media::dir() . "/r/{$name}-480.webp") < filesize(Media::dir() . "/{$name}.webp"), 'a smaller file for a phone');
        if (function_exists('imageavif')) {
            $this->assertContains('<picture><source type="image/avif" srcset="', $html);
            $this->assertContains("/assets/uploads/{$name}.avif 1600w", $html, 'the AVIF made at the upload is the biggest candidate');
            $this->assertTrue(is_file(Media::dir() . "/r/{$name}-480.avif"));
        }
        $again = ag_img($src, 'Класс', ['sizes' => '(min-width: 800px) 760px, 100vw', 'class' => 'pic']);
        $this->assertSame($html, $again, 'the second render only reads what is there');
    }

    public function testMainPictureIsLoadedFirstAndPreloadedInTheHead(): void
    {
        $this->freshSite();
        if (!$this->gd()) {
            return;
        }
        $src = $this->library(1600, 900);
        $this->createPage('landing', ['title' => 'Лендинг', 'blocks' => [
            ['id' => 'img_1', 'type' => 'image', 'data' => ['src' => $src, 'alt' => 'Главное', 'priority' => true]],
            ['id' => 'img_2', 'type' => 'image', 'data' => ['src' => $src, 'alt' => 'Второе', 'priority' => true]],
        ]]);
        $page = Renderer::renderSlug('landing', false)['html'];
        $head = substr($page, 0, (int)strpos($page, '</head>'));
        $this->assertSame(1, substr_count($head, 'rel="preload" as="image"'), 'one preload per page');
        $this->assertContains('imagesrcset="', $head);
        $this->assertContains('fetchpriority="high"', $head);
        $this->assertSame(2, substr_count($page, 'fetchpriority="high"') - 1, 'both pictures are marked, only the first is preloaded');
        $this->assertSame(0, substr_count($page, 'loading="lazy"'), 'pictures marked as the main one are not lazy');
        $this->createPage('plain', ['title' => 'Обычная', 'blocks' => [['id' => 'img_1', 'type' => 'image', 'data' => ['src' => $src, 'alt' => 'Фото']]]]);
        $plain = Renderer::renderSlug('plain', false)['html'];
        $this->assertNotContains('rel="preload" as="image"', $plain);
        $this->assertContains('loading="lazy"', $plain);
    }

    public function testSmallUnknownAndUnsafePicturesStayPlain(): void
    {
        $this->freshSite();
        if (!$this->gd()) {
            return;
        }
        $small = ag_img($this->library(300, 200), 'Малая');
        $this->assertNotContains('srcset="/assets/uploads/r/', $small, 'nothing to shrink');
        $this->assertContains('width="300" height="200"', $small);

        $missing = ag_img('/assets/uploads/' . str_repeat('a', 20) . '.webp', 'Нет файла', ['width' => 640, 'height' => 480]);
        $this->assertSame('<img src="/assets/uploads/' . str_repeat('a', 20) . '.webp" alt="Нет файла" width="640" height="480" loading="lazy" decoding="async">', $missing);
        $this->assertContains('<img src="https://cdn.example/a.jpg" alt=""', ag_img('https://cdn.example/a.jpg'));
        $this->assertNotContains('srcset', ag_img('/../data/config.php', 'x'));
        $this->assertNotContains('srcset', ag_img('/assets/uploads/../../data/x.png', 'x'));
        $this->assertContains('alt="&lt;b&gt;&quot;"', ag_img('/x.png', '<b>"'));
        $this->assertContains('onload="x()"', ag_img('/x.png', '', ['attrs' => ['onload' => 'x()']]), 'any attribute name of letters is passed, the value is escaped');
        $this->assertContains('data-id="7"', ag_img('/x.png', '', ['attrs' => ['data-id' => '7', 'Bad Name' => 'x', 'src' => 'y']]));
        $this->assertNotContains('Bad Name', ag_img('/x.png', '', ['attrs' => ['Bad Name' => 'x']]));
    }

    public function testCopiesGoWithAPictureThatIsRemoved(): void
    {
        $this->freshSite();
        if (!$this->gd()) {
            return;
        }
        $src = $this->library(1600, 900);
        $name = basename($src);
        ag_img($src, '');
        $base = substr($name, 0, 20);
        $this->assertTrue(is_file(Media::dir() . "/r/{$base}-480.webp"));
        Media::trash($name);
        $this->assertSame([], glob(Media::dir() . "/r/{$base}-*") ?: [], 'the copies are removed with the picture');
        Media::restore($name);
        ag_img($src, '');
        $this->assertTrue(is_file(Media::dir() . "/r/{$base}-480.webp"), 'made again when it is shown');
    }
}
