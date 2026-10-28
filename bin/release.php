<?php
declare(strict_types=1);

// Builds a signed core release that sites can install from «Система» (or by themselves with updates.auto).
//   php bin/release.php --version=1.1.0 --notes="Что изменилось" --key=<ed25519 secret file> [--key-id=agecms-2026]
//                       [--url=<changelog link>] [--package-url=<https address of the package>] [--out=dist]
// Writes core/release.json (version, date, notes), then <out>/agecms-<version>.json.gz and <out>/release.json.
// Upload both files to one public https folder and point updates.url of the sites at that release.json.
// Without --package-url the package is referenced by its file name, next to release.json.
// The public key of --key must be in core/release-keys.json of the sites (php bin/sign-module.php --keygen=<file>
// makes a pair). Commit core/release.json after the build, so the repository says which version it is.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Updater, Updates};

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $opts[$k] = $v;
    }
}

try {
    $version = (string)($opts['version'] ?? '');
    if (preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $version) !== 1) {
        throw new RuntimeException('--version=x.y.z is required');
    }
    $secret = base64_decode(trim((string)@file_get_contents((string)($opts['key'] ?? ''))), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('--key=<ed25519 secret key file> is required');
    }
    $keyId = (string)($opts['key-id'] ?? 'agecms-2026');
    $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    if ((Updater::trustedKeys()[$keyId] ?? '') !== $public) {
        throw new RuntimeException("The public key of --key is not core/release-keys.json[\"{$keyId}\"]: sites would refuse the package");
    }
    $root = dirname(__DIR__);
    $out = rtrim((string)($opts['out'] ?? $root . '/dist'), '/');
    if (!is_dir($out) && !mkdir($out, 0755, true)) {
        throw new RuntimeException("Cannot create {$out}");
    }

    $current = Updates::current();
    $release = [
        'version' => $version,
        'date' => gmdate('Y-m-d'),
        'notes' => (string)($opts['notes'] ?? ($current['version'] === $version ? $current['notes'] : '')),
        'url' => (string)($opts['url'] ?? ''),
    ];
    file_put_contents($root . '/core/release.json', json_encode($release, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");

    // Tracked and new (not ignored) files; outside a git checkout every file under the core folders.
    $list = [];
    exec('git -C ' . escapeshellarg($root) . ' ls-files --cached --others --exclude-standard 2>/dev/null', $list, $code);
    if ($code !== 0 || $list === []) {
        $list = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $list[] = ltrim(substr($f->getPathname(), strlen($root)), '/');
        }
    }
    $files = [];
    foreach ($list as $path) {
        if (Updater::allowedPath($path) && is_file("{$root}/{$path}") && basename($path) !== '.DS_Store') {
            $files[$path] = (string)file_get_contents("{$root}/{$path}");
        }
    }
    $package = Updater::pack($files, $version);
    $name = "agecms-{$version}.json.gz";
    file_put_contents("{$out}/{$name}", $package);

    $release['package'] = (string)($opts['package-url'] ?? $name);
    $release['sha256'] = hash('sha256', $package);
    $release['size'] = strlen($package);
    $release['signature'] = ['alg' => 'ed25519', 'key_id' => $keyId, 'sig' => base64_encode(sodium_crypto_sign_detached(Updater::signedMessage($release), $secret))];
    file_put_contents("{$out}/release.json", json_encode($release, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    if (Updater::verifyRelease($release) !== null) {
        throw new RuntimeException('The signature does not verify: ' . Updater::verifyRelease($release));
    }
    printf("AgeCMS %s: %d files, %d KB\n%s\n%s\n", $version, count($files), intdiv(strlen($package), 1024), "{$out}/{$name}", "{$out}/release.json");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
