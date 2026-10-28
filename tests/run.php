<?php
declare(strict_types=1);

// php tests/run.php [FilterSubstring]
// Runs every tests/*Test.php. Each public method named test* gets a fresh sandbox under the system temp dir.
define('TEST_TMP', sys_get_temp_dir() . '/agecms-tests-' . getmypid());
define('AGECMS_DATA', TEST_TMP . '/data');
define('AGECMS_CACHE', TEST_TMP . '/cache');
define('AGECMS_MODULES', TEST_TMP . '/modules');
define('AGECMS_UPLOADS', TEST_TMP . '/uploads');
define('AGECMS_UPDATE_ROOT', TEST_TMP . '/site');

require __DIR__ . '/../core/bootstrap.php';
require __DIR__ . '/TestCase.php';

set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];
$assertions = 0;
$start = microtime(true);

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require_once $file;
    $class = basename($file, '.php');
    if (!class_exists($class)) {
        continue;
    }
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test') || ($filter !== '' && !str_contains("{$class}::{$method}", $filter))) {
            continue;
        }
        $case = new $class();
        $t = microtime(true);
        try {
            $case->$method();
            $passed++;
            echo '.';
        } catch (Throwable $e) {
            $failed[] = ["{$class}::{$method}", $e];
            echo 'F';
        }
        $assertions += $case->assertions;
        if (getenv('AGECMS_TEST_VERBOSE')) {
            fwrite(STDERR, sprintf("\n  %s::%s %.2fs", $class, $method, microtime(true) - $t));
        }
    }
}
TestEnv::remove(TEST_TMP);

echo sprintf("\n\n%d passed, %d failed, %d assertions, %.1fs\n", $passed, count($failed), $assertions, microtime(true) - $start);
foreach ($failed as [$name, $e]) {
    echo "\nFAIL {$name}\n  " . get_class($e) . ': ' . $e->getMessage() . "\n  at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
exit($failed === [] ? 0 : 1);
