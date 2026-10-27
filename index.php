<?php
declare(strict_types=1);

// Level 3 of the shadow cache: if a rendered copy of the page exists, send it without loading the framework.
// Web servers normally answer from assets/cache/ before PHP starts (see .htaccess, nginx.vhost.example).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_SERVER['QUERY_STRING'] ?? '') === '' && !isset($_COOKIE['agecms_admin'])) {
    $raw = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $slug = $raw === '/' ? 'index' : substr($raw, 1);
    if (preg_match('~^[a-z0-9][a-z0-9_/-]*$~', $slug) === 1 && !str_contains($slug, '//') && !str_ends_with($slug, '/') && ($slug !== 'index' || $raw === '/') && is_file($file = (getenv('AGECMS_CACHE_DIR') ?: __DIR__ . '/assets/cache') . '/' . $slug . '.html')) {
        $mtime = (int)filemtime($file);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=0, must-revalidate');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('X-AgeCMS-Cache: hit');
        foreach (require __DIR__ . '/core/security-headers.php' as $name => $value) {
            header($name . ': ' . $value);
        }
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime) {
            http_response_code(304);
            exit;
        }
        readfile($file);
        exit;
    }
}

require __DIR__ . '/core/bootstrap.php';
AgeCMS\Router::handle();
