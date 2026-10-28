<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Installs a newer core from the admin ("Обновить" in «Система»), from bin/update.php or by itself when
 * updates.auto is on. Only a package signed by a key from core/release-keys.json (or config
 * updates.trusted_keys) is installed, so whoever controls the download address still cannot run code here.
 *
 * Package: gzip of {"format":1,"version":"x.y.z","files":{"path":"base64"}}, built by bin/release.php.
 * Only core files are written (see allowedPath()): data/, assets/uploads/, assets/cache/, modules/ and
 * own themes are never touched. Before writing, the database is backed up and the files that are about to be
 * replaced are saved to data/backups/core-<version>-<time>.json.gz; if any write fails, they are put back.
 * Files that the new version no longer has are left in place.
 */
final class Updater
{
    private const MAX_PACKAGE = 31457280;
    private const KEEP_ROLLBACKS = 3;
    /** After a failed automatic install the same version is tried again only after this many seconds. */
    private const AUTO_RETRY = 86400;

    /** Where the files are written: the site root. Tests point AGECMS_UPDATE_ROOT at a sandbox. */
    public static function root(): string
    {
        return defined('AGECMS_UPDATE_ROOT') ? (string)AGECMS_UPDATE_ROOT : dirname(__DIR__);
    }

    public static function autoEnabled(): bool
    {
        return Config::get('updates.auto', false) === true;
    }

    /** True when a path from a package may be written: core code, admin, bin, the default theme, assets except uploads and cache. */
    public static function allowedPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 300 || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }
        if (in_array($path, ['index.php', '.htaccess'], true)) {
            return true;
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}$/', $part) !== 1 || str_contains($part, '..')) {
                return false;
            }
        }
        if (count($parts) < 2) {
            return false;
        }
        if (in_array($parts[0], ['core', 'admin', 'bin'], true)) {
            return true;
        }
        if ($parts[0] === 'themes') {
            return $parts[1] === 'default' && count($parts) > 2;
        }
        if ($parts[0] === 'assets') {
            return !in_array($parts[1], ['uploads', 'cache'], true);
        }
        return false;
    }

    /** @return array<string,string> key id => base64 ed25519 public key */
    public static function trustedKeys(): array
    {
        $keys = json_decode((string)@file_get_contents(__DIR__ . '/release-keys.json'), true);
        $keys = is_array($keys) ? $keys : [];
        $extra = Config::get('updates.trusted_keys', []);
        foreach (is_array($extra) ? $extra : [] as $id => $public) {
            $keys[(string)$id] = $public;
        }
        return array_filter($keys, static fn(mixed $k): bool => is_string($k) && $k !== '');
    }

    /** The bytes a release signature covers. */
    public static function signedMessage(array $release): string
    {
        return "agecms-release\n{$release['version']}\n{$release['sha256']}\n{$release['size']}\n";
    }

    /** Null when the release carries a valid signature from a trusted key, otherwise the reason. */
    public static function verifyRelease(array $release): ?string
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return 'На сервере нет расширения PHP sodium, подпись обновления не проверить';
        }
        if (($release['package'] ?? '') === '' || preg_match('/^[0-9a-f]{64}$/', (string)($release['sha256'] ?? '')) !== 1 || (int)($release['size'] ?? 0) <= 0) {
            return 'Для этой версии нет пакета для установки, обновите файлы вручную';
        }
        $sig = $release['signature'] ?? null;
        $keys = self::trustedKeys();
        if (!is_array($sig) || ($sig['alg'] ?? '') !== 'ed25519' || !isset($keys[(string)($sig['key_id'] ?? '')])) {
            return 'Пакет не подписан известным ключом';
        }
        $public = base64_decode($keys[(string)$sig['key_id']], true);
        $signature = base64_decode((string)($sig['sig'] ?? ''), true);
        if ($public === false || $signature === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'Подпись пакета повреждена';
        }
        return sodium_crypto_sign_verify_detached($signature, self::signedMessage($release), $public) ? null : 'Подпись пакета не сходится';
    }

    /** Absolute package URL: a relative "package" is resolved against the address of release.json. */
    public static function packageUrl(array $release, string $source): string
    {
        $package = (string)$release['package'];
        if (preg_match('~^https://~', $package) === 1) {
            return $package;
        }
        if (preg_match('~^[A-Za-z0-9._-]+$~', $package) !== 1) {
            return '';
        }
        return substr($source, 0, (int)strrpos($source, '/') + 1) . $package;
    }

    /**
     * Problems that stop an install before anything is downloaded: missing extensions, files PHP cannot write.
     *
     * @return list<string>
     */
    public static function preflight(): array
    {
        $problems = [];
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $problems[] = 'нет расширения PHP sodium';
        }
        if (!function_exists('gzdecode')) {
            $problems[] = 'нет расширения PHP zlib';
        }
        $root = self::root();
        $notWritable = [];
        foreach (['core', 'admin', 'bin', 'assets/admin', 'themes/default'] as $dir) {
            if (is_dir("{$root}/{$dir}") && !is_writable("{$root}/{$dir}")) {
                $notWritable[] = $dir;
            }
        }
        foreach (['index.php', 'core/bootstrap.php', 'core/release.json'] as $file) {
            if (is_file("{$root}/{$file}") && !is_writable("{$root}/{$file}")) {
                $notWritable[] = $file;
            }
        }
        if ($notWritable !== []) {
            $problems[] = 'PHP не может писать в ' . implode(', ', $notWritable) . ' (файлы сайта принадлежат другому пользователю)';
        }
        return $problems;
    }

    /**
     * Downloads, checks and installs the newer release that Updates found. $fetch replaces the download in tests.
     *
     * @param (callable(string):(array{status:int,body:string}|null))|null $fetch
     * @return array{from:string,to:string,files:int,rollback:string}
     */
    public static function install(?callable $fetch = null, string $by = 'admin'): array
    {
        $handle = @fopen(AGECMS_DATA . '/update.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new AgeCMSException('Обновление уже идёт, подождите минуту');
        }
        $from = Updates::version();
        $to = '';
        try {
            $release = Updates::available();
            if ($release === null) {
                throw new AgeCMSException('Новой версии нет, обновлять нечего');
            }
            $to = $release['version'];
            if (($problems = self::preflight()) !== []) {
                throw new AgeCMSException('Обновить отсюда нельзя: ' . implode('; ', $problems));
            }
            if (($reason = self::verifyRelease($release)) !== null) {
                throw new AgeCMSException($reason);
            }
            $url = self::packageUrl($release, Updates::sourceUrl());
            if ($url === '') {
                throw new AgeCMSException('Адрес пакета обновления не подходит');
            }
            $reply = $fetch !== null ? $fetch($url) : Http::get($url, 60, self::MAX_PACKAGE);
            if ($reply === null || $reply['status'] !== 200) {
                throw new AgeCMSException('Пакет обновления не скачался' . ($reply !== null ? ', код ' . $reply['status'] : ''));
            }
            if (strlen($reply['body']) !== (int)$release['size'] || !hash_equals((string)$release['sha256'], hash('sha256', $reply['body']))) {
                throw new AgeCMSException('Скачанный пакет не совпадает с подписанным, установка отменена');
            }
            $files = self::unpack($reply['body'], $to);
            $result = self::apply($files, $from, $to);
            Logger::log('info', 'updates.installed', ['from' => $from, 'to' => $to, 'files' => $result['files'], 'by' => $by]);
            self::remember(['ok' => true, 'from' => $from, 'to' => $to, 'by' => $by, 'error' => '']);
            return $result;
        } catch (Throwable $e) {
            Logger::log('error', 'updates.install_failed', ['from' => $from, 'to' => $to, 'by' => $by, 'error' => $e->getMessage()]);
            self::remember(['ok' => false, 'from' => $from, 'to' => $to, 'by' => $by, 'error' => $e->getMessage()]);
            throw $e instanceof AgeCMSException ? $e : new AgeCMSException('Обновление не удалось: ' . $e->getMessage());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Installs by itself when updates.auto is on and a newer signed release is known. A failed version is
     * tried again a day later, not on every visit. Called from the background work.
     */
    public static function autoInstallIfDue(): bool
    {
        $release = self::autoEnabled() ? Updates::available() : null;
        if ($release === null) {
            return false;
        }
        $last = self::last();
        if ($last !== null && !$last['ok'] && $last['to'] === $release['version'] && time() - $last['at'] < self::AUTO_RETRY) {
            return false;
        }
        try {
            self::install(null, 'auto');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,string> $files path => contents */
    public static function pack(array $files, string $version): string
    {
        ksort($files);
        return (string)gzencode((string)json_encode(['format' => 1, 'version' => $version, 'files' => array_map('base64_encode', $files)], JSON_UNESCAPED_SLASHES), 9);
    }

    /**
     * Unpacks a package and checks every path. Throws when the package is broken, is for another version
     * or tries to write outside the core.
     *
     * @return array<string,string> path => contents
     */
    public static function unpack(string $gzip, string $version): array
    {
        $json = @gzdecode($gzip, 104857600);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['files'] ?? null)) {
            throw new AgeCMSException('Пакет обновления повреждён');
        }
        if (($data['version'] ?? '') !== $version) {
            throw new AgeCMSException('Пакет собран для другой версии');
        }
        $files = [];
        foreach ($data['files'] as $path => $b64) {
            $bytes = is_string($b64) ? base64_decode($b64, true) : false;
            if (!is_string($path) || !self::allowedPath($path) || $bytes === false) {
                throw new AgeCMSException('В пакете файл вне ядра: ' . mb_substr((string)$path, 0, 120));
            }
            $files[$path] = $bytes;
        }
        $inner = Updates::parse($files['core/release.json'] ?? '');
        if (!isset($files['core/bootstrap.php'], $files['index.php']) || $inner === null || $inner['version'] !== $version) {
            throw new AgeCMSException('В пакете нет файлов ядра нужной версии');
        }
        return $files;
    }

    /**
     * Writes the files. The old ones go to a rollback package first; on any failure they are written back
     * and files that did not exist before are removed.
     *
     * @param array<string,string> $files
     * @return array{from:string,to:string,files:int,rollback:string}
     */
    public static function apply(array $files, string $from, string $to): array
    {
        $root = self::root();
        // Load what runs after the swap now, so this request does not mix old and new class files.
        foreach ([Cache::class, Logger::class, Backup::class, Database::class] as $class) {
            class_exists($class);
        }
        Backup::create();
        $old = [];
        $created = [];
        foreach (array_keys($files) as $path) {
            if (is_file("{$root}/{$path}")) {
                $old[$path] = base64_encode((string)file_get_contents("{$root}/{$path}"));
            } else {
                $created[] = $path;
            }
        }
        $rollback = self::saveRollback(['format' => 1, 'version' => $from, 'files' => $old, 'created' => $created], $from);

        $written = [];
        try {
            foreach ($files as $path => $bytes) {
                self::writeFile("{$root}/{$path}", $bytes);
                $written[] = $path;
            }
        } catch (Throwable $e) {
            foreach ($written as $path) {
                if (isset($old[$path])) {
                    @self::writeFile("{$root}/{$path}", (string)base64_decode($old[$path]));
                } else {
                    @unlink("{$root}/{$path}");
                }
            }
            self::resetOpcache();
            throw new AgeCMSException('Не удалось записать файлы, прежняя версия возвращена: ' . $e->getMessage());
        }
        self::resetOpcache();
        Updates::reset();
        try {
            Cache::clearAll();
        } catch (Throwable) {
            // Pages are re-rendered on the next visit anyway.
        }
        return ['from' => $from, 'to' => $to, 'files' => count($files), 'rollback' => basename($rollback)];
    }

    /**
     * Puts back the files from a rollback package written by apply(), newest when $file is null.
     * The database is not rolled back: a newer schema stays, older code ignores the columns it does not know.
     *
     * @return array{version:string,files:int}
     */
    public static function rollback(?string $file = null): array
    {
        $list = self::rollbacks();
        $name = $file ?? ($list[0] ?? null);
        if ($name === null || !in_array($name, $list, true)) {
            throw new AgeCMSException('Нет сохранённой прежней версии');
        }
        $data = json_decode((string)@gzdecode((string)file_get_contents(Backup::dir() . '/' . $name)), true);
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            throw new AgeCMSException('Файл прежней версии повреждён');
        }
        $root = self::root();
        foreach ($data['files'] as $path => $b64) {
            if (is_string($path) && self::allowedPath($path)) {
                self::writeFile("{$root}/{$path}", (string)base64_decode((string)$b64));
            }
        }
        foreach ((array)($data['created'] ?? []) as $path) {
            if (is_string($path) && self::allowedPath($path)) {
                @unlink("{$root}/{$path}");
            }
        }
        self::resetOpcache();
        Updates::reset();
        Cache::clearAll();
        Logger::log('warning', 'updates.rolled_back', ['to' => (string)($data['version'] ?? ''), 'file' => $name]);
        return ['version' => (string)($data['version'] ?? ''), 'files' => count($data['files'])];
    }

    /** @return list<string> rollback packages, newest first */
    public static function rollbacks(): array
    {
        $files = glob(Backup::dir() . '/core-*.json.gz') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
        return array_map('basename', $files);
    }

    /** @return array{ok:bool,from:string,to:string,by:string,error:string,at:int}|null the last install attempt */
    public static function last(): ?array
    {
        $data = json_decode((string)@file_get_contents(AGECMS_DATA . '/update-last.json'), true);
        if (!is_array($data) || !isset($data['ok'], $data['at'])) {
            return null;
        }
        return ['ok' => $data['ok'] === true, 'from' => (string)($data['from'] ?? ''), 'to' => (string)($data['to'] ?? ''), 'by' => (string)($data['by'] ?? ''), 'error' => (string)($data['error'] ?? ''), 'at' => (int)$data['at']];
    }

    private static function remember(array $attempt): void
    {
        @file_put_contents(AGECMS_DATA . '/update-last.json', json_encode($attempt + ['at' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function saveRollback(array $package, string $version): string
    {
        $dir = Backup::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new AgeCMSException("Cannot create {$dir}");
        }
        $file = $dir . '/core-' . $version . '-' . gmdate('Ymd-His') . '.json.gz';
        if (file_put_contents($file, gzencode((string)json_encode($package, JSON_UNESCAPED_SLASHES), 6)) === false) {
            throw new AgeCMSException('Не удалось сохранить прежнюю версию, обновление отменено');
        }
        @chmod($file, 0600);
        foreach (array_slice(self::rollbacks(), self::KEEP_ROLLBACKS) as $stale) {
            @unlink($dir . '/' . $stale);
        }
        return $file;
    }

    /** Writes next to the target and renames, so a reader never sees half a file. */
    private static function writeFile(string $target, string $bytes): void
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AgeCMSException('Нет прав создать ' . $dir);
        }
        $tmp = $dir . '/.agecms-update-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new AgeCMSException('Нет прав записать в ' . $dir);
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new AgeCMSException('Нет прав заменить ' . $target);
        }
    }

    private static function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
