<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Addresses served by modules. A route is declared in the signed manifest:
 *   "routes": [{"path": "/kabinet/zalog/{id}", "methods": ["GET", "POST"], "rate": {"limit": 10, "window": 60}, "cache": "private"}]
 * Only modules with a verified signature get routes. A route that is malformed, uses an address the core or a page
 * already owns, or collides with a route of another module blocks the whole module when the registry loads it.
 */
final class ModuleRoutes
{
    /** First path segments that belong to the core. Pages::RESERVED covers admin, assets, search and the like. */
    private const CORE_SEGMENTS = ['index', 'sitemap', 'robots', 'llms', 'agent'];
    private const SEGMENT = '/^(?:[a-z0-9][a-z0-9_-]{0,39}|\{[a-z][a-z0-9_]{0,19}\})$/';
    private const PARAM_VALUE = '/^[A-Za-z0-9_-]{1,64}$/';
    private const MAX_ROUTES = 50;

    /**
     * @param array<string,mixed> $manifest
     * @return list<array{module:string,path:string,segments:list<string>,methods:list<string>,rate:array{limit:int,window:int},cache:string,max_age:int,indexable:bool}>
     */
    public static function parse(string $module, array $manifest): array
    {
        $declared = $manifest['routes'] ?? [];
        if (!is_array($declared) || !array_is_list($declared) || count($declared) > self::MAX_ROUTES) {
            throw new AgeCMSException('routes must be a list of at most ' . self::MAX_ROUTES . ' routes');
        }
        $routes = [];
        $seen = [];
        foreach ($declared as $i => $route) {
            if (!is_array($route) || array_diff(array_keys($route), ['path', 'methods', 'rate', 'cache', 'max_age', 'indexable']) !== []) {
                throw new AgeCMSException("routes[{$i}]: unknown or missing fields");
            }
            $path = $route['path'] ?? null;
            if (!is_string($path) || strlen($path) > 120 || $path[0] !== '/') {
                throw new AgeCMSException("routes[{$i}]: path must be a string starting with /");
            }
            $segments = explode('/', substr($path, 1));
            if (count($segments) > 6) {
                throw new AgeCMSException("routes[{$i}]: more than 6 segments");
            }
            $names = [];
            foreach ($segments as $n => $segment) {
                if (preg_match(self::SEGMENT, $segment) !== 1) {
                    throw new AgeCMSException("routes[{$i}]: bad segment '{$segment}' (lowercase letters, digits, - _ or {name})");
                }
                if (self::isParam($segment)) {
                    if ($n === 0) {
                        throw new AgeCMSException("routes[{$i}]: the first segment cannot be a placeholder");
                    }
                    if (in_array($segment, $names, true)) {
                        throw new AgeCMSException("routes[{$i}]: placeholder {$segment} used twice");
                    }
                    $names[] = $segment;
                }
            }
            if (in_array($segments[0], array_merge(Pages::RESERVED, self::CORE_SEGMENTS), true)) {
                throw new AgeCMSException("routes[{$i}]: /{$segments[0]} belongs to the core");
            }
            if (isset($seen[$path])) {
                throw new AgeCMSException("routes[{$i}]: {$path} is declared twice");
            }
            $seen[$path] = true;

            $methods = $route['methods'] ?? ['GET'];
            if (!is_array($methods) || $methods === [] || array_diff($methods, ['GET', 'POST']) !== [] || count(array_unique($methods)) !== count($methods)) {
                throw new AgeCMSException("routes[{$i}]: methods may be GET and POST");
            }
            $methods = array_values($methods);

            $rate = $route['rate'] ?? null;
            if ($rate === null) {
                $rate = in_array('POST', $methods, true) ? ['limit' => 10, 'window' => 60] : ['limit' => 120, 'window' => 60];
            } elseif (!is_array($rate) || array_keys($rate) !== ['limit', 'window'] || !is_int($rate['limit']) || !is_int($rate['window'])
                || $rate['limit'] < 1 || $rate['limit'] > 600 || $rate['window'] < 10 || $rate['window'] > 3600) {
                throw new AgeCMSException("routes[{$i}]: rate needs limit 1..600 and window 10..3600");
            }

            $cache = $route['cache'] ?? 'private';
            if (!in_array($cache, ['private', 'public'], true)) {
                throw new AgeCMSException("routes[{$i}]: cache is private or public");
            }
            $maxAge = $route['max_age'] ?? 300;
            if ($cache === 'public' && ($methods !== ['GET'] || !is_int($maxAge) || $maxAge < 1 || $maxAge > 86400)) {
                throw new AgeCMSException("routes[{$i}]: a public route is GET only, max_age 1..86400");
            }
            if ($cache === 'private' && isset($route['max_age'])) {
                throw new AgeCMSException("routes[{$i}]: max_age only goes with cache public");
            }
            $indexable = $route['indexable'] ?? false;
            if (!is_bool($indexable) || ($indexable && $cache !== 'public')) {
                throw new AgeCMSException("routes[{$i}]: indexable is true or false and only goes with cache public");
            }
            $routes[] = [
                'module' => $module, 'path' => $path, 'segments' => $segments, 'methods' => $methods,
                'rate' => ['limit' => (int)$rate['limit'], 'window' => (int)$rate['window']],
                'cache' => $cache, 'max_age' => $cache === 'public' ? (int)$maxAge : 0, 'indexable' => $indexable,
            ];
        }
        return $routes;
    }

    /**
     * Called by the registry once every manifest is verified. Blocks the modules whose routes are not acceptable.
     * Modules are visited in name order, so when two modules want the same address the first keeps it.
     *
     * @param array<string,array{status:string,reason:?string,manifest:?array<string,mixed>}> $modules
     */
    public static function validateAll(array &$modules): void
    {
        $claimed = [];
        $pages = null;
        foreach ($modules as $name => $info) {
            if ($info['status'] !== 'ok' || ($info['manifest']['routes'] ?? []) === []) {
                continue;
            }
            try {
                $routes = self::parse($name, $info['manifest']);
                $pages ??= self::pageSlugs();
                foreach ($routes as $route) {
                    foreach ($claimed as $other) {
                        if (self::overlap($route['segments'], $other['segments'])) {
                            throw new AgeCMSException("Route {$route['path']} collides with route {$other['path']} of module {$other['module']}");
                        }
                    }
                    foreach ($pages as $slug) {
                        if (self::overlap($route['segments'], explode('/', $slug))) {
                            throw new AgeCMSException("Route {$route['path']} collides with the page '{$slug}'");
                        }
                    }
                }
                array_push($claimed, ...$routes);
            } catch (AgeCMSException $e) {
                $modules[$name] = ['status' => 'blocked', 'reason' => $e->getMessage(), 'manifest' => null, 'signed' => null];
                Logger::log('warning', 'module.blocked', ['module' => $name, 'reason' => $e->getMessage()]);
            }
        }
    }

    /** @return list<array{module:string,path:string,segments:list<string>,methods:list<string>,rate:array{limit:int,window:int},cache:string,max_age:int,indexable:bool}> */
    public static function all(): array
    {
        $out = [];
        foreach (SkillRegistry::modules() as $name => $info) {
            if ($info['status'] !== 'ok' || ($info['manifest']['routes'] ?? []) === []) {
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

    /**
     * The route that answers $path. When the address exists but not for this method, "route" is null and "allow" lists the methods.
     *
     * @return array{route:?array<string,mixed>,params:array<string,string>,allow:list<string>}|null null when no module owns the address
     */
    public static function match(string $method, string $path): ?array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $parts = explode('/', trim($path, '/'));
        $best = null;
        $bestScore = -1;
        $allow = [];
        $matched = false;
        foreach (self::all() as $route) {
            if (count($route['segments']) !== count($parts)) {
                continue;
            }
            $params = [];
            foreach ($route['segments'] as $n => $segment) {
                if (self::isParam($segment)) {
                    if (preg_match(self::PARAM_VALUE, $parts[$n]) !== 1) {
                        continue 2;
                    }
                    $params[substr($segment, 1, -1)] = $parts[$n];
                } elseif ($segment !== $parts[$n]) {
                    continue 2;
                }
            }
            $matched = true;
            if (!in_array($method, $route['methods'], true)) {
                $allow = array_merge($allow, $route['methods']);
                continue;
            }
            $score = count($route['segments']) - count($params);
            if ($score > $bestScore) {
                $best = ['route' => $route, 'params' => $params, 'allow' => []];
                $bestScore = $score;
            }
        }
        if ($best !== null) {
            return $best;
        }
        return $matched ? ['route' => null, 'params' => [], 'allow' => array_values(array_unique($allow))] : null;
    }

    /** Name of the module whose route would answer this page address, or null. Pages may not take such an address. */
    public static function claimedBy(string $slug): ?string
    {
        $parts = explode('/', $slug);
        foreach (self::all() as $route) {
            if (self::overlap($route['segments'], $parts)) {
                return $route['module'];
            }
        }
        return null;
    }

    private static function isParam(string $segment): bool
    {
        return $segment !== '' && $segment[0] === '{';
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function overlap(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $n => $segment) {
            if ($segment !== $b[$n] && !self::isParam($segment) && !self::isParam($b[$n])) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private static function pageSlugs(): array
    {
        try {
            return array_map('strval', array_column(Database::rows('SELECT slug FROM pages'), 'slug'));
        } catch (Throwable) {
            return []; // not installed yet
        }
    }
}
