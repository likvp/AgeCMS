<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

use AgeCMS\Modules\Importer\Detect\Detector;
use AgeCMS\Modules\Importer\ImportException;
use AgeCMS\Modules\Importer\Neutral;
use ZipArchive;

/**
 * A capture package made by tools/capture (Chrome opens the site like a visitor, every page is saved with all its files):
 * a folder or a zip with manifest.json, pages/NNNN.html and files/<host>/<path>. This extractor unpacks a zip safely (no path
 * leaves the folder, only known kinds of files, limits on count and size, step by step so a request never runs long) and hands
 * the pages over in the neutral format with the page of the package in "copy". The page files themselves are read later,
 * when the page is created (CopyBuilder), so a job does not keep megabytes of markup in its table.
 */
final class CapturePackage implements Extractor
{
    public const FORMAT_PREFIX = 'agecms-capture/';
    public const MAX_ENTRIES = 60000;
    public const MAX_FILE = 104857600;
    public const MAX_TOTAL = 2147483648;
    public const MAX_MANIFEST = 20971520;
    /** Kinds of files a copy may hold (the same list as ALLOWED_EXT of the capture tool). */
    public const EXT = ['css', 'js', 'mjs', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'cur', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'm4a', 'ogg', 'ogv', 'wav', 'pdf', 'txt', 'vtt', 'docx', 'doc', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'rtf', 'zip', 'map', 'wasm'];

    /** @param array{file:string,dir:string,limit?:int} $options file: a zip or a folder, dir: the job folder for the unpacked zip */
    public function __construct(private array $options)
    {
    }

    /** A zip with manifest.json (at the top or one folder down) of our format. */
    public static function looksZip(string $file): bool
    {
        if (!class_exists(ZipArchive::class)) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return false;
        }
        $found = false;
        for ($i = 0; $i < min($zip->numFiles, 20000) && !$found; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('~^(?:[^/]+/)?manifest\.json$~', $name) === 1) {
                $head = (string)$zip->getFromIndex($i, 4096);
                $found = str_contains($head, '"' . self::FORMAT_PREFIX);
            }
        }
        $zip->close();
        return $found;
    }

    public static function looksDir(string $dir): bool
    {
        $m = $dir . '/manifest.json';
        return is_dir($dir) && is_file($m) && str_contains((string)@file_get_contents($m, false, null, 0, 4096), '"' . self::FORMAT_PREFIX);
    }

    public function step(array &$state, float $deadline, callable $emit): bool
    {
        $phase = (string)($state['phase'] ?? '');
        if ($phase === '') {
            $src = $this->options['file'];
            if (is_dir($src)) {
                $state['root'] = rtrim(realpath($src) ?: $src, '/');
                $state['phase'] = 'read';
            } else {
                $dir = rtrim($this->options['dir'], '/') . '/pkg';
                if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new ImportException('Не получилось создать папку для распаковки пакета.');
                }
                $state['root'] = $dir;
                $state['unzip'] = ['i' => 0, 'total' => 0, 'prefix' => null, 'count' => 0];
                $state['phase'] = 'unzip';
            }
            $phase = $state['phase'];
        }
        if ($phase === 'unzip') {
            if (!$this->unzip($state, $deadline)) {
                return false;
            }
            $state['phase'] = 'read';
        }
        $this->read($state, $emit);
        return true;
    }

    /** One portion of the unpacking; true when the whole archive is out. @param array<string,mixed> $state */
    private function unzip(array &$state, float $deadline): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($this->options['file']) !== true) {
            throw new ImportException('Архив пакета не открывается: он повреждён или это не zip.');
        }
        $u = &$state['unzip'];
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new ImportException('В пакете больше ' . self::MAX_ENTRIES . ' файлов: так много не берём.');
        }
        if ($u['prefix'] === null) {
            $u['prefix'] = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if (preg_match('~^([^/]+/)manifest\.json$~', $name, $m) === 1) {
                    $u['prefix'] = $m[1];
                    break;
                }
                if ($name === 'manifest.json') {
                    break;
                }
            }
        }
        $root = $state['root'];
        for (; $u['i'] < $zip->numFiles; $u['i']++) {
            if (microtime(true) >= $deadline) {
                $zip->close();
                return false;
            }
            $name = (string)$zip->getNameIndex($u['i']);
            $stat = $zip->statIndex($u['i']);
            if ($stat === false || str_ends_with($name, '/')) {
                continue;
            }
            $rel = $u['prefix'] !== '' && str_starts_with($name, $u['prefix']) ? substr($name, strlen($u['prefix'])) : $name;
            $target = self::safeTarget($rel);
            if ($target === null) {
                continue;
            }
            $size = (int)$stat['size'];
            $comp = max(1, (int)$stat['comp_size']);
            if ($size > self::MAX_FILE || ($size > 10485760 && $size / $comp > 300)) {
                $state['warnings'][] = 'Файл ' . $rel . ' пропущен: слишком большой или подозрительно сжат.';
                continue;
            }
            if ($u['total'] + $size > self::MAX_TOTAL) {
                throw new ImportException('Пакет после распаковки больше ' . (int)(self::MAX_TOTAL / 1073741824) . ' ГБ: так много не берём.');
            }
            $path = $root . '/' . $target;
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new ImportException('Не получилось создать папку ' . dirname($target));
            }
            $in = $zip->getStream($name);
            if ($in === false) {
                continue;
            }
            $out = @fopen($path, 'wb');
            if ($out === false) {
                fclose($in);
                throw new ImportException('Не получилось записать файл ' . $target);
            }
            $written = 0;
            while (!feof($in)) {
                $chunk = (string)fread($in, 262144);
                $written += strlen($chunk);
                if ($written > self::MAX_FILE) {
                    break;
                }
                fwrite($out, $chunk);
            }
            fclose($in);
            fclose($out);
            if ($written > self::MAX_FILE) {
                @unlink($path);
                continue;
            }
            $u['total'] += $written;
            $u['count']++;
        }
        $zip->close();
        return true;
    }

    /** The place of an archive entry inside the package folder, or null when the entry is not part of a package. */
    public static function safeTarget(string $rel): ?string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || $rel[0] === '/' || str_contains($rel, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $rel) === 1 || preg_match('~^[A-Za-z]:~', $rel) === 1) {
            return null;
        }
        if ($rel === 'manifest.json' || $rel === 'capture.log') {
            return $rel;
        }
        if (preg_match('~^pages/[0-9]{1,6}(?:\.m)?\.html$~', $rel) === 1) {
            return $rel;
        }
        if (str_starts_with($rel, 'files/') && preg_match('#^files/[A-Za-z0-9._~/-]{1,400}$#', $rel) === 1) {
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            return in_array($ext, self::EXT, true) ? $rel : null;
        }
        return null;
    }

    /** @param array<string,mixed> $state @param callable(array<string,mixed>):void $emit */
    private function read(array &$state, callable $emit): void
    {
        $root = (string)$state['root'];
        $file = $root . '/manifest.json';
        if (!is_file($file) || filesize($file) > self::MAX_MANIFEST) {
            throw new ImportException('В пакете нет manifest.json или он слишком велик: это не пакет захвата.');
        }
        $m = json_decode((string)file_get_contents($file), true);
        if (!is_array($m) || !str_starts_with((string)($m['format'] ?? ''), self::FORMAT_PREFIX) || !is_array($m['pages'] ?? null)) {
            throw new ImportException('manifest.json не похож на пакет захвата AgeCMS (нужен формат ' . self::FORMAT_PREFIX . '1).');
        }
        if ((string)$m['format'] !== self::FORMAT_PREFIX . '1') {
            throw new ImportException('Пакет сделан более новой версией инструмента захвата (' . $m['format'] . '): обновите AgeCMS.');
        }
        $limit = max(1, (int)($this->options['limit'] ?? 200));
        $platform = preg_match('~^[a-z0-9_]{1,20}$~', (string)($m['platform'] ?? '')) === 1 ? (string)$m['platform'] : 'generic';
        $source = (string)($m['source']['url'] ?? '');
        $isLocalSource = (string)($m['source']['kind'] ?? 'url') !== 'url' || preg_match('~^https?://(127\.0\.0\.1|localhost)(:|/|$)~', $source) === 1;
        $site = ['source_url' => $isLocalSource ? 'https://captured-site.invalid' : $source, 'platform' => $platform, 'title' => mb_substr((string)($m['site']['title'] ?? ''), 0, 200), 'menus' => ['header' => [], 'footer' => []]];
        $state['site'] = $site;
        $state['local_base'] = $root;
        $state['detect'] = [
            'platform' => $platform,
            'name' => Detector::name($platform),
            'confidence' => 1.0,
            'evidence' => ['пакет захвата Chrome (' . (string)($m['tool']['name'] ?? 'agecms-capture') . ' ' . (string)($m['tool']['version'] ?? '') . ')', 'страниц в пакете ' . count($m['pages']) . ', файлов ' . (int)($m['totals']['files'] ?? 0)],
            'scores' => [],
        ];
        $state['capture'] = [
            'platform' => $platform, 'files' => (int)($m['totals']['files'] ?? 0), 'bytes' => (int)($m['totals']['bytes'] ?? 0),
            'trackers' => (array)($m['trackers_removed'] ?? []), 'skipped_files' => count((array)($m['skipped_files'] ?? [])), 'created_at' => (string)($m['created_at'] ?? ''),
        ];
        $n = 0;
        foreach ($m['pages'] as $p) {
            if (!is_array($p)) {
                continue;
            }
            if ($n >= $limit) {
                $state['warnings'][] = 'Достигнут лимит страниц (' . $limit . '): ещё ' . (count($m['pages']) - $n) . ' страниц пакета не взято.';
                break;
            }
            $pageFile = (string)($p['file'] ?? '');
            if (preg_match('~^pages/[0-9]{1,6}\.html$~', $pageFile) !== 1 || !is_file($root . '/' . $pageFile)) {
                $state['warnings'][] = 'Страница ' . (string)($p['path'] ?? '?') . ': файла ' . $pageFile . ' нет в пакете, пропущена.';
                continue;
            }
            $path = (string)($p['path'] ?? '/');
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . ltrim($path, '/');
            }
            $verify = (array)($p['verify'] ?? []);
            $emit(Neutral::page([
                'source_url' => $isLocalSource ? 'https://captured-site.invalid' . $path : (string)($p['url'] ?? ''),
                'old_path' => $path,
                'aliases' => array_values(array_filter(array_map('strval', (array)($p['aliases'] ?? [])), static fn(string $a): bool => $a !== '' && $a[0] === '/')),
                'title' => (string)($p['title'] ?? '') !== '' ? (string)$p['title'] : (string)($p['og_title'] ?? ''),
                'meta_title' => (string)($p['title'] ?? ''),
                'meta_description' => (string)($p['description'] ?? ''),
                'og_image' => (string)($p['og_image'] ?? ''),
                'is_noindex' => !empty($p['noindex']),
                'copy' => [
                    'file' => $pageFile,
                    'mode' => (string)($p['mode'] ?? 'source') === 'dom' ? 'dom' : 'source',
                    'mobile_file' => preg_match('~^pages/[0-9]{1,6}\.m\.html$~', (string)($p['mobile_file'] ?? '')) === 1 ? (string)$p['mobile_file'] : '',
                    'forms' => array_slice((array)($p['forms'] ?? []), 0, 20),
                    'trackers' => array_values(array_map('strval', (array)($p['trackers'] ?? []))),
                    'notes' => array_slice(array_map('strval', (array)($p['notes'] ?? [])), 0, 20),
                    'similarity' => ['desktop' => $verify['desktop'] ?? null, 'mobile' => $verify['mobile'] ?? null],
                    'size' => (int)@filesize($root . '/' . $pageFile),
                    'platform' => (string)($p['platform'] ?? ''),
                ],
            ]));
            $n++;
        }
        $state['emitted'] = $n;
    }
}
