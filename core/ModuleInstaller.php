<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;
use ZipArchive;

/**
 * Puts modules into modules/ from the admin («Система», «Загрузить модуль») and from agents (install_module):
 * a zip archive or a map of files. A module comes as it is: with a valid manifest.json.sig it is a signed module,
 * without one it is a user module that runs with a red "подпись не проверена" (see SkillRegistry). The admin can
 * switch a module off, delete it (the folder goes to data/module-backups, its tables mod_<name>_* stay) and sign
 * it with the site's own key. Every action is logged.
 */
final class ModuleInstaller
{
    private const MAX_FILES = 500;
    private const MAX_FILE = 5242880;
    private const MAX_TOTAL = 20971520;
    private const KEEP_BACKUPS = 3;

    /**
     * Unpacks a zip into a file map. The module may lie at the archive root or in one top folder;
     * macOS extras (__MACOSX/, .DS_Store) are skipped.
     *
     * @return array<string,string>
     */
    public static function filesFromZip(string $zipFile): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new AgeCMSException('На сервере нет расширения PHP zip, архив не открыть');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new AgeCMSException('Это не zip-архив');
        }
        $files = [];
        $total = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $path = (string)($stat['name'] ?? '');
                if ($path === '' || str_ends_with($path, '/') || str_starts_with($path, '__MACOSX/') || basename($path) === '.DS_Store') {
                    continue;
                }
                $total += (int)($stat['size'] ?? 0);
                if (count($files) >= self::MAX_FILES || $total > self::MAX_TOTAL || (int)($stat['size'] ?? 0) > self::MAX_FILE) {
                    throw new AgeCMSException('Архив слишком большой: до ' . self::MAX_FILES . ' файлов и 20 МБ');
                }
                $bytes = $zip->getFromIndex($i);
                if ($bytes === false) {
                    throw new AgeCMSException('Файл в архиве повреждён: ' . mb_substr($path, 0, 120));
                }
                $files[$path] = $bytes;
            }
        } finally {
            $zip->close();
        }
        if (!isset($files['manifest.json'])) {
            $tops = array_unique(array_map(static fn(string $p): string => explode('/', $p, 2)[0], array_keys($files)));
            if (count($tops) === 1 && isset($files[$tops[0] . '/manifest.json'])) {
                $prefix = strlen($tops[0]) + 1;
                $files = array_combine(array_map(static fn(string $p): string => substr($p, $prefix), array_keys($files)), array_values($files));
            }
        }
        return $files;
    }

    /**
     * Installs a module from a file map. $replace allows overwriting a module with the same name (the old folder is
     * kept in data/module-backups). Returns the name and the state the registry gives it.
     *
     * @param array<string,string> $files path => contents
     * @return array{name:string,status:string,signed:?string,reason:?string,replaced:bool}
     */
    public static function install(array $files, bool $replace, string $by): array
    {
        self::checkFiles($files);
        $manifest = json_decode($files['manifest.json'], true);
        $name = is_array($manifest) ? (string)($manifest['name'] ?? '') : '';
        if (preg_match('/^[a-z0-9_]{1,40}$/', $name) !== 1) {
            throw new AgeCMSException('В manifest.json нужно поле name: строчные латинские буквы, цифры и _, до 40 знаков');
        }
        $entry = (string)($manifest['entry'] ?? 'Module.php');
        if (!isset($files[$entry]) || !is_string($manifest['class'] ?? null) || $manifest['class'] === '') {
            throw new AgeCMSException("В модуле нужны файл {$entry} и поле class в manifest.json");
        }
        $base = SkillRegistry::modulesDir();
        $target = $base . '/' . $name;
        $exists = is_dir($target);
        if ($exists && !$replace) {
            throw new AgeCMSException("Модуль {$name} уже установлен. Чтобы заменить его, отметьте «Заменить, если уже есть»");
        }
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            throw new AgeCMSException('Нет прав создать папку modules');
        }

        $tmp = $base . '/.upload-' . $name . '-' . bin2hex(random_bytes(4));
        try {
            foreach ($files as $path => $bytes) {
                $full = $tmp . '/' . $path;
                if (!is_dir(dirname($full)) && !@mkdir(dirname($full), 0755, true)) {
                    throw new AgeCMSException('Нет прав записать в папку modules');
                }
                if (@file_put_contents($full, $bytes) === false) {
                    throw new AgeCMSException('Нет прав записать в папку modules');
                }
            }
            if ($exists) {
                self::backup($name);
            }
            if (!@rename($tmp, $target)) {
                throw new AgeCMSException('Не удалось положить модуль на место');
            }
        } catch (Throwable $e) {
            self::remove($tmp);
            throw $e instanceof AgeCMSException ? $e : new AgeCMSException($e->getMessage());
        }
        SkillRegistry::reset();
        $info = SkillRegistry::modules()[$name] ?? ['status' => 'blocked', 'reason' => 'not found after install', 'signed' => null];
        Logger::log($info['signed'] === null ? 'warning' : 'info', 'module.installed', ['module' => $name, 'by' => $by, 'replaced' => $exists, 'status' => $info['status'], 'signed' => $info['signed']]);
        return ['name' => $name, 'status' => $info['status'], 'signed' => $info['signed'], 'reason' => $info['reason'], 'replaced' => $exists];
    }

    public static function setEnabled(string $name, bool $enabled, string $by): void
    {
        self::requireModule($name);
        $disabled = array_values(array_diff((array)Config::get('modules.disabled', []), [$name]));
        if (!$enabled) {
            $disabled[] = $name;
        }
        Config::set('modules.disabled', $disabled);
        SkillRegistry::reset();
        Logger::log('info', $enabled ? 'module.enabled' : 'module.disabled', ['module' => $name, 'by' => $by]);
    }

    /** Moves the module folder to data/module-backups; its data in the database stays. */
    public static function delete(string $name, string $by): string
    {
        self::requireModule($name);
        $kept = self::backup($name);
        Config::set('modules.disabled', array_values(array_diff((array)Config::get('modules.disabled', []), [$name])));
        SkillRegistry::reset();
        Logger::log('warning', 'module.deleted', ['module' => $name, 'by' => $by, 'kept' => basename($kept)]);
        return $kept;
    }

    /** Signs a user module with the site's own key: from now on a changed file blocks it. */
    public static function sign(string $name, string $by): void
    {
        self::requireModule($name);
        ModuleSigner::sign($name);
        SkillRegistry::reset();
        Logger::log('info', 'module.signed', ['module' => $name, 'by' => $by]);
    }

    /** @param array<string,string> $files */
    private static function checkFiles(array $files): void
    {
        if (!isset($files['manifest.json'])) {
            throw new AgeCMSException('В модуле нет manifest.json (в корне архива или в одной папке)');
        }
        if (count($files) > self::MAX_FILES) {
            throw new AgeCMSException('В модуле больше ' . self::MAX_FILES . ' файлов');
        }
        $total = 0;
        foreach ($files as $path => $bytes) {
            $path = (string)$path;
            $parts = explode('/', $path);
            foreach ($parts as $part) {
                // No hidden files (.htaccess), no "..", no absolute paths.
                if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}$/', $part) !== 1 || str_contains($part, '..')) {
                    throw new AgeCMSException('Недопустимое имя файла в модуле: ' . mb_substr($path, 0, 120));
                }
            }
            if (count($parts) > 8 || strlen($path) > 255) {
                throw new AgeCMSException('Слишком глубокий путь в модуле: ' . mb_substr($path, 0, 120));
            }
            $total += strlen($bytes);
            if (strlen($bytes) > self::MAX_FILE || $total > self::MAX_TOTAL) {
                throw new AgeCMSException('Модуль слишком большой: файл до 5 МБ, всего до 20 МБ');
            }
        }
    }

    private static function requireModule(string $name): void
    {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $name) !== 1 || !is_dir(SkillRegistry::modulesDir() . '/' . $name)) {
            throw new NotFoundException("Модуля {$name} нет");
        }
    }

    private static function backup(string $name): string
    {
        $dir = AGECMS_DATA . '/module-backups';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AgeCMSException('Нет прав создать ' . $dir);
        }
        $target = $dir . '/' . $name . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $source = SkillRegistry::modulesDir() . '/' . $name;
        if (!@rename($source, $target)) {
            self::copy($source, $target);
            self::remove($source);
        }
        $old = glob($dir . '/' . $name . '-*', GLOB_ONLYDIR) ?: [];
        rsort($old);
        foreach (array_slice($old, self::KEEP_BACKUPS) as $stale) {
            self::remove($stale);
        }
        return $target;
    }

    private static function copy(string $from, string $to): void
    {
        @mkdir($to, 0700, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            is_dir("{$from}/{$item}") ? self::copy("{$from}/{$item}", "{$to}/{$item}") : copy("{$from}/{$item}", "{$to}/{$item}");
        }
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    self::remove($path . '/' . $item);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
