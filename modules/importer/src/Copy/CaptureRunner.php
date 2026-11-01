<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Copy;

use AgeCMS\Modules\Importer\ImportException;

/**
 * Runs the capture tool (tools/capture, Node.js with Chrome) from the command line of the importer when the machine has both.
 * A web request never does this: a browser does not belong to a site's PHP process. The tool is started with a list of arguments
 * (no shell), writes the package into a folder and the importer reads that folder.
 */
final class CaptureRunner
{
    public static function root(): string
    {
        return defined('AGECMS_ROOT') ? (string)AGECMS_ROOT : dirname(__DIR__, 4);
    }

    public static function script(): string
    {
        return self::root() . '/tools/capture/capture.mjs';
    }

    /** Path of Node.js or null. */
    public static function node(): ?string
    {
        $dirs = array_merge(explode(PATH_SEPARATOR, (string)getenv('PATH')), ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/snap/bin']);
        foreach ($dirs as $d) {
            if ($d !== '' && is_file($d . '/node') && is_executable($d . '/node')) {
                return $d . '/node';
            }
        }
        return null;
    }

    /** Why the tool cannot run here, or null when it can. */
    public static function problem(): ?string
    {
        if (!function_exists('proc_open')) {
            return 'в PHP отключена функция proc_open';
        }
        if (!is_file(self::script())) {
            return 'в сайте нет папки tools/capture';
        }
        if (self::node() === null) {
            return 'на этом компьютере нет Node.js (нужна версия 18 или новее)';
        }
        if (!is_dir(dirname(self::script()) . '/node_modules/playwright-core')) {
            return 'не установлены зависимости: выполните в папке tools/capture команду «npm install»';
        }
        return null;
    }

    /**
     * Captures a site (address, Tilda export or saved folder) into a new folder and returns the folder.
     *
     * @param array<string,mixed> $options limit, delay_ms, robots, mobile (bool), verify (bool)
     * @param (callable(string):void)|null $log gets every line the tool prints
     */
    public static function run(string $input, array $options, ?callable $log = null, string $out = ''): string
    {
        $problem = self::problem();
        if ($problem !== null) {
            throw new ImportException('Режим «Точная копия» для адреса сайта запускает захват в Chrome, но ' . $problem . '. Можно сделать пакет на другом компьютере (см. tools/capture/README.md) и указать его вместо адреса.');
        }
        if ($out === '') {
            $out = sys_get_temp_dir() . '/agecms-capture-' . bin2hex(random_bytes(4));
        }
        $args = [(string)self::node(), self::script(), $input, '--out=' . $out, '--limit=' . (int)($options['limit'] ?? 50), '--delay=' . (int)($options['delay_ms'] ?? 800)];
        if (($options['robots'] ?? true) === false) {
            $args[] = '--no-robots';
        }
        if (($options['mobile'] ?? true) === false) {
            $args[] = '--no-mobile';
        }
        if (($options['verify'] ?? true) === false) {
            $args[] = '--no-verify';
        }
        $proc = @proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname(self::script()));
        if (!is_resource($proc)) {
            throw new ImportException('Не получилось запустить инструмент захвата.');
        }
        $tail = [];
        while (($line = fgets($pipes[1])) !== false) {
            $line = rtrim($line);
            $tail[] = $line;
            $tail = array_slice($tail, -15);
            if ($log !== null && $line !== '') {
                $log($line);
            }
        }
        fclose($pipes[1]);
        $code = proc_close($proc);
        if ($code !== 0 || !is_file($out . '/manifest.json')) {
            throw new ImportException('Захват не удался (код ' . $code . '): ' . implode(' | ', array_slice($tail, -4)));
        }
        return $out;
    }
}
