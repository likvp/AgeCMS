<?php
declare(strict_types=1);

// Router script for `php -S` in tests and benchmarks: mimics the rewrite rules of .htaccess.
$root = dirname(__DIR__, 2);
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path === '/admin' || $path === '/admin/') {
    $path = '/admin/index.php';
}
if (preg_match('~^/admin/(index|api|mcp|install)\.php$~', $path) === 1) {
    $_SERVER['SCRIPT_NAME'] = $path;
    require $root . $path;
    return true;
}
if (preg_match('~^/(themes/[a-z0-9_-]+/assets|assets/uploads|admin/assets)/[A-Za-z0-9_./-]+$~', $path) === 1 && !str_contains($path, '..') && is_file($root . $path)) {
    return false;
}
require $root . '/index.php';
return true;
