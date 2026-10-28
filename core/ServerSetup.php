<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Installs missing PHP extensions from the admin, where the server lets PHP do it: the web user is root or has
 * passwordless sudo, exec is allowed and a package manager is there. Everywhere else (shared hosting, locked-down
 * containers) it only builds the exact commands and the text for the hosting support, nothing is run.
 * Package names come from the table below, never from the request.
 */
final class ServerSetup
{
    /** check label => [extension, apt package suffix, dnf package, apk package suffix] */
    private const PACKAGES = [
        'pdo_sqlite' => ['pdo_sqlite', 'sqlite3', 'php-pdo', 'pdo_sqlite'],
        'mbstring' => ['mbstring', 'mbstring', 'php-mbstring', 'mbstring'],
        'sodium' => ['sodium', 'common', 'php-sodium', 'sodium'],
        'zlib' => ['zlib', 'common', 'php-common', 'zlib'],
        'openssl' => ['openssl', 'common', 'php-common', 'openssl'],
        'gd с WebP' => ['gd', 'gd', 'php-gd', 'gd'],
        'curl' => ['curl', 'curl', 'php-common', 'curl'],
        'zip' => ['zip', 'zip', 'php-zip', 'zip'],
    ];

    /** Checks that a package can fix; the rest (PHP version, FTS5, file rights) is a hosting or ownership matter. */
    public static function installable(string $label): bool
    {
        return isset(self::PACKAGES[$label]);
    }

    /**
     * What this server lets PHP do.
     *
     * @return array{method:string,manager:?string,root:bool,sudo:bool,exec:bool,why:string}
     */
    public static function capabilities(): array
    {
        $exec = self::execAllowed();
        $manager = null;
        foreach (['apt-get' => 'apt', 'dnf' => 'dnf', 'yum' => 'dnf', 'apk' => 'apk'] as $bin => $name) {
            if (self::which($bin) !== null) {
                $manager = $name;
                break;
            }
        }
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $sudo = false;
        if ($exec && !$root && self::which('sudo') !== null) {
            $sudo = self::run(['sudo', '-n', 'true'], 10)['code'] === 0;
        }
        $why = '';
        if (!$exec) {
            $why = 'функции запуска программ отключены в PHP (disable_functions), это обычно хостинг';
        } elseif (is_file('/usr/local/bin/docker-php-ext-install')) {
            $why = 'это официальный Docker-образ php: расширения добавляются при сборке образа, а не из работающего сайта';
        } elseif ($manager === null) {
            $why = 'на сервере нет apt, dnf или apk (похоже на хостинг с панелью)';
        } elseif (!$root && !$sudo) {
            $why = 'PHP работает не от root и без sudo без пароля, поставить пакеты он не может';
        }
        return ['method' => $why === '' ? 'direct' : 'manual', 'manager' => $manager, 'root' => $root, 'sudo' => $sudo, 'exec' => $exec, 'why' => $why];
    }

    /**
     * Command that installs the given checks with a package manager, as argument list (no shell).
     *
     * @param list<string> $labels labels of checks from Installer::checks()
     * @return list<string>|null null when nothing installable is named or the manager is unknown
     */
    public static function command(array $labels, ?string $manager, bool $sudo, string $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION): ?array
    {
        $packages = [];
        foreach ($labels as $label) {
            if (!isset(self::PACKAGES[$label])) {
                continue;
            }
            [, $apt, $dnf, $apk] = self::PACKAGES[$label];
            $name = match ($manager) {
                'apt' => 'php' . $phpVersion . '-' . $apt,
                'dnf' => $dnf,
                'apk' => 'php' . str_replace('.', '', $phpVersion) . '-' . $apk,
                default => null,
            };
            if ($name !== null) {
                $packages[$name] = true;
            }
        }
        if ($packages === []) {
            return null;
        }
        $install = match ($manager) {
            'apt' => ['apt-get', 'install', '-y', '-q', '-o', 'DPkg::Lock::Timeout=120'],
            'dnf' => ['dnf', 'install', '-y', '-q'],
            'apk' => ['apk', 'add', '--no-cache'],
            default => null,
        };
        return $install === null ? null : array_merge($sudo ? ['sudo', '-n'] : [], $install, array_keys($packages));
    }

    /**
     * The words for a person who has to do it by hand: commands, panel steps and a ready text for hosting support.
     *
     * @param list<array{label:string,ok:bool,level:string,note:string,fix:string}> $missing
     */
    public static function manualText(array $missing, string $siteUrl): string
    {
        $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $names = implode(', ', array_column($missing, 'label'));
        $lines = ["Не хватает для AgeCMS: {$names}.", ''];
        foreach ([['apt', 'Debian/Ubuntu'], ['dnf', 'RHEL/Remi'], ['apk', 'Alpine']] as [$manager, $title]) {
            $cmd = self::command(array_column($missing, 'label'), $manager, false, $v);
            if ($cmd !== null) {
                $lines[] = $title . ': ' . ($manager === 'apk' ? '' : 'sudo ') . implode(' ', $cmd) . '  (затем перезапустить PHP-FPM)';
            }
        }
        $lines[] = 'Docker-образ php: в Dockerfile RUN docker-php-ext-install <расширение> (gd, zip, sodium, mbstring и т.д., для gd и zip сначала нужны библиотеки, проще взять mlocati/php-extension-installer: install-php-extensions ' . implode(' ', array_map(static fn(string $l): string => self::PACKAGES[$l][0] ?? '', array_column($missing, 'label'))) . ')';
        $lines[] = '';
        $lines[] = 'Хостинг с панелью (ISPmanager, cPanel, Plesk): раздел «Расширения PHP» или «Выбор версии PHP», включить недостающее для версии PHP этого сайта.';
        $lines[] = '';
        $lines[] = "Для поддержки хостинга: «Сайт {$siteUrl} работает на PHP {$v}. Включите, пожалуйста, расширения PHP: {$names}. Если для этой версии их нет, подскажите версию PHP, где они есть.»";
        return implode("\n", $lines);
    }

    /**
     * Installs what is installable among $labels. Never throws for a refused or failed installation: the answer says so.
     *
     * @param list<string> $labels
     * @return array{ok:bool,ran:bool,title:string,text:string}
     */
    public static function install(array $labels): array
    {
        $labels = array_values(array_filter($labels, [self::class, 'installable']));
        $missing = array_values(array_filter(Installer::checks(), static fn(array $c): bool => !$c['ok']));
        $missingNames = array_column($missing, 'label');
        $labels = array_values(array_intersect($labels, $missingNames));
        if ($labels === []) {
            return ['ok' => false, 'ran' => false, 'title' => 'Нечего ставить', 'text' => 'Из названного ничего не отсутствует или это нельзя поставить пакетом (версия PHP, FTS5 и права на файлы решаются на хостинге).'];
        }
        $cap = self::capabilities();
        $siteUrl = Seo::siteUrl();
        $wanted = array_values(array_filter($missing, static fn(array $c): bool => in_array($c['label'], $labels, true)));
        if ($cap['method'] !== 'direct') {
            return ['ok' => false, 'ran' => false, 'title' => 'С сайта это поставить нельзя: ' . $cap['why'], 'text' => self::manualText($wanted, $siteUrl)];
        }
        $command = self::command($labels, $cap['manager'], !$cap['root']);
        if ($command === null) {
            return ['ok' => false, 'ran' => false, 'title' => 'Не удалось собрать команду установки', 'text' => self::manualText($wanted, $siteUrl)];
        }
        @set_time_limit(400);
        $result = self::run($command, 360);
        $reloaded = $result['code'] === 0 ? self::reloadFpm($cap['root'] ? [] : ['sudo', '-n']) : '';
        $tail = implode("\n", array_slice(preg_split('/\R/', trim($result['output'])) ?: [], -25));
        Audit::record(Audit::SYSTEM, 'Установка расширений PHP с сайта (' . implode(', ', $labels) . '): ' . ($result['code'] === 0 ? 'выполнена' : 'не удалась, код ' . $result['code']));
        if ($result['code'] !== 0) {
            return ['ok' => false, 'ran' => true, 'title' => 'Установка не удалась (код ' . $result['code'] . ')', 'text' => $tail . "\n\n" . self::manualText($wanted, $siteUrl)];
        }
        return ['ok' => true, 'ran' => true, 'title' => 'Пакеты установлены', 'text' => $tail . "\n\n" . ($reloaded !== '' ? $reloaded : 'PHP-FPM не удалось перезапустить из PHP: перезапустите его вручную, затем обновите эту страницу.')];
    }

    /** Tries to reload PHP-FPM so that the new extension is loaded; returns a sentence about what was done, '' if nothing worked. */
    private static function reloadFpm(array $prefix): string
    {
        $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach (["php{$v}-fpm", 'php-fpm', 'php' . str_replace('.', '', $v) . '-php-fpm'] as $unit) {
            if (self::which('systemctl') !== null && self::run(array_merge($prefix, ['systemctl', 'reload', $unit]), 30)['code'] === 0) {
                return "Служба {$unit} перезагружена. Обновите страницу, чтобы увидеть результат.";
            }
        }
        return '';
    }

    private static function execAllowed(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    private static function which(string $bin): ?string
    {
        if (!self::execAllowed()) {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, (string)(getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin')) as $dir) {
            if ($dir !== '' && is_file($dir . '/' . $bin) && is_executable($dir . '/' . $bin)) {
                return $dir . '/' . $bin;
            }
        }
        return null;
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private static function run(array $command, int $seconds): array
    {
        if (!self::execAllowed()) {
            return ['code' => 127, 'output' => 'запуск программ отключён'];
        }
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'DEBIAN_FRONTEND' => 'noninteractive', 'LC_ALL' => 'C']);
        if (!is_resource($process)) {
            return ['code' => 127, 'output' => 'не удалось запустить команду'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = time() + $seconds;
        while (true) {
            $status = proc_get_status($process);
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) > 0) {
                foreach ($read as $pipe) {
                    $output .= (string)fread($pipe, 8192);
                }
            }
            if (!$status['running']) {
                foreach ([$pipes[1], $pipes[2]] as $pipe) {
                    $output .= (string)stream_get_contents($pipe);
                }
                break;
            }
            if (time() > $deadline) {
                proc_terminate($process);
                $output .= "\nвремя ожидания вышло";
                break;
            }
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($process);
        $code = $status['running'] ?? false ? 124 : (($status['exitcode'] ?? -1) >= 0 ? (int)$status['exitcode'] : $exit);
        return ['code' => $code, 'output' => mb_substr($output, -4000)];
    }
}
