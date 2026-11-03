<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Responsive picture for a theme: <img> (or <picture> with AVIF) with srcset in several widths, real width and height (no layout
 * shift), lazy loading for everything but the first screen, and for the main picture of the page fetchpriority and a preload hint
 * in the <head>. Templates call ag_img(); the page itself is cached, so the work is done once per render.
 *
 * Smaller copies are made from the file on the first render and kept in assets/uploads/r/ (the name carries the width and,
 * for files outside the library, the time of the source, so a replaced picture gets new copies). Without GD or for a file that
 * is not there the result is a plain <img>, never an error.
 */
final class Picture
{
    /** Widths of the copies, narrow to wide; a copy is made only when it is clearly smaller than the source. */
    private const WIDTHS = [480, 768, 1200, 1600];
    private const WEBP_QUALITY = 76;
    private const AVIF_QUALITY = 48;
    /** Seconds of encoding allowed in one request; what is not ready is left out and made on the next render. */
    private const BUDGET = 12.0;

    private static float $spent = 0.0;
    private static bool $preloaded = false;

    /** Forget what this request did (tests and a new page render). */
    public static function reset(): void
    {
        self::$spent = 0.0;
        self::$preloaded = false;
    }

    /**
     * @param array{class?:string,sizes?:string,priority?:bool,lazy?:bool,width?:int,height?:int,attrs?:array<string,string>} $o
     *   sizes: the width the picture takes on the page, as in <img sizes> (default 100vw);
     *   priority: the main picture of the first screen (no lazy loading, fetchpriority high, preload in the head, one per page);
     *   lazy: default true unless priority; width, height: used only when the file cannot be measured;
     *   attrs: extra attributes of the <img> (names of letters, digits and dashes).
     */
    public static function render(string $src, string $alt = '', array $o = []): string
    {
        $priority = !empty($o['priority']);
        $lazy = $o['lazy'] ?? !$priority;
        $sizes = isset($o['sizes']) && $o['sizes'] !== '' ? $o['sizes'] : '100vw';
        $file = self::locate($src);
        $dims = $file !== null ? self::size($file) : null;
        $w = $dims !== null ? $dims[0] : (int)($o['width'] ?? 0);
        $h = $dims !== null ? $dims[1] : (int)($o['height'] ?? 0);

        $webp = []; // width => url, for the <img> srcset
        $avif = [];
        if ($file !== null && $dims !== null && $w > 0) {
            [$webp, $avif] = self::candidates($src, $file, $w);
        }

        $img = '<img src="' . ag_e($src) . '"';
        if (count($webp) > 1) {
            $img .= ' srcset="' . ag_e(self::srcset($webp)) . '" sizes="' . ag_e($sizes) . '"';
        }
        $img .= ' alt="' . ag_e($alt) . '"';
        if ($w > 0 && $h > 0) {
            $img .= ' width="' . $w . '" height="' . $h . '"';
        }
        if (!empty($o['class'])) {
            $img .= ' class="' . ag_e($o['class']) . '"';
        }
        $img .= $lazy ? ' loading="lazy" decoding="async"' : ' decoding="async"';
        if ($priority) {
            $img .= ' fetchpriority="high"';
        }
        foreach ($o['attrs'] ?? [] as $name => $value) {
            if (preg_match('/^[a-z][a-z0-9-]{0,30}$/', (string)$name) === 1 && !in_array($name, ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding', 'fetchpriority'], true)) {
                $img .= ' ' . $name . '="' . ag_e($value) . '"';
            }
        }
        $img .= '>';

        if ($priority && !self::$preloaded) {
            self::$preloaded = true;
            $set = $avif !== [] ? $avif : $webp;
            if (count($set) > 1) {
                Renderer::addHint('<link rel="preload" as="image" href="' . ag_e($src) . '" imagesrcset="' . ag_e(self::srcset($set)) . '" imagesizes="' . ag_e($sizes) . '"'
                    . ($avif !== [] ? ' type="image/avif"' : '') . ' fetchpriority="high">');
            } elseif ($avif !== []) {
                Renderer::addHint('<link rel="preload" as="image" href="' . ag_e((string)reset($avif)) . '" type="image/avif" fetchpriority="high">');
            } else {
                Renderer::addHint('<link rel="preload" as="image" href="' . ag_e($src) . '" fetchpriority="high">');
            }
        }

        if ($avif === []) {
            return $img;
        }
        return '<picture><source type="image/avif" srcset="' . ag_e(self::srcset($avif)) . '" sizes="' . ag_e($sizes) . '">' . $img . '</picture>';
    }

    /**
     * Copies that exist (and, while the encoding budget lasts, are made now).
     *
     * @return array{0:array<int,string>,1:array<int,string>} WebP and AVIF candidates, width => url
     */
    private static function candidates(string $src, string $file, int $natural): array
    {
        $webp = [$natural => $src];
        $avif = [];
        $isWebp = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'webp';
        // A picture of the library already has an AVIF twin from the upload.
        if ($isWebp && preg_match('~^(/assets/uploads/[a-f0-9]{20})\.webp$~', $src, $m) === 1 && is_file(substr($file, 0, -5) . '.avif')) {
            $avif[$natural] = $m[1] . '.avif';
        }
        if (!Media::available() || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['webp', 'jpg', 'jpeg', 'png'], true)) {
            return [$webp, $avif];
        }
        $widths = array_values(array_filter(self::WIDTHS, static fn(int $w): bool => $w <= (int)floor($natural * 0.85)));
        $base = self::base($src, $file);
        $dir = Media::dir() . '/r';
        $wantAvif = function_exists('imageavif');
        $needNaturalAvif = $wantAvif && !isset($avif[$natural]);
        $todo = $widths;
        if ($needNaturalAvif && $widths !== []) {
            $todo[] = $natural; // only the AVIF of the full size; the WebP of it is the source itself
        }
        $image = null;
        foreach ($todo as $width) {
            $isFull = $width === $natural;
            $webpPath = "{$dir}/{$base}-{$width}.webp";
            $avifPath = "{$dir}/{$base}-{$width}.avif";
            $needWebp = !$isFull && !is_file($webpPath);
            $needAvif = $wantAvif && !is_file($avifPath);
            if (($needWebp || $needAvif) && self::$spent < self::BUDGET) {
                $t = microtime(true);
                $image ??= self::decode($file);
                if ($image !== null && (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir))) {
                    $copy = $isFull ? $image : self::scale($image, $width);
                    if ($copy !== false) {
                        imagealphablending($copy, false);
                        imagesavealpha($copy, true);
                        if ($needWebp) {
                            self::save($copy, $webpPath, 'webp');
                        }
                        if ($needAvif) {
                            self::save($copy, $avifPath, 'avif');
                        }
                    }
                }
                self::$spent += microtime(true) - $t;
            }
            if (!$isFull && is_file($webpPath)) {
                $webp[$width] = '/assets/uploads/r/' . "{$base}-{$width}.webp";
            }
            if (is_file($avifPath)) {
                $avif[$width] = '/assets/uploads/r/' . "{$base}-{$width}.avif";
            }
        }
        ksort($webp);
        ksort($avif);
        // One AVIF candidate is enough for the picture; a single WebP means no srcset.
        return [$webp, $avif];
    }

    /** @param array<int,string> $set */
    private static function srcset(array $set): string
    {
        ksort($set);
        $parts = [];
        foreach ($set as $width => $url) {
            $parts[] = $url . ' ' . $width . 'w';
        }
        return implode(', ', $parts);
    }

    /** Where a site-relative address lives on disk, or null for anything that is not a plain local file. */
    private static function locate(string $src): ?string
    {
        if ($src === '' || $src[0] !== '/' || str_starts_with($src, '//') || str_contains($src, '..') || str_contains($src, "\0") || preg_match('~[?#\\\\]~', $src) === 1) {
            return null;
        }
        $path = str_starts_with($src, '/assets/uploads/') ? Media::dir() . substr($src, strlen('/assets/uploads')) : AGECMS_ROOT . $src;
        return is_file($path) && is_readable($path) ? $path : null;
    }

    /** @return array{0:int,1:int}|null */
    private static function size(string $file): ?array
    {
        $info = @getimagesize($file);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        return [(int)$info[0], (int)$info[1]];
    }

    /** Name part of the copies: the content hash of a library picture, otherwise a hash of the path and the state of the file. */
    private static function base(string $src, string $file): string
    {
        if (preg_match('~^/assets/uploads/([a-f0-9]{20})\.webp$~', $src, $m) === 1) {
            return $m[1];
        }
        return 'f' . substr(sha1($src . '|' . (int)filemtime($file) . '|' . (int)filesize($file)), 0, 19);
    }

    private static function decode(string $file): ?\GdImage
    {
        $bytes = @file_get_contents($file);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        imagepalettetotruecolor($image);
        return $image;
    }

    /** A copy of the picture $width wide: bicubic where this GD can do it (some builds cannot), otherwise the default. */
    private static function scale(\GdImage $image, int $width): \GdImage|false
    {
        $height = max(1, (int)round(imagesy($image) * $width / imagesx($image)));
        $copy = @imagescale($image, $width, $height, IMG_BICUBIC);
        return $copy !== false ? $copy : @imagescale($image, $width, $height);
    }

    private static function save(\GdImage $image, string $path, string $format): void
    {
        $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
        $ok = $format === 'avif' ? @imageavif($image, $tmp, self::AVIF_QUALITY) : @imagewebp($image, $tmp, self::WEBP_QUALITY);
        if ($ok && is_file($tmp) && filesize($tmp) > 0) {
            @rename($tmp, $path);
            @chmod($path, 0644);
        } else {
            @unlink($tmp);
        }
    }

    /** Removes the copies of a library picture (it is going to the trash; they are made again when it comes back and is shown). */
    public static function dropCopies(string $base): void
    {
        if (preg_match('/^[a-f0-9]{20}$/', $base) !== 1) {
            return;
        }
        foreach (glob(Media::dir() . '/r/' . $base . '-*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
