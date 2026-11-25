<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * What starts background work on time: bin/cron.php from the system cron, the run address /_/cron for hostings whose cron only
 * opens an address, and visits as before. Remembers who came last (data/scheduler/state.json) for the admin, and steps aside for
 * visits: while cron is alive they leave scheduled tasks to it.
 */
final class Cron
{
    public const PATH = '/_/cron';
    public const HEADER = 'X-AgeCMS-Cron';
    /** A cron run this recent: visits do not run tasks themselves. */
    private const FRESH = 150;
    /** Seconds after which a cron that used to come is called silent (or three of its usual gaps, if that is longer). */
    private const SILENT = 600;
    private const VISIT_EVERY = 30;

    /**
     * Everything in one go: tasks of modules first (they have a time), then background.tick and the housekeeping of the core.
     *
     * @param string $source 'cron' or 'url'
     * @return array{tasks:?array{done:int,failed:int,expired:int},background:?array{jobs:int,pings:int,rebuilt:int,ticked:bool}}|null null while a core update is being written
     */
    public static function run(string $source, float $budgetSeconds): ?array
    {
        if (self::updating()) {
            return null;
        }
        $started = microtime(true);
        self::seen($source, $started);
        $tasks = null;
        try {
            $tasks = Scheduler::run($budgetSeconds * 0.7);
        } catch (Throwable $e) {
            Logger::log('error', 'scheduler.failed', ['error' => $e->getMessage()]);
        }
        $background = Background::run(true, max(3.0, min(20.0, $budgetSeconds - (microtime(true) - $started))), false);
        return ['tasks' => $tasks, 'background' => $background];
    }

    /** Called for visits (Background::run): tasks only when no cron is near, at most every 30 seconds, for a short while. */
    public static function visit(float $budgetSeconds = 5.0): ?array
    {
        if (!Database::exists()) {
            return null; // not installed yet: nothing is written before that
        }
        $now = time();
        $stamp = Scheduler::dir() . '/.visit';
        $last = @filemtime($stamp);
        if ($last !== false && $now - $last < self::VISIT_EVERY) {
            return null;
        }
        @touch($stamp);
        if ($now - self::lastExternal() < self::FRESH || !Scheduler::due()) {
            return null;
        }
        $report = Scheduler::run($budgetSeconds);
        if ($report !== null) {
            self::seen('visit', microtime(true));
        }
        return $report;
    }

    // ---- who came last ----------------------------------------------------------------------------------------

    /** @return array<string,array{at:int,gap:int,php:string,user:string}> by source: cron, url, visit */
    public static function state(): array
    {
        $data = json_decode((string)@file_get_contents(AGECMS_DATA . '/scheduler/state.json'), true);
        $out = [];
        foreach (['cron', 'url', 'visit'] as $source) {
            $s = is_array($data) ? ($data[$source] ?? null) : null;
            if (is_array($s) && is_int($s['at'] ?? null)) {
                $out[$source] = ['at' => $s['at'], 'gap' => max(0, (int)($s['gap'] ?? 0)), 'php' => (string)($s['php'] ?? ''), 'user' => (string)($s['user'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * For the admin. level: ok (cron comes), warn (it used to come and is silent), none (never seen).
     *
     * @return array{level:string,source:?string,at:?int,last:?array{source:string,at:int}}
     */
    public static function status(?int $now = null): array
    {
        $now ??= time();
        $state = self::state();
        $external = array_diff_key($state, ['visit' => 1]);
        $newest = static function (array $list): ?string {
            uasort($list, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
            return array_key_first($list);
        };
        $any = $newest($state);
        $last = $any === null ? null : ['source' => $any, 'at' => $state[$any]['at']];
        $source = $newest($external);
        if ($source === null) {
            return ['level' => 'none', 'source' => null, 'at' => null, 'last' => $last];
        }
        $s = $external[$source];
        $silent = $now - $s['at'] > max(self::SILENT, min(86400, 3 * $s['gap']));
        return ['level' => $silent ? 'warn' : 'ok', 'source' => $source, 'at' => $s['at'], 'last' => $last];
    }

    private static function lastExternal(): int
    {
        $state = self::state();
        return max($state['cron']['at'] ?? 0, $state['url']['at'] ?? 0);
    }

    private static function seen(string $source, float $at): void
    {
        $handle = @fopen(Scheduler::dir() . '/state.json', 'c+');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $data = json_decode((string)stream_get_contents($handle), true);
            $data = is_array($data) ? $data : [];
            $before = (int)($data[$source]['at'] ?? 0);
            $data[$source] = ['at' => (int)$at, 'gap' => $before > 0 ? max(0, (int)$at - $before) : 0, 'php' => PHP_VERSION, 'user' => self::user()];
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)json_encode($data, JSON_UNESCAPED_UNICODE));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** A core update is replacing files right now (Updater::install holds this lock): starting on half of them is not safe. */
    public static function updating(): bool
    {
        $handle = @fopen(AGECMS_DATA . '/update.lock', 'r');
        if ($handle === false) {
            return false;
        }
        $free = flock($handle, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    // ---- the run address --------------------------------------------------------------------------------------

    public static function urlEnabled(): bool
    {
        return self::key() !== '';
    }

    /** Address with the secret, for the owner's eyes only; '' while it is switched off. */
    public static function url(): string
    {
        return self::urlEnabled() ? rtrim((string)Config::get('site_url', ''), '/') . self::PATH . '?key=' . self::key() : '';
    }

    /** A new secret; the old address stops working. */
    public static function renewKey(): void
    {
        Config::set('secrets.cron_key', Security::token(32));
    }

    public static function disableUrl(): void
    {
        Config::set('secrets.cron_key', '');
    }

    public static function keyMatches(string $given): bool
    {
        $key = self::key();
        return $key !== '' && strlen($given) <= 200 && hash_equals(hash('sha256', $key), hash('sha256', $given));
    }

    /**
     * Answers /_/cron. Says nothing about the site: 204 when the secret fits, 404 otherwise, 429 when asked too often. The secret
     * comes in the header X-AgeCMS-Cron (it stays out of the access log of the web server) or in ?key=.
     */
    public static function serve(string $method): never
    {
        $ip = Http::clientIp();
        if (!in_array($method, ['GET', 'POST'], true)) {
            self::answer(405, ['Allow' => 'GET, POST']);
        }
        if (!AntiSpam::rateLimit('cron:' . $ip, 20, 60)) {
            self::answer(429, ['Retry-After' => '60']);
        }
        $header = $_SERVER['HTTP_X_AGECMS_CRON'] ?? '';
        $given = is_string($header) && $header !== '' ? $header : ($_GET['key'] ?? '');
        if (!is_string($given) || !self::keyMatches($given)) {
            if (!AntiSpam::rateLimit('cron-bad:' . $ip, 10, 600)) {
                self::answer(429, ['Retry-After' => '600']);
            }
            self::answer(404);
        }
        if (!AntiSpam::rateLimit('cron-run', 10, 60)) {
            self::answer(429, ['Retry-After' => '60']);
        }
        // The caller gets its answer first where the server can do that; the work goes on if it hangs up.
        ignore_user_abort(true);
        $limit = (int)ini_get('max_execution_time');
        $budget = $limit > 0 ? max(5.0, min(25.0, $limit - 5.0)) : 25.0;
        self::headers(204, []);
        Http::finishResponse();
        try {
            self::run('url', $budget);
        } catch (Throwable $e) {
            Logger::log('error', 'scheduler.failed', ['error' => $e->getMessage()]);
        }
        exit;
    }

    /** @param array<string,string> $headers */
    private static function answer(int $status, array $headers = []): never
    {
        self::headers($status, $headers);
        exit;
    }

    /** @param array<string,string> $headers */
    private static function headers(int $status, array $headers): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        $base = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];
        foreach (array_replace(require __DIR__ . '/security-headers.php', $base, $headers) as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    private static function key(): string
    {
        $key = Config::get('secrets.cron_key', '');
        return is_string($key) ? $key : '';
    }

    // ---- what to write into cron ------------------------------------------------------------------------------

    /** The command of bin/cron.php for this site. */
    public static function command(): string
    {
        return self::quote(self::phpBinary()) . ' ' . self::quote(AGECMS_ROOT . '/bin/cron.php') . ' >/dev/null 2>&1';
    }

    /** A line for "crontab -e" of the site's user. */
    public static function crontabLine(): string
    {
        return '* * * * * ' . str_replace('%', '\\%', self::command());
    }

    /** A line for a file in /etc/cron.d, with the user. */
    public static function cronDLine(): string
    {
        return '* * * * * ' . self::owner() . ' ' . str_replace('%', '\\%', self::command());
    }

    /** One command for root of an own server that puts the line into /etc/cron.d. */
    public static function installCommand(): string
    {
        $host = (string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)parse_url((string)Config::get('site_url', ''), PHP_URL_HOST)));
        return "echo '" . str_replace("'", "'\\''", self::cronDLine()) . "' | sudo tee /etc/cron.d/agecms-" . (trim($host, '-') !== '' ? trim($host, '-') : 'site') . '-scheduler';
    }

    /** The user the site's files belong to: cron must run as that one, or it leaves files the site cannot write. */
    public static function owner(): string
    {
        $uid = @fileowner(AGECMS_DATA);
        $info = $uid !== false && function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        return is_array($info) && preg_match('/^[a-z_][a-z0-9_.-]*\$?$/i', (string)$info['name']) === 1 ? (string)$info['name'] : 'www-data';
    }

    /** PHP of the command line with the version of the site: under PHP-FPM PHP_BINARY is not it. */
    public static function phpBinary(): string
    {
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach ([PHP_BINDIR . '/php' . $version, PHP_BINDIR . '/php', '/usr/bin/php' . $version] as $file) {
            if (@is_executable($file)) {
                return $file;
            }
        }
        return 'php';
    }

    private static function quote(string $path): string
    {
        return preg_match('~^[A-Za-z0-9_./+-]+$~', $path) === 1 ? $path : "'" . str_replace("'", "'\\''", $path) . "'";
    }

    private static function user(): string
    {
        $info = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? @posix_getpwuid(posix_geteuid()) : false;
        return is_array($info) ? (string)$info['name'] : '';
    }
}
