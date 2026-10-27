<?php
declare(strict_types=1);

// Image upload for the admin: multipart POST with field "file". Auth: the admin session plus the
// X-CSRF-Token header, or a Bearer token. The image is re-encoded to WebP, so the original is never stored.
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{AgeCMSException, AntiSpam, Api, Auth, Config, Http, Logger, Media, NotInstalledException, Security};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::json(['ok' => false, 'error' => 'POST only'], 405);
    }
    $bearer = Http::bearer();
    if ($bearer !== null) {
        $actor = Auth::tokenActor($bearer);
    } else {
        $actor = Auth::actor();
        if ($actor !== null && !Auth::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            Http::json(['ok' => false, 'error' => 'Bad CSRF token, reload the page'], 403);
        }
    }
    if ($actor === null) {
        Http::json(['ok' => false, 'error' => 'Authentication required'], 401);
    }
    Security::requirePermission($actor, 'content.write');
    if (!AntiSpam::rateLimit('upload:' . $actor->name, (int)Config::get('rate_limit.upload_per_minute', 30), 60)) {
        Http::json(['ok' => false, 'error' => 'Rate limit exceeded'], 429);
    }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        Http::json(['ok' => false, 'error' => 'Send the image in the multipart field "file". Files above the server limit arrive empty.'], 422);
    }
    $path = Media::saveUpload($_FILES['file']);
    Logger::log('info', 'media.uploaded', ['path' => $path, 'actor' => $actor->name]);
    Http::json(['ok' => true, 'result' => ['path' => $path]]);
} catch (NotInstalledException $e) {
    Http::json(['ok' => false, 'error' => 'Not installed'], 503);
} catch (Throwable $e) {
    if (!$e instanceof AgeCMSException) {
        Logger::log('error', 'upload.failed', ['error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    }
    Http::json(['ok' => false, 'error' => $e instanceof AgeCMSException ? $e->getMessage() : 'Internal error'], Api::statusFor($e));
}
