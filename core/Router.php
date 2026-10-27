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
        // The page-view beacon counts as a visit: pages served straight from the cache never start PHP themselves.
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (in_array($method, ['GET', 'HEAD'], true) || ($method === 'POST' && parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === '/_/hit')) {
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
                default => self::moduleOr405($method, $path),
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

        $module = ModuleRoutes::match($method, $path);
        if ($module !== null) {
            self::module($module, $method, $path);
        }

        $slug = Cache::slugForPath($path);
        if ($slug === null) {
            self::notFound();
        }

        $editing = Auth::user() !== null;
        $generation = Cache::generation();
        $page = Renderer::renderSlug($slug, $editing, $editing ? Auth::csrf() : '');
        if ($page === null) {
            // A page that moved leaves a permanent redirect at its old address.
            $to = Pages::redirectFor($slug);
            if ($to !== null) {
                $query = (string)($_SERVER['QUERY_STRING'] ?? '');
                self::redirect('/' . ($to === 'index' ? '' : $to) . ($query !== '' ? '?' . $query : ''));
            }
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

    private static function moduleOr405(string $method, string $path): never
    {
        $module = ModuleRoutes::match($method, $path);
        if ($module === null) {
            self::send(405, 'text/plain; charset=utf-8', "Method not allowed\n");
        }
        self::module($module, $method, $path);
    }

    /**
     * Answers an address that a module owns. The core does the work that must not be left to a module: the request
     * limit, the same-site and form-token checks for POST, the security headers, the cache policy and the error page.
     *
     * @param array{route:?array<string,mixed>,params:array<string,string>,allow:list<string>} $hit
     */
    private static function module(array $hit, string $method, string $path): never
    {
        $route = $hit['route'];
        if ($route === null) {
            self::send(405, 'text/plain; charset=utf-8', "Method not allowed\n", ['Allow' => implode(', ', $hit['allow'])]);
        }
        $name = (string)$route['module'];
        $post = $method === 'POST';
        $ip = Http::clientIp();

        if (!AntiSpam::rateLimit('mod:' . $name . ':' . $route['path'] . ':' . ($post ? 'p' : 'g') . ':' . $ip, $route['rate']['limit'], $route['rate']['window'])) {
            self::moduleReply($route, 429, 'Слишком много запросов', '<section class="section"><div class="container narrow"><h1>Слишком много запросов</h1><p>Попробуйте чуть позже.</p></div></section>', ['Retry-After' => (string)$route['rate']['window']]);
        }

        $body = [];
        if ($post) {
            self::sameSiteOnly();
            if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
                self::moduleReply($route, 403, 'Запрос отклонён', '<section class="section"><div class="container narrow"><h1>Запрос отклонён</h1></div></section>');
            }
            $body = self::input();
            if (!AntiSpam::validate($body)) {
                self::moduleReply($route, 400, 'Форма устарела', '<section class="section"><div class="container narrow"><h1>Форма устарела</h1><p>Вернитесь на предыдущую страницу, обновите её и отправьте форму ещё раз.</p></div></section>');
            }
            unset($body['token'], $body['website_hp']);
        }

        try {
            $module = SkillRegistry::routed($name);
            $request = new ModuleRequest($name, $method === 'HEAD' ? 'GET' : $method, $path, $hit['params'], $_GET, $body, $ip, self::moduleCookies($name));
            $response = $module->handle($request);
        } catch (NotFoundException) {
            self::notFound();
        } catch (Throwable $e) {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            self::moduleReply($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1><p>Мы уже знаем об ошибке. Попробуйте позже.</p></div></section>');
        }
        if (!$response instanceof ModuleResponse) {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => 'handle() did not return a ModuleResponse']);
            self::moduleReply($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1></div></section>');
        }

        $status = $response->status;
        if ($response->kind === 'redirect') {
            $to = $response->body;
            if (!in_array($status, [302, 303], true) || preg_match('~^/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*$~', $to) !== 1) {
                Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => 'redirect must be 302 or 303 to an address on this site']);
                self::moduleReply($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1></div></section>');
            }
        } elseif ($status < 200 || $status > 599 || ($status >= 300 && $status < 400)) {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => "status {$status} is not allowed"]);
            self::moduleReply($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1></div></section>');
        }
        if ($route['cache'] === 'public' && $response->cookies() !== []) {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => 'a public route cannot set cookies']);
            self::moduleReply($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1></div></section>');
        }
        self::moduleSetCookies($name, $response);

        if ($response->kind === 'redirect') {
            self::moduleSend($route, $status, 'text/plain; charset=utf-8', '', ['Location' => $response->body]);
        }
        if ($response->kind === 'json') {
            self::moduleSend($route, $status, 'application/json; charset=utf-8', $response->body);
        }
        self::moduleReply($route, $status, $response->title, $response->body, [], $response->description(), $path);
    }

    /**
     * An HTML page of a module (or an error page for it), drawn inside the theme.
     *
     * @param array<string,mixed> $route
     * @param array<string,string> $headers
     */
    private static function moduleReply(array $route, int $status, string $title, string $bodyHtml, array $headers = [], string $description = '', string $path = ''): never
    {
        // Only a successful page of a route declared "indexable" goes to search engines; everything else stays noindex.
        $seo = $route['indexable'] && $status === 200 ? ['slug' => trim($path, '/'), 'description' => $description] : null;
        self::moduleSend($route, $status, 'text/html; charset=utf-8', Renderer::shell($title, $bodyHtml, false, $seo), $headers);
    }

    /**
     * Pages of modules are never written to the shadow cache. Unless the manifest marks the route public they
     * are private and not stored by browsers or proxies either (they may show personal data).
     *
     * @param array<string,mixed> $route
     * @param array<string,string> $headers
     */
    private static function moduleSend(array $route, int $status, string $type, string $body, array $headers = []): never
    {
        $cache = $route['cache'] === 'public' && $status === 200 ? 'public, max-age=' . $route['max_age'] : 'private, no-store';
        self::send($status, $type, $body, $headers + ['Cache-Control' => $cache, 'X-AgeCMS-Cache' => 'bypass']);
    }

    /** @return array<string,string> cookies of the module, without its prefix */
    private static function moduleCookies(string $module): array
    {
        $out = [];
        $prefix = $module . '_';
        foreach ($_COOKIE as $name => $value) {
            if (is_string($name) && is_string($value) && str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                $out[substr($name, strlen($prefix))] = $value;
            }
        }
        return $out;
    }

    private static function moduleSetCookies(string $module, ModuleResponse $response): void
    {
        $secure = str_starts_with((string)Config::get('site_url', ''), 'https://');
        foreach ($response->cookies() as $cookie) {
            if (preg_match('/^[a-z0-9_]{1,30}$/', $cookie['name']) !== 1 || preg_match('/^[A-Za-z0-9._~+\/=-]{0,1024}$/', $cookie['value']) !== 1) {
                Logger::log('warning', 'module.cookie_refused', ['module' => $module, 'name' => $cookie['name']]);
                continue;
            }
            $expires = $cookie['ttl'] < 0 ? time() - 86400 : ($cookie['ttl'] > 0 ? time() + min($cookie['ttl'], 31536000) : 0);
            setcookie($module . '_' . $cookie['name'], $cookie['ttl'] < 0 ? '' : $cookie['value'], ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        }
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
        Mailer::notifyLead(['name' => $name, 'phone' => $phone, 'message' => $message, 'page' => $page]);
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
