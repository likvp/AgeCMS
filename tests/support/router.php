<?php
declare(strict_types=1);

// Router script for `php -S` in tests and benchmarks: mimics the rewrite rules of .htaccess.
$root = dirname(__DIR__, 2);
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path === '/admin' || $path === '/admin/') {
    $path = '/admin/index.php';
}
if (preg_match('~^/admin/(index|api|mcp|install|upload)\.php$~', $path) === 1) {
    $_SERVER['SCRIPT_NAME'] = $path;
    require $root . $path;
    return true;
}
// Uploads live outside the repository when AGECMS_UPLOADS_DIR is set (tests, the dev instance).
$uploads = getenv('AGECMS_UPLOADS_DIR');
if ($uploads !== false && $uploads !== '' && preg_match('~^/assets/uploads/([a-f0-9]{20}\.(webp|avif))$~', $path, $m) === 1 && is_file($uploads . '/' . $m[1])) {
    header('Content-Type: image/' . $m[2]);
    readfile($uploads . '/' . $m[1]);
    return true;
}
if (preg_match('~^/assets/(?!cache/)[A-Za-z0-9_./-]+$~', $path) === 1 && !str_contains($path, '..') && is_file($root . $path)) {
    return false;
}
// Pictures, styles and scripts of themes are plain files for the web server too (PHP of a theme never runs directly).
if (preg_match('~^/themes/[a-z0-9_]{1,40}/[A-Za-z0-9_./-]+\.(png|jpe?g|webp|avif|gif|svg|css|js|woff2?)$~', $path) === 1 && !str_contains($path, '..') && is_file($root . $path)) {
    return false;
}
require $root . '/index.php';
return true;
