<?php
declare(strict_types=1);

// php bin/config.php get <dotted.key>      (secrets are masked)
// php bin/config.php set <dotted.key> <value>   (value is JSON if it parses, otherwise a string)
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\Config;

$args = array_slice($argv, 1);
$protected = ['secret'];
switch ($args[0] ?? '') {
    case 'get':
        $key = $args[1] ?? '';
        if (in_array($key, $protected, true) || str_starts_with($key, 'secrets')) {
            echo "(hidden)\n";
            break;
        }
        echo json_encode(Config::get($key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
        break;
    case 'set':
        if (!isset($args[1], $args[2]) || in_array($args[1], $protected, true)) {
            fwrite(STDERR, "Usage: php bin/config.php set <dotted.key> <value>\n");
            exit(1);
        }
        $decoded = json_decode($args[2], true);
        Config::set($args[1], json_last_error() === JSON_ERROR_NONE ? $decoded : $args[2]);
        AgeCMS\Audit::record(AgeCMS\Audit::SYSTEM, "Изменена настройка {$args[1]} из консоли");
        echo "Saved.\n";
        break;
    default:
        fwrite(STDERR, "Usage: php bin/config.php get|set <dotted.key> [value]\n");
        exit(1);
}
