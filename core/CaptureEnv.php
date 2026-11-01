<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Finds Node.js and Chrome/Chromium for the "exact copy" mode of the site importer (tools/capture) and describes them as
 * environment checks for Installer::checks(). Where the paths come from, in this order: the site config (capture.node,
 * capture.chrome), the environment (AGECMS_NODE, AGECMS_CHROME, CHROME_PATH), PATH, the usual places. PHP-FPM clears its
 * environment and PATH, so bin/install-capture.sh writes the config keys.
 *
 * Every method takes an optional $o with replacements for the machine (tests): config, env, path, node_dirs, browser_paths,
 * browser_globs, version (callable path => ?string), exec (bool), euid, fpm_files.
 */
final class CaptureEnv
{
    public const LABEL_NODE = 'Node.js 20+';
    public const LABEL_BROWSER = 'Chrome или Chromium';
    public const LABEL_EXEC = 'proc_open';
    public const NODE_MIN = 20;

    private const NODE_DIRS = ['/usr/local/bin', '/usr/bin', '/opt/homebrew/bin', '/opt/nodejs/bin'];
    private const BROWSER_PATHS = [
        '/opt/google/chrome/chrome', '/usr/bin/google-chrome-stable', '/usr/bin/google-chrome', '/usr/local/bin/chromium', '/usr/bin/chromium',
        '/usr/lib/chromium/chromium', '/usr/bin/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ];
    private const BROWSER_GLOBS = ['/opt/agecms/browsers/chromium-*/chrome-linux*/chrome', '/opt/agecms/browsers/chromium-*/chrome-linux*/headless_shell'];
    private const BROWSER_NAMES = ['google-chrome-stable', 'google-chrome', 'chromium', 'chromium-browser', 'chrome'];

    /**
     * @param array<string,mixed> $o
     * @return array{path:?string,version:?string,source:string,problem:string}
     */
    public static function node(array $o = []): array
    {
        $problem = '';
        foreach (self::nodeCandidates($o) as [$path, $source]) {
            $explicit = str_starts_with($source, 'настройки') || str_starts_with($source, 'переменной');
            $why = '';
            $version = null;
            if (!self::executable($path)) {
                $why = "путь из {$source} ({$path}) не найден или не запускается этим PHP";
            } else {
                $version = self::nodeVersion($path, $o);
                if ($version !== null && (int)explode('.', $version)[0] < self::NODE_MIN) {
                    $why = "найден Node.js {$version} ({$path}), нужна версия " . self::NODE_MIN . ' или новее';
                }
            }
            if ($why === '') {
                return ['path' => $path, 'version' => $version, 'source' => $source, 'problem' => ''];
            }
            // A path the owner named is the one the importer will use, so a wrong one must not hide behind a good one on PATH.
            if ($explicit) {
                return ['path' => null, 'version' => null, 'source' => $source, 'problem' => $why];
            }
            $problem = str_starts_with($why, 'найден') ? $why : $problem;
        }
        return ['path' => null, 'version' => null, 'source' => '', 'problem' => $problem !== '' ? $problem : 'Node.js не найден'];
    }

    /**
     * @param array<string,mixed> $o
     * @return array{path:?string,source:string,problem:string}
     */
    public static function browser(array $o = []): array
    {
        $problem = '';
        foreach (self::browserCandidates($o) as [$path, $source]) {
            $explicit = str_starts_with($source, 'настройки') || str_starts_with($source, 'переменной');
            $why = '';
            if (!self::executable($path)) {
                $why = "путь из {$source} ({$path}) не найден или не запускается этим PHP";
            } elseif (self::isSnap($path)) {
                $why = "{$path} это оболочка snap: сервис веб-сервера не может запускать snap-пакеты, нужен Google Chrome или Chromium из bin/install-capture.sh";
            }
            if ($why === '') {
                return ['path' => $path, 'source' => $source, 'problem' => ''];
            }
            if ($explicit) {
                return ['path' => null, 'source' => $source, 'problem' => $why];
            }
            $problem = str_contains($why, 'snap') ? $why : $problem;
        }
        return ['path' => null, 'source' => '', 'problem' => $problem !== '' ? $problem : 'Chrome и Chromium не найдены'];
    }

    /** Whether this PHP may start programs at all (the capture tool is a program). */
    public static function execEnabled(array $o = []): bool
    {
        if (isset($o['exec'])) {
            return (bool)$o['exec'];
        }
        if (!function_exists('proc_open')) {
            return false;
        }
        return !in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
    }

    /**
     * Files of the PHP-FPM configuration that disable proc_open while this (command-line) PHP still has it. The web server's
     * PHP is the one that must start the capture, and its php.ini differs from the console's.
     *
     * @param array<string,mixed> $o
     * @return list<string> files
     */
    public static function fpmDisables(array $o = []): array
    {
        if (PHP_SAPI !== 'cli' && !isset($o['fpm_files'])) {
            return [];
        }
        $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $files = $o['fpm_files'] ?? array_merge(
            ["/etc/php/{$v}/fpm/php.ini"],
            glob("/etc/php/{$v}/fpm/conf.d/*.ini") ?: [],
            glob("/etc/php/{$v}/fpm/pool.d/*.conf") ?: [],
            glob('/etc/php-fpm.d/*.conf') ?: [],
            glob('/etc/php/fpm/php.ini') ?: [],
            glob('/usr/local/etc/php-fpm.d/*.conf') ?: [],
            glob('/usr/local/etc/php/conf.d/*.ini') ?: []
        );
        $found = [];
        foreach ($files as $file) {
            $text = is_string($file) && is_file($file) && is_readable($file) ? (string)@file_get_contents($file) : '';
            if ($text !== '' && preg_match_all('/^\s*(?:php(?:_admin)?_value\[disable_functions\]|disable_functions)\s*=\s*(.+)$/mi', $text, $m) > 0) {
                foreach ($m[1] as $list) {
                    if (preg_match('/(^|[\s,"\'])proc_open([\s,"\']|$)/', $list) === 1) {
                        $found[] = $file;
                        break;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * The three checks for Installer::checks().
     *
     * @param array<string,mixed> $o
     * @return list<array{label:string,ok:bool,required:bool,level:string,note:string,fix:string}>
     */
    public static function rows(array $o = []): array
    {
        $row = static fn(string $label, bool $ok, string $note, string $fix): array => ['label' => $label, 'ok' => $ok, 'required' => false, 'level' => 'recommended', 'note' => $note, 'fix' => $fix];
        $root = defined('AGECMS_ROOT') ? (string)AGECMS_ROOT : dirname(__DIR__);
        $web = PHP_SAPI !== 'cli';
        $without = 'для режима «Точная копия» по адресу сайта (программа захвата tools/capture); без этого она недоступна, а загрузка готового пакета захвата и обычный перенос страниц работают';
        $install = 'Debian/Ubuntu: sudo bash ' . $root . '/bin/install-capture.sh (ставит Node.js из NodeSource, Google Chrome или Chromium, шрифты и зависимости, сам записывает пути в настройки), или в админке «Система», «Установить»; вручную: поставить Node.js 20+ и Chrome, затем php bin/config.php set capture.node /usr/bin/node и php bin/config.php set capture.chrome /opt/google/chrome/chrome';
        $seen = $web ? ' Так их видит PHP сайта (' . PHP_SAPI . '); если в консоли они есть, а здесь нет, пропишите пути командами php bin/config.php set capture.node ... и capture.chrome ... или откройте права на папки.' : '';

        $exec = self::execEnabled($o);
        $fpm = $exec ? self::fpmDisables($o) : [];
        $execNote = 'запуск программ: Node.js и Chrome для «Точной копии», установка пакетов из админки';
        if (!$exec) {
            $execNote .= '; отключена в disable_functions этого PHP';
        } elseif ($fpm !== []) {
            $execNote .= '; в консоли есть, но PHP-FPM её отключает (' . implode(', ', array_slice($fpm, 0, 2)) . ')';
        }
        $rows = [$row(self::LABEL_EXEC, $exec && $fpm === [], $execNote, 'убрать proc_open из disable_functions в php.ini именно PHP-FPM (Debian/Ubuntu: /etc/php/<версия>/fpm/php.ini) и перезапустить PHP-FPM; на хостинге включить в панели или написать в поддержку')];

        $node = self::node($o);
        $rows[] = $row(self::LABEL_NODE, $node['path'] !== null,
            $node['path'] !== null
                ? 'Node.js ' . ($node['version'] ?? '(версия не проверена)') . ' (' . $node['path'] . ')'
                : $without . ' (' . $node['problem'] . ').' . $seen,
            $install);
        $reachable = $node['path'] !== null ? self::reachableByOthers($node['path'], $o) : true;
        if (!$reachable) {
            $rows[count($rows) - 1]['ok'] = false;
            $rows[count($rows) - 1]['note'] = $node['path'] . ' лежит там, куда пользователь PHP-FPM не доберётся (права на папки): консоль его видит, сайт нет. Поставьте Node.js в /usr или откройте папки на чтение и запуск.';
        }

        $browser = self::browser($o);
        $rows[] = $row(self::LABEL_BROWSER, $browser['path'] !== null,
            $browser['path'] !== null ? $browser['path'] : $without . ' (' . $browser['problem'] . ').' . $seen,
            $install);
        if ($browser['path'] !== null && !self::reachableByOthers($browser['path'], $o)) {
            $rows[count($rows) - 1]['ok'] = false;
            $rows[count($rows) - 1]['note'] = $browser['path'] . ' лежит там, куда пользователь PHP-FPM не доберётся (права на папки): консоль его видит, сайт нет. Поставьте Chrome в /opt или /usr.';
        }
        return $rows;
    }

    /** True if the executable can be started by an ordinary user (the console runs as root and sees more than PHP-FPM does). */
    private static function reachableByOthers(string $path, array $o): bool
    {
        $euid = $o['euid'] ?? (function_exists('posix_geteuid') ? posix_geteuid() : 1);
        if ($euid !== 0) {
            return true;
        }
        $real = @realpath($path);
        if ($real === false) {
            return true;
        }
        if ((@fileperms($real) & 0001) === 0) {
            return false;
        }
        for ($dir = dirname($real); $dir !== '/' && $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
            if ((@fileperms($dir) & 0001) === 0) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array{0:string,1:string}> path and where it came from */
    private static function nodeCandidates(array $o): array
    {
        $out = [];
        $config = $o['config']['node'] ?? self::configValue('capture.node');
        $env = $o['env'] ?? [];
        $get = static fn(string $k): string => (string)($env[$k] ?? (isset($o['env']) ? '' : getenv($k)));
        if (is_string($config) && $config !== '') {
            $out[] = [$config, 'настройки capture.node'];
        }
        if ($get('AGECMS_NODE') !== '') {
            $out[] = [$get('AGECMS_NODE'), 'переменной AGECMS_NODE'];
        }
        $path = (string)($o['path'] ?? getenv('PATH'));
        foreach (array_filter(explode(PATH_SEPARATOR, $path)) as $dir) {
            $out[] = [rtrim($dir, '/') . '/node', 'PATH'];
        }
        foreach ($o['node_dirs'] ?? self::NODE_DIRS as $dir) {
            $out[] = [$dir . '/node', 'типовое место'];
        }
        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    private static function browserCandidates(array $o): array
    {
        $out = [];
        $config = $o['config']['chrome'] ?? self::configValue('capture.chrome');
        $env = $o['env'] ?? [];
        $get = static fn(string $k): string => (string)($env[$k] ?? (isset($o['env']) ? '' : getenv($k)));
        if (is_string($config) && $config !== '') {
            $out[] = [$config, 'настройки capture.chrome'];
        }
        foreach (['AGECMS_CHROME', 'CHROME_PATH'] as $k) {
            if ($get($k) !== '') {
                $out[] = [$get($k), "переменной {$k}"];
            }
        }
        foreach ($o['browser_paths'] ?? self::BROWSER_PATHS as $p) {
            $out[] = [$p, 'типовое место'];
        }
        foreach ($o['browser_globs'] ?? self::BROWSER_GLOBS as $g) {
            foreach (glob($g) ?: [] as $p) {
                $out[] = [$p, 'типовое место'];
            }
        }
        $path = (string)($o['path'] ?? getenv('PATH'));
        foreach (array_filter(explode(PATH_SEPARATOR, $path)) as $dir) {
            foreach (self::BROWSER_NAMES as $name) {
                $out[] = [rtrim($dir, '/') . '/' . $name, 'PATH'];
            }
        }
        return $out;
    }

    private static function configValue(string $key): mixed
    {
        try {
            return Config::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function executable(string $path): bool
    {
        return $path !== '' && $path[0] === '/' && @is_file($path) && @is_executable($path);
    }

    /** The /usr/bin/chromium-browser of Ubuntu is a stub that starts a snap, which a web server's user cannot use. */
    private static function isSnap(string $path): bool
    {
        $real = @realpath($path) ?: $path;
        if (str_starts_with($real, '/snap/')) {
            return true;
        }
        $size = (int)@filesize($real);
        if ($size > 0 && $size < 8192) {
            $head = (string)@file_get_contents($real, false, null, 0, 4096);
            return str_starts_with($head, '#!') && preg_match('~/snap/|snap (install|run)~', $head) === 1;
        }
        return false;
    }

    /** "24.22.2" for "v24.22.2", null if the version cannot be read (programs are not allowed to start, or it printed nonsense). */
    private static function nodeVersion(string $path, array $o): ?string
    {
        if (isset($o['version']) && is_callable($o['version'])) {
            $v = $o['version']($path);
            return is_string($v) ? ltrim($v, 'v') : null;
        }
        if (!self::execEnabled($o)) {
            return null;
        }
        $proc = @proc_open([$path, '--version'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_timeout($pipes[1], 5);
        $out = trim((string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($proc);
        return preg_match('/^v(\d+\.\d+\.\d+)/', $out, $m) === 1 ? $m[1] : null;
    }
}
