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

    /**
     * Accepts one entry of $_FILES, checks it and stores an optimised copy.
     *
     * @param array<string,mixed> $file
     */
    public static function saveUpload(array $file, int $quality = 82): string
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['The file is larger than the server allows (upload_max_filesize is ' . ini_get('upload_max_filesize') . ', AgeCMS accepts up to 10 MB)']);
        }
        if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException(['The file was not uploaded']);
        }
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new ValidationException(['The file is larger than 10 MB']);
        }
        return self::optimize($file['tmp_name'], $quality);
    }

    /**
     * Uploaded images, newest first.
     *
     * @return list<array{name:string,url:string,size:int,time:int}>
     */
    public static function items(): array
    {
        $items = [];
        foreach (glob(self::dir() . '/*.webp') ?: [] as $file) {
            $name = basename($file);
            if (preg_match('/^[a-f0-9]{20}\.webp$/', $name) !== 1) {
                continue;
            }
            $avif = substr($file, 0, -5) . '.avif';
            $items[] = ['name' => $name, 'url' => '/assets/uploads/' . $name, 'size' => (int)filesize($file) + (is_file($avif) ? (int)filesize($avif) : 0), 'time' => (int)filemtime($file)];
        }
        usort($items, static fn(array $a, array $b): int => $b['time'] <=> $a['time'] ?: strcmp($a['name'], $b['name']));
        return $items;
    }

    /**
     * Pages that use the image.
     *
     * @return list<array<string,mixed>>
     */
    public static function usage(string $name): array
    {
        self::assertName($name);
        $like = '%' . $name . '%';
        return Database::rows('SELECT slug, title FROM pages WHERE blocks_json LIKE ? OR og_image LIKE ? OR canonical_url LIKE ? ORDER BY slug', [$like, $like, $like]);
    }

    /** Deletes an unused image together with its AVIF copy. */
    public static function delete(string $name): void
    {
        self::assertName($name);
        $used = self::usage($name);
        if ($used !== []) {
            throw new ValidationException(['The image is used on: ' . implode(', ', array_map(static fn(array $p): string => '/' . $p['slug'], $used)) . '. Remove it there first.']);
        }
        $base = self::dir() . '/' . substr($name, 0, -5);
        if (!is_file($base . '.webp')) {
            throw new NotFoundException('Image not found');
        }
        @unlink($base . '.webp');
        @unlink($base . '.avif');
        Logger::log('info', 'media.deleted', ['name' => $name]);
    }

    private static function assertName(string $name): void
    {
        if (preg_match('/^[a-f0-9]{20}\.webp$/', $name) !== 1) {
            throw new ValidationException(['Bad image name']);
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
