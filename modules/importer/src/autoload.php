<?php
declare(strict_types=1);

// Class loader of the importer. Registered once per process: the module may be required from more than one folder
// (the tests copy modules into a sandbox), and the first registration wins.
if (!defined('AGECMS_IMPORTER_SRC')) {
    define('AGECMS_IMPORTER_SRC', __DIR__);
    spl_autoload_register(static function (string $class): void {
        $prefix = 'AgeCMS\\Modules\\Importer\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = AGECMS_IMPORTER_SRC . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
