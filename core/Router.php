<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;
use Throwable;

/** Front controller: system routes, form and beacon endpoints, then pages from the database. */
final class Router
{
    public const EDIT_COOKIE = 'agecms_edit';

    /** Set for the answer that may be shown in a frame of the sites of security.frame_ancestors (see Embedding). Everything else stays strict. */
    private static bool $embed = false;

    public static function handle(): void
    {
        // Leftover jobs, pings and re-renders are finished after a visit is answered, at most every 30 seconds.
        // The page-view beacon counts as a visit: pages served straight from the cache never start PHP themselves.
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === Cron::PATH) {
            self::cron((string)$method);
        }
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

    /** The run address. Apart from the rest: no visit is counted, and an error never puts its query (the secret) into the log. */
    private static function cron(string $method): never
    {
        try {
            if (!Database::exists()) {
                self::send(404, 'text/plain; charset=utf-8', '', ['Cache-Control' => 'no-store']);
            }
            Cron::serve($method);
        } catch (Throwable $e) {
            Logger::log('error', 'request.failed', ['uri' => Cron::PATH, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            self::send(500, 'text/plain; charset=utf-8', '', ['Cache-Control' => 'no-store']);
        }
    }

    private static function route(): void
    {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || !mb_check_encoding($path, 'UTF-8')) {
            self::send(400, 'text/plain; charset=utf-8', "Bad request\n");
        }

        if (str_starts_with($path, ModuleAdmin::PREFIX)) {
            ModuleAdmin::serve($method, $path);
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
            case $path === '/rss.xml':
                self::send(200, 'application/rss+xml; charset=utf-8', Seo::rss(), ['Cache-Control' => 'public, max-age=300']);
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
            case $path === '/_/edit':
                self::toggleEdit();
            case $path === '/search':
                self::search();
        }

        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($path === '/index' || ($path !== '/' && str_ends_with($path, '/'))) {
            $target = $path === '/index' ? '/' : rtrim($path, '/');
            // An address of the old site with a slash at the end goes to its new page in one step.
            if ($path !== '/index' && ($to = Redirects::target($target, $query)) !== null) {
                self::redirect($to);
            }
            self::redirect($target . ($query !== '' ? '?' . $query : ''));
        }

        $module = ModuleRoutes::match($method, $path);
        if ($module !== null) {
            self::module($module, $method, $path);
        }

        // Addresses of the old site (Content::addRedirects): the path with its query (/?p=12 of WordPress), then the path alone.
        // A path alone never belongs to a page, so a page is never shadowed by it.
        if (($to = Redirects::target($path, $query)) !== null) {
            self::redirect($to);
        }

        $slug = Cache::slugForPath($path);
        if ($slug === null) {
            self::notFound();
        }

        // A signed-in user sees the site as visitors do (drafts included) and switches to the editor with the pencil.
        $signedIn = Auth::user() !== null;
        $editing = $signedIn && ($_COOKIE[self::EDIT_COOKIE] ?? '') === '1';
        $generation = Cache::generation();
        $variant = ($_GET['ag_view'] ?? '') === 'm' ? 'm' : '';
        $page = Renderer::renderSlug($slug, $editing, $editing ? Auth::csrf() : '', $signedIn, $variant);
        if ($page === null) {
            // A page that moved leaves a permanent redirect at its old address.
            $to = Pages::redirectFor($slug);
            if ($to !== null) {
                self::redirect('/' . ($to === 'index' ? '' : $to) . ($query !== '' ? '?' . $query : ''));
            }
            self::notFound();
        }
        if (($page['policy'] ?? '') === 'copy') {
            self::sendCopy($slug, $page['html'], $signedIn, $path, $method);
        }
        $cacheable = !$signedIn && $method === 'GET' && ($_SERVER['QUERY_STRING'] ?? '') === '';
        if ($cacheable) {
            Cache::store($slug, $page['html'], $page['tags'], $generation);
        }
        $html = $signedIn ? self::withEditButton($page['html'], $editing, $path) : $page['html'];
        self::$embed = !$signedIn;
        self::send(200, 'text/html; charset=utf-8', $html, [
            'Cache-Control' => $signedIn ? 'private, no-store' : 'public, max-age=0, must-revalidate',
            'X-AgeCMS-Cache' => 'miss',
        ]);
    }

    /**
     * A copied page (CopyPage): never stored in the shadow cache, sent with its own policy, validated with an ETag so a repeat
     * visit costs one small query. A signed-in user gets the pencil that leads to the page editor of the importer.
     */
    private static function sendCopy(string $slug, string $html, bool $signedIn, string $path, string $method): never
    {
        $doc = Pages::doc($slug) ?? [];
        $headers = CopyPage::headers(CopyPage::data($doc));
        $headers['X-AgeCMS-Cache'] = 'bypass';
        if ($signedIn) {
            $html = self::withEditButton($html, false, $path, '/admin/m/importer/copy?slug=' . rawurlencode($slug));
            $headers['Cache-Control'] = 'private, no-store';
        } else {
            $etag = '"' . substr(sha1($html), 0, 20) . '"';
            $headers['Cache-Control'] = 'public, max-age=0, must-revalidate';
            $headers['ETag'] = $etag;
            if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                self::send(304, 'text/html; charset=utf-8', '', $headers);
            }
        }
        self::send(200, 'text/html; charset=utf-8', $html, $headers);
    }

    /**
     * What a signed-in user gets on top of the page. A round button in the corner: the pencil opens the editor, in the editor the eye
     * returns to the plain view. In the editor also the black strip "Режим редактирования" right after <body>, unless the theme
     * draws its own (older themes have a .ag-bar in layout.php). Styles: assets/edit-button.css, loaded in <head>.
     */
    private static function withEditButton(string $html, bool $editing, string $path, ?string $editHref = null): string
    {
        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $html;
        }
        $label = $editing ? 'Выйти из режима редактирования' : 'Редактировать страницу';
        $icon = $editing
            ? '<path d="M1.5 12s3.8-7 10.5-7 10.5 7 10.5 7-3.8 7-10.5 7S1.5 12 1.5 12Z"/><circle cx="12" cy="12" r="3"/>'
            : '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m14.5 7.5 3 3"/>';
        $button = '<a class="ag-fab" href="' . ag_e($editHref ?? '/_/edit?to=' . rawurlencode($path)) . '" title="' . ag_e($label) . '" aria-label="' . ag_e($label) . '">'
            . '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icon . '</svg></a>';
        $html = substr($html, 0, $pos) . $button . substr($html, $pos);

        $css = '<link rel="stylesheet" href="' . ag_e(self::asset('/assets/edit-button.css')) . '">';
        $head = stripos($html, '</head>');
        $html = $head === false ? $css . $html : substr($html, 0, $head) . $css . "
" . substr($html, $head);

        if ($editing && preg_match('/<[a-z][a-z0-9]*\s[^>]*class\s*=\s*["\'][^"\']*(?<![\w-])ag-bar(?![\w-])/i', $html) !== 1
            && preg_match('/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $at) . "\n" . self::editBar() . substr($html, $at);
        }
        return $html;
    }

    /** The strip of the page editor: what mode this is, how saving goes and the way back to the admin. editor.js fills #ag-status. */
    public static function editBar(): string
    {
        return '<div class="ag-bar" data-ag-bar role="region" aria-label="Режим редактирования">'
            . '<strong>Режим редактирования</strong>'
            . '<span class="ag-bar-tip">Нажмите на текст, чтобы изменить. Наведите курсор на блок, чтобы переместить или изменить его. Правки сохраняются сами.</span>'
            . '<span class="ag-bar-end"><span id="ag-status" role="status" aria-live="polite"></span>'
            . '<a class="ag-bar-admin" href="/admin/?s=pages">Админка</a></span></div>';
    }

    /** Address of a core file with ?v=mtime, so a core update reaches browsers that keep the old file. */
    private static function asset(string $file): string
    {
        return $file . '?v=' . (is_file(AGECMS_ROOT . $file) ? filemtime(AGECMS_ROOT . $file) : 1);
    }

    /** Switches the page editor on or off for the signed-in user and goes back to the page they came from (?to=). */
    private static function toggleEdit(): never
    {
        if (Auth::user() === null) {
            self::send(302, 'text/plain; charset=utf-8', '', ['Location' => '/admin/', 'Cache-Control' => 'no-store']);
        }
        // ?on=1 and ?on=0 (links from the admin) set the mode, without it the pencil button switches it.
        $wanted = (string)($_GET['on'] ?? '');
        $on = $wanted === '1' || ($wanted !== '0' && ($_COOKIE[self::EDIT_COOKIE] ?? '') !== '1');
        $secure = str_starts_with((string)Config::get('site_url', ''), 'https://') || (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::EDIT_COOKIE, $on ? '1' : '', ['expires' => $on ? 0 : 1, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        $to = (string)($_GET['to'] ?? '/');
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_contains($to, '\\') || preg_match('/[\x00-\x1f]/', $to) === 1) {
            $to = '/';
        }
        self::send(302, 'text/plain; charset=utf-8', '', ['Location' => $to, 'Cache-Control' => 'no-store']);
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
     * An address declared "api" is for programs (a Telegram webhook, a mobile app): no form token and no cookies, the headers
     * the manifest names, a JSON body, and every answer, errors of the core included, is JSON. An address declared "upload"
     * takes pictures: the core checks them before the module is called and keeps none of them unless the module saves it.
     *
     * @param array{route:?array<string,mixed>,params:array<string,string>,allow:list<string>,api?:bool} $hit
     */
    private static function module(array $hit, string $method, string $path): never
    {
        $route = $hit['route'];
        if ($route === null) {
            if ($hit['api'] ?? false) {
                self::apiError(405, ['Allow' => implode(', ', $hit['allow'])]);
            }
            self::send(405, 'text/plain; charset=utf-8', "Method not allowed\n", ['Allow' => implode(', ', $hit['allow'])]);
        }
        $name = (string)$route['module'];
        $post = $method === 'POST';
        $api = (bool)$route['api'];
        $ip = Http::clientIp();

        if (!AntiSpam::rateLimit('mod:' . $name . ':' . $route['path'] . ':' . ($post ? 'p' : 'g') . ':' . $ip, $route['rate']['limit'], $route['rate']['window'])) {
            self::moduleFail($route, 429, 'Слишком много запросов', '<section class="section"><div class="container narrow"><h1>Слишком много запросов</h1><p>Попробуйте чуть позже.</p></div></section>', ['Retry-After' => (string)$route['rate']['window']]);
        }

        $body = [];
        $uploads = [];
        if ($post) {
            if ($api) {
                if (self::foreignOrigin() || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
                    self::apiError(403);
                }
                $body = self::apiInput($route);
            } else {
                self::sameSiteOnly();
                if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
                    self::moduleFail($route, 403, 'Запрос отклонён', '<section class="section"><div class="container narrow"><h1>Запрос отклонён</h1></div></section>');
                }
                $body = self::input();
            }
            // Pictures first: a file that is too big for PHP empties $_POST and $_FILES, and "the form is stale" would be a wrong answer.
            if ($route['upload'] !== null) {
                try {
                    self::assertUploadArrived();
                    if (!$api && !AntiSpam::validate($body)) {
                        self::moduleFail($route, 400, 'Форма устарела', '<section class="section"><div class="container narrow"><h1>Форма устарела</h1><p>Вернитесь на предыдущую страницу, обновите её и отправьте форму ещё раз.</p></div></section>');
                    }
                    $uploads = ModuleUpload::collect($name, $route['upload'], $_FILES, true);
                } catch (ValidationException $e) {
                    self::moduleFail($route, 400, 'Данные не приняты', '<section class="section"><div class="container narrow"><h1>Данные не приняты</h1><p>' . ag_e($e->getMessage()) . '</p></div></section>', [], ['error' => 'invalid_upload', 'message' => $e->getMessage()]);
                }
            } elseif (!$api && !AntiSpam::validate($body)) {
                self::moduleFail($route, 400, 'Форма устарела', '<section class="section"><div class="container narrow"><h1>Форма устарела</h1><p>Вернитесь на предыдущую страницу, обновите её и отправьте форму ещё раз.</p></div></section>');
            }
            if (!$api) {
                unset($body['token'], $body['website_hp']);
            }
        }

        try {
            $module = SkillRegistry::routed($name);
            $request = new ModuleRequest(
                $name, $method === 'HEAD' ? 'GET' : $method, $path, $hit['params'], $_GET, $body, $ip,
                $api ? [] : self::moduleCookies($name), $uploads, false, $api ? self::requestHeaders($route['headers']) : [],
            );
            $response = $module->handle($request);
        } catch (NotFoundException) {
            $api ? self::apiError(404) : self::notFound();
        } catch (ValidationException $e) {
            // A picture that passed the checks of the core but cannot be decoded (toMedia()) is the sender's fault, not an error of the module.
            if ($route['upload'] === null) {
                Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
                self::moduleFail($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1><p>Мы уже знаем об ошибке. Попробуйте позже.</p></div></section>');
            }
            self::moduleFail($route, 400, 'Данные не приняты', '<section class="section"><div class="container narrow"><h1>Данные не приняты</h1><p>' . ag_e($e->getMessage()) . '</p></div></section>', [], ['error' => 'invalid_upload', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            self::moduleFail($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1><p>Мы уже знаем об ошибке. Попробуйте позже.</p></div></section>');
        }
        if (!$response instanceof ModuleResponse) {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => 'handle() did not return a ModuleResponse']);
            self::moduleFail($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1></div></section>');
        }
        if ($api && $response->kind !== 'json') {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => 'an api route answers only with JSON']);
            self::moduleFail($route, 500, 'Ошибка', '');
        }

        $status = $response->status;
        if ($response->kind === 'redirect') {
            $to = $response->body;
            if (!in_array($status, [302, 303], true) || preg_match('~^/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*$~', $to) !== 1) {
                Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => 'redirect must be 302 or 303 to an address on this site']);
                self::moduleFail($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1></div></section>');
            }
        } elseif ($status < 200 || $status > 599 || ($status >= 300 && $status < 400)) {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => "status {$status} is not allowed"]);
            self::moduleFail($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1></div></section>');
        }
        if (($route['cache'] === 'public' || $api) && $response->cookies() !== []) {
            Logger::log('error', 'module.route_failed', ['module' => $name, 'path' => $path, 'error' => $api ? 'an api route cannot set cookies' : 'a public route cannot set cookies']);
            self::moduleFail($route, 500, 'Ошибка', '<section class="section"><div class="container narrow"><h1>Что-то пошло не так</h1></div></section>');
        }
        self::moduleSetCookies($name, $response);

        if ($response->kind === 'redirect') {
            self::moduleSend($route, $status, 'text/plain; charset=utf-8', '', ['Location' => $response->body]);
        }
        if ($response->kind === 'json') {
            self::moduleSend($route, $status, 'application/json; charset=utf-8', $response->body);
        }
        self::moduleReply($route, $status, $response->title, $response->body, [], $response->description(), $path, $response->image(), $response->meta());
    }

    /** An error of the core on an address of a module: a page in the theme, on an API address JSON ($extra may change "error" and add "message"). */
    private static function moduleFail(array $route, int $status, string $title, string $bodyHtml, array $headers = [], array $extra = []): never
    {
        if ($route['api']) {
            self::apiError($status, $headers, $extra);
        }
        self::moduleReply($route, $status, $title, $bodyHtml, $headers);
    }

    /** {"ok": false, "error": "rate_limited"} and the like: the only error answer of an API address. @param array<string,string> $headers @param array<string,mixed> $extra */
    private static function apiError(int $status, array $headers = [], array $extra = []): never
    {
        $code = [400 => 'bad_request', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 413 => 'payload_too_large', 429 => 'rate_limited'][$status] ?? ($status >= 500 ? 'server_error' : 'error');
        self::send($status, 'application/json; charset=utf-8', (string)json_encode(array_replace(['ok' => false, 'error' => $code], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $headers + ['Cache-Control' => 'private, no-store', 'X-AgeCMS-Cache' => 'bypass']);
    }

    /**
     * Body of a POST to an API address: JSON of any content type, or the fields of a form (multipart, urlencoded) as PHP read them.
     * Not JSON, broken JSON or a JSON value that is not an object or a list gives an empty array; a body over the limit of the route is refused.
     *
     * @param array<string,mixed> $route
     * @return array<string,mixed>
     */
    private static function apiInput(array $route): array
    {
        $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($type, 'multipart/form-data') || str_contains($type, 'application/x-www-form-urlencoded')) {
            return $_POST;
        }
        $limit = (int)$route['max_body_kb'] * 1024;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
            self::apiError(413);
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, $limit + 1);
        if (strlen($raw) > $limit) {
            self::apiError(413);
        }
        $data = $raw === '' ? null : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * The request headers that the manifest of an API route asked for, by lower case name; the rest is never handed over.
     *
     * @param list<string> $names
     * @return array<string,string>
     */
    private static function requestHeaders(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $value = match ($n) {
                'content-type' => $_SERVER['CONTENT_TYPE'] ?? null,
                'content-length' => $_SERVER['CONTENT_LENGTH'] ?? null,
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
                default => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $n))] ?? null,
            };
            if (is_string($value) && $value !== '') {
                $out[$n] = substr((string)preg_replace('/[\x00-\x1f\x7f]/', '', $value), 0, 4096);
            }
        }
        return $out;
    }

    /** A form post whose files PHP threw away because the whole request was over post_max_size. @throws ValidationException */
    private static function assertUploadArrived(): void
    {
        if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data') && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) {
            throw new ValidationException(['Файл больше, чем разрешает сервер (post_max_size ' . ini_get('post_max_size') . ')']);
        }
    }

    /**
     * An HTML page of a module (or an error page for it), drawn inside the theme.
     *
     * @param array<string,mixed> $route
     * @param array<string,string> $headers
     * @param array<string,string> $meta preview tags from ModuleResponse::withMeta()
     */
    private static function moduleReply(array $route, int $status, string $title, string $bodyHtml, array $headers = [], string $description = '', string $path = '', string $image = '', array $meta = []): never
    {
        // Only a successful page of a route declared "indexable" goes to search engines; everything else stays noindex.
        $seo = $route['indexable'] && $status === 200 ? ['slug' => trim($path, '/'), 'description' => $description, 'image' => $image, 'meta' => $meta] : null;
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
        // A page of a module may be framed only when the manifest says so ("embed", or a public address) and nobody is signed in.
        self::$embed = !$route['api'] && $type === 'text/html; charset=utf-8' && ($route['embed'] || $route['cache'] === 'public') && Auth::user() === null;
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
        $consent = in_array(strtolower(trim((string)($in['consent'] ?? ''))), ['1', 'on', 'true', 'yes'], true);
        $errors = [];
        if (!$consent && SiteSettings::consentRequired(Seo::settings())) {
            $errors[] = 'Нужно согласие на обработку персональных данных';
        }
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

        // The agreement is kept with the lead as proof (152-FZ): the time and the address it came from.
        $consentAt = $consent ? ag_now() : '';
        $consentIp = $consent ? substr($ip, 0, 45) : '';
        Database::write(static function (PDO $pdo) use ($page, $name, $phone, $message, $ip, $consentAt, $consentIp): void {
            $pdo->prepare('INSERT INTO leads (created_at, page_slug, name, phone, message, ip_hash, consent_at, consent_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([ag_now(), $page, $name, $phone, $message, AntiSpam::ipHash($ip), $consentAt, $consentIp]);
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
        EventBus::trigger('lead.created', ['name' => $name, 'phone' => $phone, 'message' => $message, 'page' => $page, 'consent_at' => $consentAt]);
        Mailer::notifyLead(['name' => $name, 'phone' => $phone, 'message' => $message, 'page' => $page, 'consent_at' => $consentAt]);
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
        if (self::foreignOrigin()) {
            Http::json(['ok' => false, 'error' => 'Forbidden origin'], 403);
        }
    }

    /** True when the request names an Origin that is not this site. A request without Origin (a program, not a browser) is not foreign. */
    private static function foreignOrigin(): bool
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        return $origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url((string)Config::get('site_url', ''), PHP_URL_HOST);
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
            $security = require __DIR__ . '/security-headers.php';
            foreach (array_replace(self::$embed ? Embedding::open($security) : $security, $headers) as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && $status !== 204) {
            echo $body;
        }
        exit;
    }
}
