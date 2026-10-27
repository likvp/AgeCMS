<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Module-as-a-Skill loader. A module is a folder in modules/ with a manifest.json that lists every file
 * with its sha256, and manifest.json.sig. The registry refuses a module when the signature is missing or wrong,
 * when a listed file changed, or when an executable file exists that the manifest does not list.
 * Signatures: hmac-sha256 with the site's own key (modules signed on this server by bin/sign-module.php),
 * or ed25519 with a publisher key listed in config trusted_publishers (modules from outside).
 */
final class SkillRegistry
{
    private const EXECUTABLE = ['php', 'phtml', 'phar', 'inc', 'php5', 'php7', 'php8', 'pht', 'htaccess'];

    /** @var array<string,array{status:string,reason:?string,manifest:?array<string,mixed>}>|null */
    private static ?array $modules = null;
    /** @var array<string,ModuleInterface> */
    private static array $instances = [];
    private static bool $booted = false;

    public static function modulesDir(): string
    {
        return defined('AGECMS_MODULES') ? AGECMS_MODULES : AGECMS_ROOT . '/modules';
    }

    public static function reset(): void
    {
        self::$modules = null;
        self::$instances = [];
        self::$booted = false;
    }

    /** @return array<string,array{status:string,reason:?string,manifest:?array<string,mixed>}> */
    public static function modules(): array
    {
        if (self::$modules !== null) {
            return self::$modules;
        }
        self::$modules = [];
        foreach (glob(self::modulesDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            try {
                self::$modules[$name] = ['status' => 'ok', 'reason' => null, 'manifest' => self::verify($dir, $name)];
            } catch (AgeCMSException $e) {
                self::$modules[$name] = ['status' => 'blocked', 'reason' => $e->getMessage(), 'manifest' => null];
                Logger::log('warning', 'module.blocked', ['module' => $name, 'reason' => $e->getMessage()]);
            }
        }
        return self::$modules;
    }

    /** Loads every verified module and lets it subscribe to events. Safe to call repeatedly. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        foreach (self::modules() as $name => $info) {
            if ($info['status'] !== 'ok') {
                continue;
            }
            try {
                self::instance($name)->register();
            } catch (Throwable $e) {
                self::$modules[$name] = ['status' => 'blocked', 'reason' => 'load failed: ' . $e->getMessage(), 'manifest' => null];
                Logger::log('error', 'module.load_failed', ['module' => $name, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Skill descriptors of all verified modules.
     *
     * @return list<array{module:string,name:string,description:string,permission:string,inputSchema:array<string,mixed>}>
     */
    public static function skills(): array
    {
        $out = [];
        foreach (self::modules() as $module => $info) {
            if ($info['status'] !== 'ok') {
                continue;
            }
            foreach ($info['manifest']['skills'] ?? [] as $file) {
                $skill = json_decode((string)file_get_contents(self::modulesDir() . "/{$module}/{$file}"), true);
                if (!is_array($skill) || !isset($skill['name'], $skill['description'])) {
                    continue;
                }
                $out[] = [
                    'module' => $module,
                    'name' => (string)$skill['name'],
                    'description' => (string)$skill['description'],
                    'permission' => (string)($skill['permission'] ?? 'skills.run'),
                    'inputSchema' => (array)($skill['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()]),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function call(string $module, string $skill, array $args, Actor $actor): array
    {
        foreach (self::skills() as $descriptor) {
            if ($descriptor['module'] === $module && $descriptor['name'] === $skill) {
                Security::requirePermission($actor, $descriptor['permission']);
                self::boot();
                return self::instance($module)->callSkill($skill, $args, $actor);
            }
        }
        throw new NotFoundException("Skill {$module}/{$skill} not found or its module is blocked");
    }

    private static function instance(string $name): ModuleInterface
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }
        $manifest = self::$modules[$name]['manifest'] ?? null;
        if ($manifest === null) {
            throw new NotFoundException("Module {$name} is not available");
        }
        require_once self::modulesDir() . "/{$name}/" . ($manifest['entry'] ?? 'Module.php');
        $class = (string)($manifest['class'] ?? '');
        if (!class_exists($class) || !is_subclass_of($class, ModuleInterface::class)) {
            throw new AgeCMSException("Module {$name}: class {$class} does not implement ModuleInterface");
        }
        return self::$instances[$name] = new $class();
    }

    /** @return array<string,mixed> the verified manifest */
    public static function verify(string $dir, string $name): array
    {
        $manifestFile = $dir . '/manifest.json';
        $sigFile = $dir . '/manifest.json.sig';
        if (!is_file($manifestFile) || !is_file($sigFile)) {
            throw new AgeCMSException('manifest.json or manifest.json.sig is missing');
        }
        $bytes = (string)file_get_contents($manifestFile);
        $sig = json_decode((string)file_get_contents($sigFile), true);
        if (!is_array($sig) || !isset($sig['alg'], $sig['sig'])) {
            throw new AgeCMSException('Signature file is malformed');
        }
        if (!self::signatureValid($bytes, $sig)) {
            throw new AgeCMSException('Signature does not match the manifest');
        }

        $manifest = json_decode($bytes, true);
        if (!is_array($manifest) || ($manifest['name'] ?? null) !== $name || preg_match('/^[a-z0-9_]{1,40}$/', $name) !== 1) {
            throw new AgeCMSException('Manifest name does not match the folder name');
        }
        $files = (array)($manifest['files'] ?? []);
        foreach ($files as $path => $hash) {
            if (!is_string($path) || preg_match('~^[A-Za-z0-9_./-]+$~', $path) !== 1 || str_contains($path, '..') || $path[0] === '/') {
                throw new AgeCMSException("Unsafe file path in manifest: {$path}");
            }
            $full = $dir . '/' . $path;
            if (!is_file($full)) {
                throw new AgeCMSException("Listed file is missing: {$path}");
            }
            if (!hash_equals((string)$hash, hash_file('sha256', $full))) {
                throw new AgeCMSException("File changed after signing: {$path}");
            }
        }
        foreach ((array)($manifest['skills'] ?? []) as $skillFile) {
            if (!isset($files[$skillFile])) {
                throw new AgeCMSException("Skill file is not covered by the manifest: {$skillFile}");
            }
        }

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($dir)), '/');
            $ext = strtolower($file->getExtension());
            if ($relative !== 'manifest.json' && $relative !== 'manifest.json.sig' && !isset($files[$relative])
                && (in_array($ext, self::EXECUTABLE, true) || $file->getFilename() === '.htaccess')) {
                throw new AgeCMSException("Unlisted executable file: {$relative}");
            }
        }
        return $manifest;
    }

    /** @param array<string,mixed> $sig */
    private static function signatureValid(string $bytes, array $sig): bool
    {
        if ($sig['alg'] === 'hmac-sha256') {
            return Security::verify('module', $bytes, (string)$sig['sig']);
        }
        if ($sig['alg'] === 'ed25519' && function_exists('sodium_crypto_sign_verify_detached')) {
            $publishers = (array)Config::get('trusted_publishers', []);
            $public = base64_decode((string)($publishers[(string)($sig['key_id'] ?? '')] ?? ''), true);
            $signature = base64_decode((string)$sig['sig'], true);
            return $public !== false && $signature !== false
                && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($signature, $bytes, $public);
        }
        return false;
    }
}
