<?php
declare(strict_types=1);

// Signs a module so SkillRegistry will load it.
//   php bin/sign-module.php <module>                                        hmac-sha256 with this site's key
//   php bin/sign-module.php <module> --ed25519-secret=<file> --key-id=<id>  publisher signature; the site must list the
//                                                                           public key under config trusted_publishers
//   php bin/sign-module.php --keygen=<secret-file>                          new ed25519 key pair, prints the public key
// Rebuilds the manifest's "files" (sha256 of every file) and "skills" (skills/*.json) before signing.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\ModuleSigner;

$opts = [];
$positional = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $opts[$k] = $v;
    } else {
        $positional[] = $a;
    }
}

try {
    if (isset($opts['keygen'])) {
        $pair = ModuleSigner::keygen($opts['keygen']);
        echo "Secret key written to {$opts['keygen']} (keep it off the server).\nPublic key: {$pair['public']}\n";
        exit(0);
    }
    $name = $positional[0] ?? '';
    $result = ModuleSigner::sign($name, $opts['ed25519-secret'] ?? null, $opts['key-id'] ?? 'publisher');
    echo "Signed {$name}: {$result['files']} files, {$result['alg']}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
