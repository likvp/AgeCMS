<?php
declare(strict_types=1);

if (defined('AGECMS_BOOTED')) {
    return;
}
define('AGECMS_BOOTED', true);
if (!headers_sent()) {
    header_remove('X-Powered-By');
}

defined('AGECMS_ROOT') || define('AGECMS_ROOT', dirname(__DIR__));
defined('AGECMS_DATA') || define('AGECMS_DATA', getenv('AGECMS_DATA_DIR') ?: AGECMS_ROOT . '/data');
defined('AGECMS_CACHE') || define('AGECMS_CACHE', getenv('AGECMS_CACHE_DIR') ?: AGECMS_ROOT . '/assets/cache');
defined('AGECMS_MODULES') || define('AGECMS_MODULES', getenv('AGECMS_MODULES_DIR') ?: AGECMS_ROOT . '/modules');
defined('AGECMS_UPLOADS') || define('AGECMS_UPLOADS', getenv('AGECMS_UPLOADS_DIR') ?: AGECMS_ROOT . '/assets/uploads');

require __DIR__ . '/Exceptions.php';
require __DIR__ . '/helpers.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'AgeCMS\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (PHP_SAPI === 'cli') {
    // Command-line tools print a short message instead of a stack trace.
    set_exception_handler(static function (Throwable $e): void {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    });
}
