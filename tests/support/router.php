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
if ($uploads !== false && $uploads !== '' && preg_match('~^/assets/uploads/((?:r/)?[a-f0-9]{20}(?:-\d+)?\.(webp|avif))$~', $path, $m) === 1 && is_file($uploads . '/' . $m[1])) {
    header('Content-Type: image/' . $m[2]);
    readfile($uploads . '/' . $m[1]);
    return true;
}
// Files of copied pages live under uploads/copy/<key>/ (CopyPage); with a separate uploads folder they are served from there.
if ($uploads !== false && $uploads !== '' && preg_match('#^/assets/uploads/(copy/[a-z0-9]+/[A-Za-z0-9_./~-]+)$#', $path, $m) === 1 && !str_contains($m[1], '..') && is_file($uploads . '/' . $m[1])) {
    $ext = strtolower(pathinfo($m[1], PATHINFO_EXTENSION));
    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'json' => 'application/json', 'pdf' => 'application/pdf'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
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
