<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Copy;

use AgeCMS\CaptureEnv;
use AgeCMS\Config;
use AgeCMS\Http;
use AgeCMS\Modules\Importer\ImportException;

/**
 * Finds and runs the capture tool (tools/capture: Node.js and Chrome). Two ways:
 *  - run(): the command line of the importer waits for the tool and reads the package it made;
 *  - CaptureJob: the admin starts the tool as a background process that does not belong to the web request.
 *
 * Where the programs are is told by the site configuration (capture.node, capture.chrome), then the environment (AGECMS_NODE,
 * AGECMS_CHROME), then PATH and the usual places; without a path for Chrome the tool looks for it itself (the usual places,
 * the "chrome" channel of Playwright, Chromium in PLAYWRIGHT_BROWSERS_PATH). The tool is started with a list of arguments (no shell)
 * and a short, clean environment: nothing of the site (database passwords, keys, tokens) reaches the browser.
 */
final class CaptureRunner
{
    /** Tests put a stand-in for tools/capture/capture.mjs here. */
    public static ?string $scriptOverride = null;
    /** Tests: pretend the machine has (true) or has not (false) what the tool needs; null asks the machine. */
    public static ?bool $availableOverride = null;
    /** Tests: the answer of the address check instead of the real DNS. @var (callable(string):?string)|null */
    public static $addressCheck = null;

    public static function root(): string
    {
        return defined('AGECMS_ROOT') ? (string)AGECMS_ROOT : dirname(__DIR__, 4);
    }

    public static function script(): string
    {
        return self::$scriptOverride ?? self::root() . '/tools/capture/capture.mjs';
    }

    private static function setting(string $key): string
    {
        try {
            $v = Config::get('capture.' . $key, '');
        } catch (\Throwable) {
            return '';
        }
        return is_string($v) ? trim($v) : '';
    }

    /** Path of Node.js or null: capture.node, AGECMS_NODE, PATH, the usual places (the core's CaptureEnv looks, the same as the installer). */
    public static function node(): ?string
    {
        return self::found()['node']['path'];
    }

    /**
     * What CaptureEnv found, kept for the request: finding Node.js starts it to read its version, and the importer asks several times.
     * The key holds everything the answer depends on, so a changed setting is seen at once.
     *
     * @return array{node:array<string,mixed>,browser:array<string,mixed>}
     */
    private static function found(): array
    {
        static $memo = [];
        $key = implode('|', [self::setting('node'), self::setting('chrome'), (string)getenv('AGECMS_NODE'), (string)getenv('AGECMS_CHROME'), (string)getenv('PATH')]);
        return $memo[$key] ??= ['node' => CaptureEnv::node(), 'browser' => CaptureEnv::browser()];
    }

    /** Path of Chrome or Chromium (capture.chrome, AGECMS_CHROME, the usual places, /opt/agecms/browsers), or null: then the tool looks itself. */
    public static function chrome(): ?string
    {
        return self::found()['browser']['path'];
    }

    /** Folder with Chromium of Playwright (capture.browsers_path, PLAYWRIGHT_BROWSERS_PATH) or null. */
    public static function browsersPath(): ?string
    {
        foreach ([self::setting('browsers_path'), (string)getenv('PLAYWRIGHT_BROWSERS_PATH')] as $p) {
            if ($p !== '' && is_dir($p)) {
                return $p;
            }
        }
        return null;
    }

    /** Whether a source on localhost is allowed: the command line (--allow-private) or the development server, never a real web server. */
    public static function allowPrivate(): bool
    {
        return \AgeCMS\Modules\Importer\Net\HttpFetcher::privateAllowed();
    }

    /** Why the tool cannot run here, or null when it can (fast checks, no browser is started). */
    public static function problem(): ?string
    {
        if (self::$availableOverride !== null) {
            return self::$availableOverride ? null : 'на этом сервере нет Node.js и Chrome';
        }
        if (!function_exists('proc_open')) {
            return 'в PHP отключена функция proc_open (проверьте disable_functions в php.ini)';
        }
        if (!is_file(self::script())) {
            return 'в сайте нет папки tools/capture';
        }
        $node = self::found()['node'];
        if ($node['path'] === null) {
            return $node['problem'] . ' (нужен Node.js ' . CaptureEnv::NODE_MIN . ' или новее; его ставит bin/install-capture.sh)';
        }
        if (self::$scriptOverride === null && self::chrome() === null && self::browsersPath() === null) {
            return self::found()['browser']['problem'] . ' (Chrome или Chromium ставит bin/install-capture.sh)';
        }
        if (self::$scriptOverride === null && !is_dir(dirname(self::script()) . '/node_modules/playwright-core')) {
            return 'не установлены зависимости: выполните в папке tools/capture команду «npm install»';
        }
        return null;
    }

    /** @return string path of the file with the last result of the browser check */
    private static function selfTestFile(): string
    {
        return AGECMS_DATA . '/importer/capture-selftest.json';
    }

    /**
     * The answer of `capture.mjs --self-test`: starts the browser, opens about:blank, checks the guard. The answer is kept for ten
     * minutes (a failure for one), so the admin page does not start a browser on every visit.
     *
     * @return array{ok:bool,message:string,at:int,detail:string}
     */
    public static function selfTest(bool $force = false): array
    {
        $problem = self::problem();
        if ($problem !== null) {
            return ['ok' => false, 'message' => ucfirst($problem), 'at' => time(), 'detail' => ''];
        }
        if (self::$availableOverride === true) {
            return ['ok' => true, 'message' => 'проверка пропущена', 'at' => time(), 'detail' => ''];
        }
        $key = sha1(self::script() . '|' . (string)self::node() . '|' . (string)self::chrome() . '|' . (string)@filemtime(self::script()));
        $file = self::selfTestFile();
        if (!$force && is_file($file)) {
            $saved = json_decode((string)@file_get_contents($file), true);
            if (is_array($saved) && ($saved['key'] ?? '') === $key && time() - (int)($saved['at'] ?? 0) < (!empty($saved['ok']) ? 600 : 60)) {
                return ['ok' => !empty($saved['ok']), 'message' => (string)$saved['message'], 'at' => (int)$saved['at'], 'detail' => (string)($saved['detail'] ?? '')];
            }
        }
        $tmp = sys_get_temp_dir();
        $args = [(string)self::node(), self::script(), '--self-test'];
        if (self::chrome() !== null) {
            $args[] = '--chrome=' . self::chrome();
        }
        $r = self::exec($args, self::env($tmp, $tmp), 60, dirname(self::script()));
        $ok = $r['code'] === 0 && trim($r['out']) === 'ok';
        $reason = trim(implode(' ', array_slice(array_filter(array_map('trim', explode("\n", $r['err']))), -2)));
        $message = $ok ? 'Chrome запускается' : self::browserHelp($reason !== '' ? $reason : 'код ' . $r['code']);
        $result = ['ok' => $ok, 'message' => $message, 'at' => time(), 'detail' => mb_substr($r['err'], 0, 600)];
        @mkdir(dirname($file), 0700, true);
        @file_put_contents($file, json_encode($result + ['key' => $key], JSON_UNESCAPED_UNICODE));
        return $result;
    }

    /** What a person should do when the browser does not start. */
    private static function browserHelp(string $reason): string
    {
        $hint = ' Установите Google Chrome или Chromium (на Debian и Ubuntu: «apt install chromium» или пакет Google Chrome), либо укажите путь к нему в настройке capture.chrome.';
        if (stripos($reason, 'песочниц') !== false || stripos($reason, 'sandbox') !== false) {
            $hint = ' Запустите захват от обычного пользователя веб-сервера или разрешите пользовательские пространства имён.';
        }
        return 'Chrome не запускается: ' . $reason . '.' . $hint;
    }

    /**
     * What stops a capture of this address before it starts: not an address, an address of the internal network, no such name.
     * (The tool checks again, for every request of the browser.)
     */
    public static function addressProblem(string $url): ?string
    {
        if (self::$addressCheck !== null) {
            return (self::$addressCheck)($url);
        }
        if (self::allowPrivate()) {
            return preg_match('~^https?://~i', $url) === 1 ? null : 'это не адрес сайта';
        }
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            return 'это не адрес сайта (нужен http или https)';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'в адресе не должно быть логина и пароля';
        }
        $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        $host = trim($p['host'], '[]');
        if (preg_match('~^(localhost|.*\.(localhost|local|internal|intranet|lan|localdomain))$~i', $host) === 1 || (!str_contains($host, '.') && !str_contains($host, ':'))) {
            return 'адрес ведёт во внутреннюю сеть этого сервера, такие сайты не переносятся';
        }
        if (!in_array($port, [80, 443], true)) {
            return 'порт ' . $port . ' не разрешён (только 80 и 443)';
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } else {
            foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
                $ips[] = (string)$r['ip'];
            }
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                $ips[] = (string)$r['ipv6'];
            }
        }
        if ($ips === []) {
            return 'имя «' . $host . '» не найдено: проверьте, что сайт открывается';
        }
        foreach ($ips as $ip) {
            if (!Http::isPublicIp($ip)) {
                return 'адрес ведёт во внутреннюю сеть (' . $ip . '), такие сайты не переносятся';
            }
        }
        return null;
    }

    /** Free space problem for a capture of up to $mb megabytes, or null. */
    public static function diskProblem(int $mb): ?string
    {
        $need = (int)ceil($mb * 1.15) + 150;
        $dirs = [AGECMS_DATA => 'для данных сайта'];
        if (defined('AGECMS_UPLOADS')) {
            $dirs[(string)AGECMS_UPLOADS] = 'для файлов копии';
        }
        $seen = [];
        foreach ($dirs as $dir => $what) {
            $probe = $dir;
            while ($probe !== '' && $probe !== '/' && !is_dir($probe)) {
                $probe = dirname($probe);
            }
            $free = @disk_free_space($probe === '' ? '/' : $probe);
            $dev = @stat($probe)['dev'] ?? $probe;
            if ($free === false || isset($seen[$dev])) {
                continue;
            }
            $seen[$dev] = true;
            if ($free < $need * 1048576) {
                return 'на диске мало места ' . $what . ': свободно ' . (int)($free / 1048576) . ' МБ, для захвата до ' . $mb . ' МБ нужно не меньше ' . $need . ' МБ. Освободите место или уменьшите предел размера в настройках модуля.';
            }
        }
        return null;
    }

    /**
     * The short, clean environment of the tool and of the browser under it. $home and $tmp are folders of the job: the profile of the
     * browser and every temporary file live there and go away with the job.
     *
     * @return array<string,string>
     */
    public static function env(string $home, string $tmp): array
    {
        $path = (string)getenv('PATH');
        $node = self::node();
        if ($node !== null) {
            $path = dirname($node) . PATH_SEPARATOR . $path;
        }
        $env = ['PATH' => $path !== '' ? $path : '/usr/local/bin:/usr/bin:/bin', 'HOME' => $home, 'TMPDIR' => $tmp, 'TMP' => $tmp, 'TEMP' => $tmp, 'LANG' => 'C.UTF-8', 'TZ' => 'UTC'];
        if (self::chrome() !== null) {
            $env['AGECMS_CHROME'] = (string)self::chrome();
        }
        if (self::node() !== null) {
            $env['AGECMS_NODE'] = (string)self::node();
        }
        if (self::browsersPath() !== null) {
            $env['PLAYWRIGHT_BROWSERS_PATH'] = (string)self::browsersPath();
        }
        return $env;
    }

    /**
     * Runs a command to its end (or to the time limit) and returns its output. No shell.
     *
     * @param list<string> $args
     * @param array<string,string> $env
     * @return array{code:int,out:string,err:string,timeout:bool}
     */
    public static function exec(array $args, array $env, int $timeout, ?string $cwd = null): array
    {
        $proc = @proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return ['code' => -1, 'out' => '', 'err' => 'не удалось запустить программу', 'timeout' => false];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $end = microtime(true) + $timeout;
        $timedOut = false;
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            if (microtime(true) > $end) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(50000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (isset($st) && $st['running'] === false && $st['exitcode'] >= 0) {
            $code = $st['exitcode'];
        }
        return ['code' => $timedOut ? -1 : $code, 'out' => $out, 'err' => $timedOut ? ($err . ' время ожидания вышло') : $err, 'timeout' => $timedOut];
    }

    /**
     * Captures a site (address, Tilda export or saved folder) into a new folder and returns the folder. Command line: waits for the tool.
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
        if (self::chrome() !== null) {
            $args[] = '--chrome=' . self::chrome();
        }
        if (self::allowPrivate()) {
            $args[] = '--allow-private';
        }
        $env = self::env((string)getenv('HOME') !== '' ? (string)getenv('HOME') : sys_get_temp_dir(), sys_get_temp_dir());
        $proc = @proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname(self::script()), $env);
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
