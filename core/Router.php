<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;
use Throwable;

/** Front controller: system routes, form and beacon endpoints, then pages from the database. */
final class Router
{
    public static function handle(): void
    {
        // Leftover jobs, pings and re-renders are finished after a visit is answered, at most every 30 seconds.
        if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
            Background::afterResponse();
        }
        try {
            self::route();
        } catch (NotInstalledException $e) {
            self::send(503, 'text/plain; charset=utf-8', "AgeCMS is not installed.\nRun: php bin/install.php --url=https://your-site\n");
        } catch (Throwable $e) {
            Logger::log('error', 'request.failed', ['uri' => $_SERVER['REQUEST_URI'] ?? '', 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            self::send(500, 'text/plain; charset=utf-8', "Internal server error\n");
        }
    }

    private static function route(): void
    {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || !mb_check_encoding($path, 'UTF-8')) {
            self::send(400, 'text/plain; charset=utf-8', "Bad request\n");
        }

        if ($method === 'POST') {
            match ($path) {
                '/_/lead' => self::lead(),
                '/_/hit' => self::hit(),
                default => self::send(405, 'text/plain; charset=utf-8', "Method not allowed\n"),
            };
            return;
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            self::send(405, 'text/plain; charset=utf-8', "Method not allowed\n");
        }

        $indexNowKey = (string)Config::get('indexnow_key', '');
        switch (true) {
            case $path === '/sitemap.xml':
                self::send(200, 'application/xml; charset=utf-8', Seo::sitemap(), ['Cache-Control' => 'public, max-age=300']);
            case $path === '/robots.txt':
                self::send(200, 'text/plain; charset=utf-8', Seo::robots(), ['Cache-Control' => 'public, max-age=3600']);
            case $path === '/llms.txt':
                self::send(200, 'text/markdown; charset=utf-8', Seo::llms(), ['Cache-Control' => 'public, max-age=300']);
            case $path === '/AGENT.md':
                self::send(200, 'text/markdown; charset=utf-8', Seo::agentInstructions(), ['Cache-Control' => 'public, max-age=300']);
            case $indexNowKey !== '' && $path === '/' . $indexNowKey . '.txt':
                self::send(200, 'text/plain; charset=utf-8', $indexNowKey);
            case $path === '/_/token':
                self::send(200, 'application/json', json_encode(['token' => AntiSpam::issueToken()]), ['Cache-Control' => 'no-store']);
            case $path === '/search':
                self::search();
        }

        if ($path === '/index' || ($path !== '/' && str_ends_with($path, '/'))) {
            $target = $path === '/index' ? '/' : rtrim($path, '/');
            $query = (string)($_SERVER['QUERY_STRING'] ?? '');
            self::redirect($target . ($query !== '' ? '?' . $query : ''));
        }

        $slug = Cache::slugForPath($path);
        if ($slug === null) {
            self::notFound();
        }

        $editing = Auth::user() !== null;
        $generation = Cache::generation();
        $page = Renderer::renderSlug($slug, $editing, $editing ? Auth::csrf() : '');
        if ($page === null) {
            self::notFound();
        }
        $cacheable = !$editing && $method === 'GET' && ($_SERVER['QUERY_STRING'] ?? '') === '';
        if ($cacheable) {
            Cache::store($slug, $page['html'], $page['tags'], $generation);
        }
        self::send(200, 'text/html; charset=utf-8', $page['html'], [
            'Cache-Control' => $editing ? 'private, no-store' : 'public, max-age=0, must-revalidate',
            'X-AgeCMS-Cache' => 'miss',
        ]);
    }

    private static function notFound(): never
    {
        $html = Renderer::shell('Страница не найдена', '<section class="hero"><div class="container"><h1>404</h1><p class="lead">Такой страницы нет или она была удалена.</p><a class="btn" href="/">На главную</a></div></section>');
        self::send(404, 'text/html; charset=utf-8', $html, ['Cache-Control' => 'no-store']);
    }

    private static function search(): never
    {
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $items = '';
        foreach (Search::query($q) as $hit) {
            $items .= '<li><a href="' . ag_e('/' . ($hit['slug'] === 'index' ? '' : $hit['slug'])) . '">' . ag_e($hit['title']) . '</a>'
                . ($hit['snippet'] !== '' ? '<p>' . ag_e($hit['snippet']) . '</p>' : '') . '</li>';
        }
        $body = '<section class="section"><div class="container narrow"><h1>Поиск</h1>'
            . '<form class="search-form" action="/search" method="get"><input type="search" name="q" value="' . ag_e($q) . '" maxlength="100" placeholder="Что ищете?"><button class="btn" type="submit">Найти</button></form>'
            . ($q === '' ? '' : ($items === '' ? '<p>Ничего не найдено.</p>' : '<ul class="results">' . $items . '</ul>'))
            . '</div></section>';
        self::send(200, 'text/html; charset=utf-8', Renderer::shell('Поиск', $body), ['Cache-Control' => 'no-store']);
    }

    private static function lead(): never
    {
        self::sameSiteOnly();
        $ip = Http::clientIp();
        $in = self::input();

        if (!AntiSpam::validate($in)) {
            Http::json(['ok' => false, 'error' => 'Форма устарела, обновите страницу и попробуйте ещё раз.'], 400);
        }
        if (!AntiSpam::rateLimit('lead:' . $ip, 3, 60) || !AntiSpam::rateLimit('lead-day:' . $ip, 20, 86400)) {
            Http::json(['ok' => false, 'error' => 'Слишком много заявок, попробуйте позже.'], 429);
        }

        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($in['name'] ?? '')));
        $phone = trim((string)($in['phone'] ?? ''));
        $message = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($in['message'] ?? '')));
        $page = (string)($in['page'] ?? '');
        $errors = [];
        if (mb_strlen($name) > 100) {
            $errors[] = 'Имя слишком длинное';
        }
        if ($phone !== '' && preg_match('/^[0-9+()\-\s]{5,30}$/', $phone) !== 1) {
            $errors[] = 'Проверьте телефон';
        }
        if (mb_strlen($message) > 2000) {
            $errors[] = 'Сообщение слишком длинное';
        }
        if ($phone === '' && $message === '') {
            $errors[] = 'Укажите телефон или сообщение';
        }
        if ($errors !== []) {
            Http::json(['ok' => false, 'error' => implode('. ', $errors)], 422);
        }
        $page = Pages::slugValid($page) ? $page : '';

        Database::write(static function (PDO $pdo) use ($page, $name, $phone, $message, $ip): void {
            $pdo->prepare('INSERT INTO leads (created_at, page_slug, name, phone, message, ip_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([ag_now(), $page, $name, $phone, $message, AntiSpam::ipHash($ip)]);
            $day = gmdate('Y-m-d');
            $pdo->prepare('INSERT OR IGNORE INTO analytics_daily (date) VALUES (?)')->execute([$day]);
            $pdo->prepare('UPDATE analytics_daily SET leads_count = leads_count + 1 WHERE date = ?')->execute([$day]);
        });

        // Answer first: notifications (Telegram and similar) must not slow the visitor down.
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => true]);
        Http::finishResponse();
        EventBus::trigger('lead.created', ['name' => $name, 'phone' => $phone, 'message' => $message, 'page' => $page]);
        exit;
    }

    private static function hit(): never
    {
        $ip = Http::clientIp();
        $in = self::input();
        $p = $in['p'] ?? '';
        if (!is_string($p) || $p === '' || $p[0] !== '/' || strlen($p) > 200 || !AntiSpam::rateLimit('hit:' . $ip, 120, 60)) {
            self::send(204, 'text/plain', '');
        }
        $day = gmdate('Y-m-d');
        $visitor = substr(Security::sign('visitor', $day . '|' . $ip . '|' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)), 0, 16);
        Database::write(static function (PDO $pdo) use ($day, $visitor): void {
            $pdo->prepare('INSERT OR IGNORE INTO analytics_daily (date) VALUES (?)')->execute([$day]);
            $pdo->prepare('UPDATE analytics_daily SET views = views + 1 WHERE date = ?')->execute([$day]);
            $new = $pdo->prepare('INSERT OR IGNORE INTO visitors_daily (day, hash) VALUES (?, ?)');
            $new->execute([$day, $visitor]);
            if ($new->rowCount() > 0) {
                $pdo->prepare('UPDATE analytics_daily SET unique_visitors = unique_visitors + 1 WHERE date = ?')->execute([$day]);
            }
            $pdo->prepare('DELETE FROM visitors_daily WHERE day < ?')->execute([gmdate('Y-m-d', time() - 2 * 86400)]);
        });
        self::send(204, 'text/plain', '');
    }

    /** @return array<string,mixed> */
    private static function input(): array
    {
        if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
            $data = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
            return is_array($data) ? $data : [];
        }
        // sendBeacon posts text/plain.
        $raw = (string)file_get_contents('php://input', false, null, 0, 65536);
        $decoded = $raw !== '' && $raw[0] === '{' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : $_POST;
    }

    /** Rejects cross-site POSTs when the browser says where the request came from. */
    private static function sameSiteOnly(): void
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return;
        }
        if (parse_url($origin, PHP_URL_HOST) !== parse_url((string)Config::get('site_url', ''), PHP_URL_HOST)) {
            Http::json(['ok' => false, 'error' => 'Forbidden origin'], 403);
        }
    }

    private static function redirect(string $to): never
    {
        self::send(301, 'text/plain; charset=utf-8', '', ['Location' => $to]);
    }

    /** @param array<string,string> $headers */
    private static function send(int $status, string $type, string $body, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $type);
            foreach ((require __DIR__ . '/security-headers.php') + $headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && $status !== 204) {
            echo $body;
        }
        exit;
    }
}
