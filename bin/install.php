<?php
declare(strict_types=1);

// Usage: php bin/install.php --url=https://example.com [--name="Site"] [--admin=admin] [--password=...] [--no-demo]
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\Installer;

$opts = getopt('', ['url:', 'name::', 'description::', 'admin::', 'password::', 'no-demo', 'strict', 'help']);
if (isset($opts['help']) || !isset($opts['url'])) {
    fwrite(STDERR, "Usage: php bin/install.php --url=https://example.com [--name=\"Site name\"] [--admin=admin] [--password=...] [--no-demo] [--strict]\n  --strict: refuse to install while a recommended PHP extension (sodium, gd, zip, curl, openssl) is missing. Run php bin/requirements.php first.\n");
    exit(isset($opts['help']) ? 0 : 1);
}

$missingRecommended = array_values(array_filter(Installer::checks(), static fn(array $c): bool => !$c['ok'] && $c['level'] === 'recommended'));
if ($missingRecommended !== []) {
    fwrite(STDERR, "Не хватает рекомендуемых расширений PHP, часть возможностей будет выключена:\n");
    foreach ($missingRecommended as $c) {
        fwrite(STDERR, '  - ' . $c['label'] . ': ' . $c['note'] . "\n    как поставить: " . $c['fix'] . "\n");
    }
    if (isset($opts['strict'])) {
        fwrite(STDERR, "Установка остановлена (--strict). Поставьте расширения, перезапустите PHP и запустите php bin/requirements.php.\n");
        exit(1);
    }
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
echo "Then check the web PHP (it can differ from this CLI one): admin, section Система, block Требования сервера.\n";
