<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * A file that arrived with an admin page of a module (a page that declared "upload" in its manifest, see ModuleAdmin).
 * The core has already checked it: the kind is decided from the content, never from the name or the browser's type,
 * the size and the number are within the page's limits. The module sees only this object, never $_FILES.
 * Export files (kinds xml and zip: a WordPress export, a site archive) are judged by their signature, may be large
 * and never reach a public folder: store() keeps them under data/, toMedia() refuses them.
 */
final class ModuleUpload
{
    /** Kind => content types accepted for it. */
    public const KINDS = [
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'pdf' => ['application/pdf'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'xml' => ['application/xml'],
        'zip' => ['application/zip'],
    ];
    /** Kinds of export files: recognised by the first bytes, allowed up to EXPORT_MAX_KB. */
    public const EXPORT_KINDS = ['xml', 'zip'];
    /** Largest max_kb of a page that takes export files (512 MB), and the default when the page does not say (50 MB). */
    public const EXPORT_MAX_KB = 524288;
    public const EXPORT_DEFAULT_KB = 51200;
    /** Largest max_kb for images, PDF and CSV; on a page that also takes export files they keep this limit. */
    public const SMALL_MAX_KB = 10240;
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf', 'text/csv' => 'csv', 'text/plain' => 'csv', 'application/csv' => 'csv', 'application/xml' => 'xml', 'application/zip' => 'zip'];

    /** @param string $path temporary file of this request, removed by PHP when it ends */
    public function __construct(
        public readonly string $module,
        public readonly string $name,
        public readonly string $kind,
        public readonly string $mime,
        public readonly int $size,
        public readonly string $path,
    ) {
    }

    /** Reads the file (for a CSV that is imported on the spot). */
    public function contents(): string
    {
        return (string)file_get_contents($this->path);
    }

    /**
     * Keeps the file in the private folder of the module (data/module_files/<module>/, not reachable from the web).
     * @return string the stored name, give it to ModuleUpload::stored() to find the file again
     */
    public function store(): string
    {
        $dir = self::dir($this->module);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AgeCMSException('Cannot create ' . $dir);
        }
        $name = bin2hex(random_bytes(12)) . '.' . self::EXTENSIONS[$this->mime];
        if (!@copy($this->path, $dir . '/' . $name)) {
            throw new AgeCMSException('Cannot store the file');
        }
        @chmod($dir . '/' . $name, 0600);
        return $name;
    }

    /** Puts an image into the media library of the site (resized, converted to WebP) and returns its name there. */
    public function toMedia(): string
    {
        if ($this->kind !== 'image') {
            throw new AgeCMSException('Only an image can go to the media library');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'agm');
        if ($tmp === false || !@copy($this->path, $tmp)) {
            throw new AgeCMSException('Cannot read the image');
        }
        try {
            return Media::optimize($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Path of a file saved with store(), or null when there is no such file of this module. */
    public static function stored(string $module, string $name): ?string
    {
        if (preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif|pdf|csv|xml|zip)$/', $name) !== 1) {
            return null;
        }
        $file = self::dir($module) . '/' . $name;
        return is_file($file) ? $file : null;
    }

    public static function delete(string $module, string $name): void
    {
        $file = self::stored($module, $name);
        if ($file !== null) {
            @unlink($file);
        }
    }

    private static function dir(string $module): string
    {
        return AGECMS_DATA . '/module_files/' . $module;
    }

    /**
     * The largest file, in bytes, the page $path of $module takes right now: its max_kb, lowered by upload_max_filesize and
     * post_max_size of this PHP. 0 when the page takes no files. For the hint next to the file field.
     */
    public static function maxBytes(string $module, string $path): int
    {
        foreach (ModuleAdmin::all() as $page) {
            if ($page['module'] === $module && $page['path'] === $path && $page['upload'] !== null) {
                $own = $page['upload']['max_kb'] * 1024;
                $server = self::serverMaxBytes();
                return $server > 0 ? min($own, $server) : $own;
            }
        }
        return 0;
    }

    /** What PHP lets through in one request: the smaller of upload_max_filesize and post_max_size, 0 when neither is set. */
    public static function serverMaxBytes(): int
    {
        $limits = array_filter([self::iniBytes((string)ini_get('upload_max_filesize')), self::iniBytes((string)ini_get('post_max_size'))], static fn(int $b): bool => $b > 0);
        return $limits === [] ? 0 : min($limits);
    }

    /** "50 МБ", "300 КБ": a size for people. */
    public static function sizeText(int $bytes): string
    {
        if ($bytes >= 1048576) {
            $mb = $bytes / 1048576;
            return ($mb >= 10 || floor($mb) === $mb ? (string)(int)floor($mb) : str_replace('.', ',', (string)round($mb, 1))) . ' МБ';
        }
        return max(1, (int)floor($bytes / 1024)) . ' КБ';
    }

    /** "8M", "512K", "1G" of php.ini into bytes; 0 means no limit. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || preg_match('/^(\d+)\s*([KMG]?)/i', $value, $m) !== 1) {
            return 0;
        }
        return (int)$m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
    }

    /**
     * Kind of an export file by its first bytes: zip starts with "PK\x03\x04", xml with "<?xml" or "<rss" (after an optional
     * UTF-8 byte order mark and white space). Null when it is neither or the page does not take that kind.
     *
     * @param list<string> $kinds
     * @return array{0:string,1:string}|null [kind, content type]
     */
    private static function sniffExport(string $file, array $kinds): ?array
    {
        if (array_intersect($kinds, self::EXPORT_KINDS) === []) {
            return null;
        }
        $head = (string)file_get_contents($file, false, null, 0, 1024);
        if (in_array('zip', $kinds, true) && str_starts_with($head, "PK\x03\x04")) {
            return ['zip', 'application/zip'];
        }
        if (in_array('xml', $kinds, true)) {
            $text = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);
            if (!str_contains($head, "\0") && (str_starts_with($text, '<?xml') || str_starts_with($text, '<rss'))) {
                return ['xml', 'application/xml'];
            }
        }
        return null;
    }

    /**
     * Checks everything PHP received for the page and turns it into ModuleUpload objects.
     *
     * @param array{kinds:list<string>,max_kb:int,max_files:int} $limits
     * @param array<string,mixed> $files $_FILES
     * @return list<self>
     * @throws ValidationException
     */
    public static function collect(string $module, array $limits, array $files): array
    {
        $flat = [];
        foreach ($files as $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            foreach (is_array($entry['name']) ? array_keys($entry['name']) : [null] as $i) {
                $pick = static fn(string $k): mixed => $i === null ? $entry[$k] : ($entry[$k][$i] ?? null);
                if ((int)$pick('error') === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $flat[] = ['name' => $pick('name'), 'tmp' => $pick('tmp_name'), 'error' => (int)$pick('error'), 'size' => (int)$pick('size')];
            }
        }
        if (count($flat) > $limits['max_files']) {
            throw new ValidationException(['Файлов не больше ' . $limits['max_files']]);
        }
        $out = [];
        $accepted = array_merge(...array_map(static fn(string $k): array => self::KINDS[$k], $limits['kinds']));
        foreach ($flat as $f) {
            $label = is_string($f['name']) ? mb_substr(basename(str_replace('\\', '/', $f['name'])), 0, 80) : 'файл';
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new ValidationException(["{$label}: файл больше, чем разрешает сервер (upload_max_filesize " . ini_get('upload_max_filesize') . ')']);
            }
            if ($f['error'] !== UPLOAD_ERR_OK || !is_string($f['tmp']) || !is_uploaded_file($f['tmp'])) {
                throw new ValidationException(["{$label}: файл не загрузился"]);
            }
            $size = max($f['size'], (int)filesize($f['tmp']));
            if ($size > $limits['max_kb'] * 1024) {
                throw new ValidationException(["{$label}: больше " . self::limitText($limits['max_kb'])]);
            }
            $export = self::sniffExport($f['tmp'], $limits['kinds']);
            if ($export !== null) {
                [$kind, $mime] = $export;
                if ($kind === 'zip' && class_exists(\ZipArchive::class)) {
                    $zip = new \ZipArchive();
                    $opened = $zip->open($f['tmp']);
                    if ($opened !== true || $zip->numFiles === 0) {
                        throw new ValidationException(["{$label}: архив повреждён или пуст"]);
                    }
                    $zip->close();
                }
            } else {
                $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp']);
                $kind = null;
                foreach (array_diff($limits['kinds'], self::EXPORT_KINDS) as $k) {
                    if (in_array($mime, self::KINDS[$k], true)) {
                        $kind = $k;
                        break;
                    }
                }
                if ($kind === null || !in_array($mime, $accepted, true)) {
                    throw new ValidationException(["{$label}: такой тип файла не принимается (допустимо: " . implode(', ', $limits['kinds']) . ')']);
                }
                if ($size > self::SMALL_MAX_KB * 1024) {
                    throw new ValidationException(["{$label}: больше " . self::limitText(self::SMALL_MAX_KB)]);
                }
            }
            if ($kind === 'csv') {
                $head = (string)file_get_contents($f['tmp'], false, null, 0, 65536);
                if (str_contains($head, "\0") || !mb_check_encoding($head, 'UTF-8')) {
                    throw new ValidationException(["{$label}: CSV должен быть в UTF-8"]);
                }
            }
            $out[] = new self($module, $label, $kind, $mime, (int)filesize($f['tmp']), $f['tmp']);
        }
        return $out;
    }

    private static function limitText(int $kb): string
    {
        return $kb >= 1024 && $kb % 1024 === 0 ? ($kb / 1024) . ' МБ' : $kb . ' КБ';
    }
}
