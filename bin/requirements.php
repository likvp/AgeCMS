<?php
declare(strict_types=1);

// Usage: php bin/requirements.php [--json] [--strict]
// Checks what AgeCMS needs from the server. Run it BEFORE php bin/install.php, install whatever is missing (the "fix" column
// says how), restart PHP-FPM / the web server and run it again until nothing is missing. Note: this is the command-line PHP;
// the web server may use another one, so after the installation also open /admin/?s=system (section "Требования сервера")
// or call get_site_structure and look at "environment".
// Exit code: 0 everything is there, 1 something required is missing, 2 only recommended extensions are missing (1 as well with --strict).
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\Installer;

$opts = getopt('', ['json', 'strict']);
$checks = Installer::checks();
$missingRequired = array_values(array_filter($checks, static fn(array $c): bool => !$c['ok'] && $c['level'] === 'required'));
$missingRecommended = array_values(array_filter($checks, static fn(array $c): bool => !$c['ok'] && $c['level'] === 'recommended'));

if (isset($opts['json'])) {
    echo json_encode([
        'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'ini' => php_ini_loaded_file() ?: null,
        'ok' => $missingRequired === [] && $missingRecommended === [],
        'missing_required' => array_column($missingRequired, 'label'), 'missing_recommended' => array_column($missingRecommended, 'label'),
        'checks' => $checks,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo 'PHP ' . PHP_VERSION . ' (' . (php_ini_loaded_file() ?: 'php.ini не найден') . ")\n";
    foreach ($checks as $c) {
        $mark = $c['ok'] ? 'OK     ' : ($c['level'] === 'required' ? 'НЕТ    ' : 'СОВЕТ  ');
        echo $mark . $c['label'] . ($c['note'] !== '' ? ' - ' . $c['note'] : '') . "\n";
        if (!$c['ok'] && $c['fix'] !== '') {
            echo '         как поставить: ' . $c['fix'] . "\n";
        }
    }
    if ($missingRequired !== []) {
        echo "\nБез обязательного установка невозможна. Поставьте недостающее, перезапустите PHP и запустите проверку снова.\n";
    } elseif ($missingRecommended !== []) {
        echo "\nУстановка возможна, но часть возможностей будет выключена. Поставьте расширения из строк СОВЕТ и запустите проверку снова.\n";
    } else {
        echo "\nВсё необходимое есть.\n";
    }
}
exit($missingRequired !== [] ? 1 : (($missingRecommended !== [] && isset($opts['strict'])) ? 1 : ($missingRecommended !== [] ? 2 : 0)));
