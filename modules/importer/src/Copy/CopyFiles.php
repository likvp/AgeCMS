<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Copy;

use AgeCMS\CopyPage;
use AgeCMS\Modules\Importer\Extract\CapturePackage;
use AgeCMS\Modules\Importer\ImportException;

/**
 * Puts the files of a capture package (files/<host>/<path>) into the folder of one copy, assets/uploads/copy/<key>/, step by step.
 * Every file is checked again here (the package may be a folder that never went through the safe unzip): the kind of file,
 * the name, the size, the place inside the folder. The addresses written by the capture tool in styles and scripts
 * (/__agecms_copy__/...) are replaced by the folder of this copy; an SVG loses scripts and handlers.
 */
final class CopyFiles
{
    /** Text files whose addresses are rewritten (larger ones are copied as they are). */
    private const TEXT = ['css', 'js', 'mjs', 'json', 'svg', 'txt', 'vtt', 'map'];
    private const TEXT_MAX = 20971520;

    /**
     * The list of files of a package, relative to its "files" folder. @return list<string>
     */
    public static function list(string $root): array
    {
        $base = rtrim($root, '/') . '/files';
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $out[] = substr($f->getPathname(), strlen($base) + 1);
            }
        }
        sort($out);
        return $out;
    }

    /** Whether the relative name is one the copy may hold. */
    public static function nameOk(string $rel): bool
    {
        if ($rel === '' || strlen($rel) > 400 || preg_match('#^[A-Za-z0-9._~/-]+$#', $rel) !== 1 || preg_match('~(^|/)\.\.?(/|$)~', $rel) === 1 || str_starts_with($rel, '/')) {
            return false;
        }
        if (preg_match('~\.(php[0-9]?|phtml|phar|pht|inc|htaccess|cgi|pl|py|sh)(\.|/|$)~i', $rel) === 1) {
            return false;
        }
        return in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), CapturePackage::EXT, true);
    }

    /**
     * Installs the files from $offset on until the deadline.
     *
     * @param list<string> $names the list from list()
     * @param array{done:int,bytes:int,skipped:int,notes:list<string>} $progress
     * @return bool true when all files are in
     */
    public static function install(string $root, string $key, array $names, array &$progress, float $deadline, bool $move): bool
    {
        if (!CopyPage::keyValid($key)) {
            throw new ImportException('Недопустимое имя копии.');
        }
        $src = rtrim((string)realpath($root . '/files'), '/');
        $dest = CopyPage::dir($key);
        if ($src === '' || !is_dir($src)) {
            return true;
        }
        if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new ImportException('Не получилось создать папку файлов копии: ' . $dest);
        }
        $destReal = rtrim((string)realpath($dest), '/');
        $prefix = CopyPage::url($key);
        $total = count($names);
        for (; $progress['done'] < $total; $progress['done']++) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $rel = $names[$progress['done']];
            if (!self::nameOk($rel)) {
                $progress['skipped']++;
                continue;
            }
            $from = realpath($src . '/' . $rel);
            if ($from === false || !str_starts_with($from, $src . '/') || is_link($src . '/' . $rel)) {
                $progress['skipped']++;
                continue;
            }
            $to = $destReal . '/' . $rel;
            $dir = dirname($to);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new ImportException('Не получилось создать папку ' . dirname($rel));
            }
            if (!str_starts_with((string)realpath($dir), $destReal)) {
                $progress['skipped']++;
                continue;
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $size = (int)@filesize($from);
            if (in_array($ext, self::TEXT, true) && $size <= self::TEXT_MAX) {
                $text = (string)file_get_contents($from);
                if (str_contains($text, CopyBuilder::SENTINEL)) {
                    $text = str_replace(CopyBuilder::SENTINEL, $prefix, $text);
                }
                if ($ext === 'svg') {
                    $text = self::svg($text);
                }
                if (file_put_contents($to, $text) === false) {
                    throw new ImportException('Не получилось записать файл ' . $rel);
                }
                if ($move) {
                    @unlink($from);
                }
                $progress['bytes'] += strlen($text);
            } else {
                $ok = $move ? @rename($from, $to) || (@copy($from, $to) && @unlink($from)) : @copy($from, $to);
                if (!$ok) {
                    throw new ImportException('Не получилось записать файл ' . $rel);
                }
                $progress['bytes'] += $size;
            }
            @chmod($to, 0664);
        }
        return true;
    }

    /** An SVG picture without scripts, event handlers, foreign objects and javascript: addresses. */
    public static function svg(string $svg): string
    {
        $svg = (string)preg_replace('~<script\b.*?</script\s*>~is', '', $svg);
        $svg = (string)preg_replace('~<script\b[^>]*/>~is', '', $svg);
        $svg = (string)preg_replace('~<foreignObject\b.*?</foreignObject\s*>~is', '', $svg);
        $svg = (string)preg_replace('~\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $svg);
        $svg = (string)preg_replace('~((?:xlink:)?href\s*=\s*["\'])\s*(?:javascript|data:text/html)[^"\']*~i', '$1#', $svg);
        return $svg;
    }
}
