<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Pages of modules inside the admin panel. Declared in the signed manifest:
 *   "admin": [{"path": "/", "title": "Рассылки", "permission": "content.write"},
 *             {"path": "/campaigns/{id}", "title": "Кампания", "permission": "content.write", "methods": ["GET", "POST"]}]
 * They are served at /admin/m/<module><path>. The core signs the user in, checks the permission, the request limit and,
 * for POST, the admin CSRF token. The module never sees the admin session or any cookie.
 */
final class ModuleAdmin
{
    public const PREFIX = '/admin/m/';
    private const SEGMENT = '/^(?:[a-z0-9][a-z0-9_-]{0,39}|\{[a-z][a-z0-9_]{0,19}\})$/';
    private const PARAM_VALUE = '/^[A-Za-z0-9_-]{1,64}$/';
    private const MAX_PAGES = 20;
    /** Permissions a page may ask for: the ones roles are built from. */
    private const PERMISSIONS = ['content.read', 'content.write', 'cache.clear', 'search', 'skills.run', 'system.manage'];

    /**
     * @param array<string,mixed> $manifest
     * @return list<array{module:string,path:string,segments:list<string>,title:string,permission:string,methods:list<string>,menu:bool,upload:?array{kinds:list<string>,max_kb:int,max_files:int}}>
     */
    public static function parse(string $module, array $manifest): array
    {
        $declared = $manifest['admin'] ?? [];
        if (!is_array($declared) || !array_is_list($declared) || count($declared) > self::MAX_PAGES) {
            throw new AgeCMSException('admin must be a list of at most ' . self::MAX_PAGES . ' pages');
        }
        $pages = [];
        foreach ($declared as $i => $page) {
            if (!is_array($page) || array_diff(array_keys($page), ['path', 'title', 'permission', 'methods', 'menu', 'upload']) !== [] || !isset($page['path'], $page['title'], $page['permission'])) {
                throw new AgeCMSException("admin[{$i}]: path, title and permission are required, nothing else except methods and menu");
            }
            $path = $page['path'];
            if (!is_string($path) || strlen($path) > 100 || $path === '' || $path[0] !== '/') {
                throw new AgeCMSException("admin[{$i}]: path must be a string starting with /");
            }
            $segments = $path === '/' ? [] : explode('/', substr($path, 1));
            if (count($segments) > 4) {
                throw new AgeCMSException("admin[{$i}]: more than 4 segments");
            }
            $names = [];
            foreach ($segments as $segment) {
                if (preg_match(self::SEGMENT, $segment) !== 1) {
                    throw new AgeCMSException("admin[{$i}]: bad segment '{$segment}' (lowercase letters, digits, - _ or {name})");
                }
                if ($segment[0] === '{') {
                    if (in_array($segment, $names, true)) {
                        throw new AgeCMSException("admin[{$i}]: placeholder {$segment} used twice");
                    }
                    $names[] = $segment;
                }
            }
            $title = $page['title'];
            if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 60 || preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
                throw new AgeCMSException("admin[{$i}]: title is 1..60 characters");
            }
            if (!is_string($page['permission']) || !in_array($page['permission'], self::PERMISSIONS, true)) {
                throw new AgeCMSException("admin[{$i}]: permission must be one of " . implode(', ', self::PERMISSIONS));
            }
            $methods = $page['methods'] ?? ['GET'];
            if (!is_array($methods) || $methods === [] || array_diff($methods, ['GET', 'POST']) !== [] || count(array_unique($methods)) !== count($methods)) {
                throw new AgeCMSException("admin[{$i}]: methods may be GET and POST");
            }
            $menu = $page['menu'] ?? (in_array('GET', $methods, true) && $names === []);
            if (!is_bool($menu) || ($menu && ($names !== [] || !in_array('GET', $methods, true)))) {
                throw new AgeCMSException("admin[{$i}]: only a GET page without placeholders can be in the menu");
            }
            $upload = null;
            if (isset($page['upload'])) {
                $u = $page['upload'];
                $kinds = is_array($u) ? ($u['kinds'] ?? null) : null;
                $maxKb = is_array($u) ? ($u['max_kb'] ?? 2048) : null;
                $maxFiles = is_array($u) ? ($u['max_files'] ?? 1) : null;
                if (!is_array($u) || array_diff(array_keys($u), ['kinds', 'max_kb', 'max_files']) !== [] || $methods !== ['POST']
                    || !is_array($kinds) || $kinds === [] || !array_is_list($kinds) || array_diff($kinds, array_keys(ModuleUpload::KINDS)) !== [] || count(array_unique($kinds)) !== count($kinds)
                    || !is_int($maxKb) || $maxKb < 1 || $maxKb > 10240 || !is_int($maxFiles) || $maxFiles < 1 || $maxFiles > 10) {
                    throw new AgeCMSException("admin[{$i}]: upload goes only with a POST-only page and needs kinds (" . implode(', ', array_keys(ModuleUpload::KINDS)) . '), max_kb 1..10240, max_files 1..10');
                }
                $upload = ['kinds' => array_values($kinds), 'max_kb' => $maxKb, 'max_files' => $maxFiles];
            }
            foreach ($pages as $other) {
                if (self::overlap($segments, $other['segments']) && array_intersect($methods, $other['methods']) !== []) {
                    throw new AgeCMSException("admin[{$i}]: {$path} overlaps {$other['path']}");
                }
            }
            $pages[] = ['module' => $module, 'path' => $path, 'segments' => $segments, 'title' => trim($title), 'permission' => $page['permission'], 'methods' => array_values($methods), 'menu' => $menu, 'upload' => $upload];
        }
        return $pages;
    }

    /** Called once every manifest is verified: a module with bad admin pages is blocked. @param array<string,array{status:string,reason:?string,manifest:?array<string,mixed>}> $modules */
    public static function validateAll(array &$modules): void
    {
        foreach ($modules as $name => $info) {
            if ($info['status'] !== 'ok' || ($info['manifest']['admin'] ?? []) === []) {
                continue;
            }
            try {
                self::parse($name, $info['manifest']);
            } catch (AgeCMSException $e) {
                $modules[$name] = ['status' => 'blocked', 'reason' => $e->getMessage(), 'manifest' => null];
                Logger::log('warning', 'module.blocked', ['module' => $name, 'reason' => $e->getMessage()]);
            }
        }
    }

    /** @return list<array{module:string,path:string,segments:list<string>,title:string,permission:string,methods:list<string>,menu:bool,upload:?array{kinds:list<string>,max_kb:int,max_files:int}}> */
    public static function all(): array
    {
        $out = [];
        foreach (SkillRegistry::modules() as $name => $info) {
            if ($info['status'] !== 'ok' || ($info['manifest']['admin'] ?? []) === []) {
                continue;
            }
            try {
                array_push($out, ...self::parse($name, $info['manifest']));
            } catch (AgeCMSException) {
                // validateAll() has already blocked such a module
            }
        }
        return $out;
    }

    /** Menu items for a role: [title, address, id] of the pages it may open; id is "module|path". @return list<array{0:string,1:string,2:string}> */
    public static function menu(string $role): array
    {
        $items = [];
        foreach (self::all() as $page) {
            if ($page['menu'] && Security::can($role, $page['permission'])) {
                $items[] = [$page['title'], self::PREFIX . $page['module'] . ($page['path'] === '/' ? '/' : $page['path']), $page['module'] . '|' . $page['path']];
            }
        }
        return $items;
    }

    /**
     * @return array{page:array<string,mixed>,params:array<string,string>}|null null when no page of a module answers this address and method
     */
    public static function match(string $method, string $path): ?array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        if (!str_starts_with($path, self::PREFIX)) {
            return null;
        }
        $rest = explode('/', trim(substr($path, strlen(self::PREFIX)), '/'));
        $module = array_shift($rest);
        $rest = array_values(array_filter($rest, static fn(string $s): bool => $s !== ''));
        foreach (self::all() as $page) {
            if ($page['module'] !== $module || count($page['segments']) !== count($rest) || !in_array($method, $page['methods'], true)) {
                continue;
            }
            $params = [];
            foreach ($page['segments'] as $n => $segment) {
                if ($segment[0] === '{') {
                    if (preg_match(self::PARAM_VALUE, $rest[$n]) !== 1) {
                        continue 2;
                    }
                    $params[substr($segment, 1, -1)] = $rest[$n];
                } elseif ($segment !== $rest[$n]) {
                    continue 2;
                }
            }
            return ['page' => $page, 'params' => $params];
        }
        return null;
    }

    /**
     * Runs the page of the module for a checked user. Everything that went wrong comes back as a ModuleResponse for the admin to show.
     *
     * @param array{page:array<string,mixed>,params:array<string,string>} $hit
     * @param array<string,mixed> $input POST fields without the CSRF field
     * @param array<string,mixed> $files $_FILES; looked at only when the page declared "upload"
     */
    public static function run(array $hit, string $method, string $path, array $query, array $input, Actor $actor, array $files = []): ModuleResponse
    {
        $name = (string)$hit['page']['module'];
        try {
            Security::requirePermission($actor, (string)$hit['page']['permission']);
            $module = SkillRegistry::admin($name);
            $uploads = $hit['page']['upload'] !== null && $method === 'POST' ? ModuleUpload::collect($name, $hit['page']['upload'], $files) : [];
            $request = new ModuleRequest($name, $method === 'HEAD' ? 'GET' : $method, $path, $hit['params'], $query, $input, Http::clientIp(), [], $uploads);
            $response = $module->handleAdmin($request, $actor);
        } catch (ForbiddenException $e) {
            return ModuleResponse::html('Нет доступа', '<div class="notice error">' . ag_e($e->getMessage()) . '</div>', 403);
        } catch (ValidationException $e) {
            return ModuleResponse::html('Данные не приняты', '<div class="notice error">' . ag_e($e->getMessage()) . '</div>', 400);
        } catch (NotFoundException) {
            return ModuleResponse::html('Страница не найдена', '<div class="notice error">Модуль недоступен.</div>', 404);
        } catch (Throwable $e) {
            Logger::log('error', 'module.admin_failed', ['module' => $name, 'path' => $path, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            return ModuleResponse::html('Ошибка', '<div class="notice error">Страница модуля не открылась, подробности в журнале.</div>', 500);
        }
        $status = $response->status;
        $bad = null;
        if (!$response instanceof ModuleResponse) {
            $bad = 'handleAdmin() did not return a ModuleResponse';
        } elseif ($response->cookies() !== []) {
            $bad = 'an admin page cannot set cookies';
        } elseif ($response->kind === 'redirect' && (!in_array($status, [302, 303], true) || !self::ownAddress($name, $response->body))) {
            $bad = 'redirect must be 302 or 303 to a page of the same module';
        } elseif ($response->kind !== 'redirect' && ($status < 200 || $status > 599 || ($status >= 300 && $status < 400))) {
            $bad = "status {$status} is not allowed";
        }
        if ($bad !== null) {
            Logger::log('error', 'module.admin_failed', ['module' => $name, 'path' => $path, 'error' => $bad]);
            return ModuleResponse::html('Ошибка', '<div class="notice error">Страница модуля вернула недопустимый ответ.</div>', 500);
        }
        return $response;
    }

    private static function ownAddress(string $module, string $to): bool
    {
        $base = self::PREFIX . $module;
        return ($to === $base || str_starts_with($to, $base . '/') || str_starts_with($to, $base . '?'))
            && preg_match('~^/[^\x00-\x1F\x7F\\\\]*$~', $to) === 1;
    }

    /**
     * Front door for /admin/m/...: finds the page and hands over to the admin panel, which signs the user in and
     * answers. An unknown address is a plain 404, so nothing tells whether a module exists.
     */
    public static function serve(string $method, string $path): never
    {
        $hit = self::match($method, $path);
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true) || $hit === null) {
            http_response_code($hit === null && in_array($method, ['GET', 'HEAD', 'POST'], true) ? 404 : 405);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Not found\n";
            exit;
        }
        $GLOBALS['AGECMS_ADMIN_MODULE'] = $hit + ['path' => $path];
        require AGECMS_ROOT . '/admin/index.php';
        exit;
    }

    /** @param list<string> $a @param list<string> $b */
    private static function overlap(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $n => $segment) {
            if ($segment !== $b[$n] && $segment[0] !== '{' && $b[$n][0] !== '{') {
                return false;
            }
        }
        return true;
    }
}
