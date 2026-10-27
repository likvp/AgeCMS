<?php
declare(strict_types=1);

namespace AgeCMS;

/** Image import and optimisation: re-encodes to WebP (and AVIF when available), strips metadata, caps the size. */
final class Media
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_PIXELS = 40000000;
    private const MAX_WIDTH = 2400;
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    public static function dir(): string
    {
        return defined('AGECMS_UPLOADS') ? AGECMS_UPLOADS : AGECMS_ROOT . '/assets/uploads';
    }

    /** Downloads a public image and stores an optimised copy. Returns its site-relative URL. */
    public static function importUrl(string $url, int $quality = 82): string
    {
        $response = Http::get($url, 8, self::MAX_BYTES);
        if ($response === null || $response['status'] !== 200) {
            throw new ValidationException(['Image could not be downloaded (non-public address, error or larger than 10 MB)']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'agimg');
        file_put_contents($tmp, $response['body']);
        try {
            return self::optimize($tmp, $quality);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return string site-relative URL such as /assets/uploads/ab12cd.webp */
    public static function optimize(string $path, int $quality = 82): string
    {
        if (!self::available()) {
            throw new AgeCMSException('The GD extension with WebP support is required');
        }
        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], self::TYPES, true)) {
            throw new ValidationException(['Only JPEG, PNG, GIF and WebP images are accepted']);
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ValidationException(['Image is too large (max 40 megapixels)']);
        }
        $image = @imagecreatefromstring((string)file_get_contents($path));
        if ($image === false) {
            throw new ValidationException(['Image could not be decoded']);
        }
        imagepalettetotruecolor($image);
        if ($info[0] > self::MAX_WIDTH) {
            $scaled = imagescale($image, self::MAX_WIDTH);
            if ($scaled !== false) {
                $image = $scaled;
            }
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AgeCMSException("Cannot create {$dir}");
        }
        $tmp = $dir . '/.tmp' . bin2hex(random_bytes(4));
        imagewebp($image, $tmp, max(1, min(100, $quality)));
        $name = substr(sha1_file($tmp), 0, 20);
        rename($tmp, "{$dir}/{$name}.webp");
        @chmod("{$dir}/{$name}.webp", 0644);
        if (function_exists('imageavif')) {
            @imageavif($image, "{$dir}/{$name}.avif", max(1, min(100, $quality - 20)));
        }
        return "/assets/uploads/{$name}.webp";
    }
}
