<?php
declare(strict_types=1);

// REST endpoint for the in-page editor and simple scripts. POST JSON: {"action": "<tool>", ...arguments}.
// Auth: Bearer token, or the admin session plus the X-CSRF-Token header.
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Api, Background, AgeCMSException, AntiSpam, Auth, Config, Http, Logger, NotInstalledException, Seo};


// After the answer is sent: finish queued work, ping search engines, re-cache the pages that changed. No cron needed.
Background::afterResponse(true);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::json(['ok' => false, 'error' => 'POST only'], 405);
    }
    $body = json_decode((string)file_get_contents('php://input', false, null, 0, 1048576), true);

    // An agent gets its own token without a session: by a one-time invitation code from the owner, or by the owner's
    // login and password (see /AGENT.md). The code or the credentials are the proof, wrong ones count against limits.
    if (is_array($body) && in_array($body['action'] ?? null, ['issue_token', 'redeem_invite'], true)) {
        $issued = $body['action'] === 'redeem_invite'
            ? Auth::redeemInvite((string)($body['code'] ?? ''), (string)($body['name'] ?? ''))
            : Auth::issueTokenForCredentials((string)($body['username'] ?? ''), (string)($body['password'] ?? ''), (string)($body['name'] ?? ''), (string)($body['role'] ?? ''), isset($body['days']) ? (int)$body['days'] : null, ($body['own_user'] ?? true) !== false);
        $base = Seo::siteUrl();
        Http::json(['ok' => true, 'result' => $issued + [
            'note' => 'The token is shown once. Keep it. Never write it, the password or the code into site content. Check the expiry in expires_at.',
            'mcp' => $base . '/admin/mcp.php',
            'rest' => $base . '/admin/api.php',
            'instructions' => $base . '/AGENT.md',
        ]]);
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
        Http::json(['ok' => false, 'error' => Auth::authError(), 'instructions' => Seo::siteUrl() . '/AGENT.md'], 401);
    }
    if (($tokenInfo = Auth::currentToken()) !== null && $tokenInfo['warning'] !== null) {
        header('X-AgeCMS-Token-Warning: ' . $tokenInfo['warning']);
    }
    if (!AntiSpam::rateLimit('api:' . $actor->name, (int)Config::get('rate_limit.mcp_per_minute', 120), 60)) {
        Http::json(['ok' => false, 'error' => 'Rate limit exceeded'], 429);
    }

    if (!is_array($body) || !is_string($body['action'] ?? null)) {
        Http::json(['ok' => false, 'error' => 'Body must be JSON with a string "action"'], 400);
    }
    $action = $body['action'];
    unset($body['action']);

    Http::json(['ok' => true, 'result' => Api::call($actor, $action, $body)]);
} catch (NotInstalledException $e) {
    Http::json(['ok' => false, 'error' => 'Not installed'], 503);
} catch (Throwable $e) {
    if (!$e instanceof AgeCMSException) {
        Logger::log('error', 'api.failed', ['error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    }
    Http::json(['ok' => false, 'error' => $e instanceof AgeCMSException ? $e->getMessage() : 'Internal error'], Api::statusFor($e));
}
