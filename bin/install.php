<?php
declare(strict_types=1);

// Usage: php bin/install.php --url=https://example.com [--name="Site"] [--admin=admin] [--password=...] [--no-demo]
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\Installer;

$opts = getopt('', ['url:', 'name::', 'description::', 'admin::', 'password::', 'no-demo', 'help']);
if (isset($opts['help']) || !isset($opts['url'])) {
    fwrite(STDERR, "Usage: php bin/install.php --url=https://example.com [--name=\"Site name\"] [--admin=admin] [--password=...] [--no-demo]\n");
    exit(isset($opts['help']) ? 0 : 1);
}

try {
    $result = Installer::install([
        'site_url' => (string)$opts['url'],
        'site_name' => is_string($opts['name'] ?? null) ? $opts['name'] : 'AgeCMS',
        'site_description' => is_string($opts['description'] ?? null) ? $opts['description'] : '',
        'admin_user' => is_string($opts['admin'] ?? null) ? $opts['admin'] : 'admin',
        'admin_password' => is_string($opts['password'] ?? null) ? $opts['password'] : null,
        'demo' => !isset($opts['no-demo']),
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Installation failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "AgeCMS installed.\n";
echo "Site:     {$result['site_url']}\n";
echo "Admin:    {$result['admin_user']}\n";
if ($result['generated']) {
    echo "Password: {$result['admin_password']}   (shown once, store it now)\n";
}
echo "Next: point the web server at this folder, open {$result['site_url']}/admin/\n";
