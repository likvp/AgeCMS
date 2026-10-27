<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * A file that arrived with an admin page of a module (a page that declared "upload" in its manifest, see ModuleAdmin).
 * The core has already checked it: the kind is decided from the content, never from the name or the browser's type,
 * the size and the number are within the page's limits. The module sees only this object, never $_FILES.
 */
final class ModuleUpload
{
    /** Kind => content types accepted for it. */
    public const KINDS = [
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'pdf' => ['application/pdf'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
    ];
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf', 'text/csv' => 'csv', 'text/plain' => 'csv', 'application/csv' => 'csv'];

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
        if (preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif|pdf|csv)$/', $name) !== 1) {
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
            if ($f['size'] > $limits['max_kb'] * 1024 || (int)filesize($f['tmp']) > $limits['max_kb'] * 1024) {
                throw new ValidationException(["{$label}: больше {$limits['max_kb']} КБ"]);
            }
            $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp']);
            $kind = null;
            foreach ($limits['kinds'] as $k) {
                if (in_array($mime, self::KINDS[$k], true)) {
                    $kind = $k;
                    break;
                }
            }
            if ($kind === null || !in_array($mime, $accepted, true)) {
                throw new ValidationException(["{$label}: такой тип файла не принимается (допустимо: " . implode(', ', $limits['kinds']) . ')']);
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
}
