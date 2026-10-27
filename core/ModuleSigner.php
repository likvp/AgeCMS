<?php
declare(strict_types=1);

namespace AgeCMS;

/** Builds a module's file hash list and signs its manifest. Used by bin/sign-module.php and the installer. */
final class ModuleSigner
{
    /**
     * Rewrites manifest.json "files", "skills" (skills/*.json) and "migrations" (migrations/*.sql, in name order) and writes manifest.json.sig.
     * Without a secret key file the site's own HMAC key is used.
     *
     * @return array{files:int,alg:string}
     */
    public static function sign(string $name, ?string $ed25519SecretFile = null, string $keyId = 'publisher'): array
    {
        $dir = SkillRegistry::modulesDir() . '/' . $name;
        if (preg_match('/^[a-z0-9_]{1,40}$/', $name) !== 1 || !is_file($dir . '/manifest.json')) {
            throw new AgeCMSException("modules/{$name}/manifest.json does not exist");
        }
        $manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        $files = [];
        $skills = [];
        $migrations = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($dir)), '/');
            if (!$file->isFile() || $relative === 'manifest.json' || $relative === 'manifest.json.sig') {
                continue;
            }
            $files[$relative] = hash_file('sha256', $file->getPathname());
            if (preg_match('~^skills/[^/]+\.json$~', $relative) === 1) {
                $skills[] = $relative;
            }
            if (preg_match('~^migrations/[A-Za-z0-9_.-]+\.sql$~', $relative) === 1) {
                $migrations[] = $relative;
            }
        }
        ksort($files);
        sort($skills);
        sort($migrations);
        $manifest['name'] = $name;
        $manifest['files'] = $files;
        $manifest['skills'] = $skills;
        if ($migrations !== []) {
            $manifest['migrations'] = $migrations;
        } else {
            unset($manifest['migrations']);
        }
        $bytes = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

        if ($ed25519SecretFile !== null) {
            $secret = base64_decode(trim((string)@file_get_contents($ed25519SecretFile)), true);
            if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new AgeCMSException('Bad ed25519 secret key file');
            }
            $sig = ['alg' => 'ed25519', 'key_id' => $keyId, 'sig' => base64_encode(sodium_crypto_sign_detached($bytes, $secret))];
        } else {
            $sig = ['alg' => 'hmac-sha256', 'key_id' => 'local', 'sig' => Security::sign('module', $bytes)];
        }
        file_put_contents($dir . '/manifest.json', $bytes);
        file_put_contents($dir . '/manifest.json.sig', json_encode($sig) . "\n");
        return ['files' => count($files), 'alg' => $sig['alg']];
    }

    /** @return array{public:string} */
    public static function keygen(string $secretFile): array
    {
        $pair = sodium_crypto_sign_keypair();
        file_put_contents($secretFile, base64_encode(sodium_crypto_sign_secretkey($pair)));
        chmod($secretFile, 0600);
        return ['public' => base64_encode(sodium_crypto_sign_publickey($pair))];
    }
}
