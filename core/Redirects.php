<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Permanent redirects from addresses of the old site (another CMS) to pages of this one. They share the table `redirects`
 * with the redirects a moved page leaves behind (Content::renamePage): those have a slug in from_slug, these a path that
 * starts with "/" and may carry a query ("/old/post?p=12"). to_slug is a page slug (it follows the page when it moves
 * again) or a full https address. Writes go through Content::addRedirects() and Content::removeRedirects().
 */
final class Redirects
{
    public const MAX_BATCH = 1000;
    private const MAX_LENGTH = 2000;
    /** Query keys that only mark where a visitor came from; they never decide where an old address leads. */
    private const TRACKING = ['fbclid', 'gclid', 'yclid', 'ysclid', 'dclid', 'msclkid', '_openstat', 'openstat', 'mc_cid', 'mc_eid', '_ga', '_gl', 'from'];
    private const SYSTEM_PATHS = ['/sitemap.xml', '/robots.txt', '/llms.txt', '/AGENT.md', '/index'];

    /**
     * A source address as stored: no scheme and host, a leading "/", no trailing "/", percent-encoding decoded (Cyrillic stays
     * as letters), the query without tracking keys and in a fixed order. Null when the address cannot be one.
     */
    public static function normalizeFrom(string $from): ?string
    {
        $from = trim($from);
        $from = preg_replace('~^([a-z][a-z0-9+.\-]*:)?//[^/?#]*~i', '', $from) ?? '';
        $hash = strpos($from, '#');
        if ($hash !== false) {
            $from = substr($from, 0, $hash);
        }
        if ($from === '' || $from[0] !== '/') {
            $from = '/' . $from;
        }
        [$path, $query] = array_pad(explode('?', $from, 2), 2, '');
        $path = self::normalizePath($path);
        if ($path === null) {
            return null;
        }
        $query = self::normalizeQuery($query);
        $out = $query === '' ? $path : $path . '?' . $query;
        return strlen($out) > self::MAX_LENGTH ? null : $out;
    }

    /** "/Путь//к/странице/" or "/%D0%9F..." becomes "/Путь/к/странице". Null for broken encoding or control characters. */
    public static function normalizePath(string $path): ?string
    {
        $path = rawurldecode($path);
        if (!mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }
        $path = '/' . trim((string)preg_replace('~/+~', '/', $path), '/');
        // What would change the meaning of the stored key goes back to its percent form.
        return strtr($path, ['%' => '%25', '?' => '%3F', '#' => '%23', ' ' => '%20']);
    }

    /** "b=2&a=1&utm_source=x" becomes "a=1&b=2": decoded, tracking keys out, sorted. */
    public static function normalizeQuery(string $query): string
    {
        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            $key = urldecode((string)$key);
            $value = $value === null ? null : urldecode($value);
            if (!mb_check_encoding($key . (string)$value, 'UTF-8')) {
                $pairs[] = [strtoupper($part), ''];
                continue;
            }
            $lower = strtolower($key);
            if ($key === '' || str_starts_with($lower, 'utm_') || in_array($lower, self::TRACKING, true)) {
                continue;
            }
            $pairs[] = [self::encode($key), $value === null ? '' : '=' . self::encode($value)];
        }
        usort($pairs, static fn(array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
        return implode('&', array_map(static fn(array $p): string => $p[0] . $p[1], $pairs));
    }

    private static function encode(string $s): string
    {
        return (string)preg_replace_callback('/[\x00-\x20%&=+#\x7F]/', static fn(array $m): string => '%' . strtoupper(bin2hex($m[0])), $s);
    }

    /**
     * Where a request should go: the stored path with its query first, then the path alone (the query of the request is kept
     * then). Null when there is no redirect or its page is gone.
     */
    public static function target(string $path, string $query): ?string
    {
        $path = self::normalizePath($path);
        if ($path === null || $path === '/' && $query === '') {
            return null;
        }
        $q = self::normalizeQuery($query);
        $keys = $q !== '' ? [$path . '?' . $q, $path] : [$path];
        $rows = Database::rows('SELECT from_slug, to_slug FROM redirects WHERE from_slug IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys);
        if ($rows === []) {
            return null;
        }
        $found = array_column($rows, 'to_slug', 'from_slug');
        foreach ($keys as $i => $key) {
            if (!isset($found[$key])) {
                continue;
            }
            $to = (string)$found[$key];
            if (str_starts_with($to, 'https://') || str_starts_with($to, 'http://')) {
                return $to;
            }
            if (Pages::row($to) === null) {
                return null;
            }
            $location = '/' . ($to === 'index' ? '' : $to);
            // A match with the query used the query up; a match by the path alone passes it on, as a moved page does.
            return $i === 0 && $q !== '' ? $location : $location . ($query !== '' ? '?' . $query : '');
        }
        return null;
    }

    /**
     * Imported redirects and redirects of moved pages, newest first. from is always a path ("/old"), to a path or an https address.
     *
     * @return list<array{from:string,to:string,kind:string,created_at:string}>
     */
    public static function listing(int $limit = 1000, int $offset = 0, string $search = ''): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE from_slug LIKE ? OR to_slug LIKE ?';
            $like = '%' . strtr($search, ['%' => '', '_' => '']) . '%';
            $params = [$like, $like];
        }
        $params[] = max(1, min(5000, $limit));
        $params[] = max(0, $offset);
        $out = [];
        foreach (Database::rows("SELECT from_slug, to_slug, created_at FROM redirects {$where} ORDER BY created_at DESC, from_slug LIMIT ? OFFSET ?", $params) as $row) {
            $out[] = self::row($row);
        }
        return $out;
    }

    public static function count(): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM redirects');
    }

    /**
     * @param array<string,mixed> $row
     * @return array{from:string,to:string,kind:string,created_at:string}
     */
    public static function row(array $row): array
    {
        $from = (string)$row['from_slug'];
        $to = (string)$row['to_slug'];
        $external = str_starts_with($to, 'https://') || str_starts_with($to, 'http://');
        return [
            'from' => str_starts_with($from, '/') ? $from : '/' . $from,
            'to' => $external ? $to : '/' . ($to === 'index' ? '' : $to),
            'kind' => str_starts_with($from, '/') ? 'import' : 'rename',
            'created_at' => (string)$row['created_at'],
        ];
    }

    /**
     * Checks one redirect before it is stored. Returns [from, to_slug] in the stored form or an error text.
     *
     * @return array{0:string,1:string}|string
     */
    public static function check(mixed $from, mixed $to): array|string
    {
        if (!is_string($from) || trim($from) === '' || !is_string($to) || trim($to) === '') {
            return 'from and to must be non-empty strings';
        }
        $key = self::normalizeFrom($from);
        if ($key === null) {
            return "'{$from}' is not an address (broken encoding, control characters or longer than " . self::MAX_LENGTH . ')';
        }
        [$path, $query] = array_pad(explode('?', $key, 2), 2, '');
        if ($path === '/' && $query === '') {
            return "'{$from}' is the home page";
        }
        if (SchemaValidator::pathEscapes($path)) {
            return "'{$from}' must not contain '..' or a backslash in the path";
        }
        $first = explode('/', ltrim($path, '/'))[0];
        if (in_array($path, self::SYSTEM_PATHS, true) || in_array($first, Pages::RESERVED, true)) {
            return "'{$key}' is an address of the system";
        }
        if (ModuleRoutes::match('GET', rawurldecode($path)) !== null) {
            return "'{$key}' is an address of a module";
        }
        $slug = Cache::slugForPath($path);
        if ($query === '' && $slug !== null && Pages::row($slug) !== null) {
            return "'{$key}' is the address of an existing page";
        }
        if ($query === '' && $slug !== null && Database::value('SELECT to_slug FROM redirects WHERE from_slug = ?', [$slug]) !== null) {
            return "'{$key}' already leads to another page since that page moved";
        }

        $target = self::targetOf(trim($to));
        if (is_string($target) && str_starts_with($target, 'error:')) {
            return substr($target, 6);
        }
        if ($target === ($slug ?? '') && $query === '') {
            return "'{$key}' would lead to itself";
        }
        return [$key, $target];
    }

    /** The stored target: a page slug or an https address. "error:..." when it is neither. */
    private static function targetOf(string $to): string
    {
        $site = strtolower((string)parse_url(Seo::siteUrl(), PHP_URL_HOST));
        if (SchemaValidator::hasUserinfo($to)) {
            return "error:'{$to}' must not contain a login or password in the address";
        }
        if (preg_match('~^https?://~i', $to) === 1) {
            $host = strtolower((string)parse_url($to, PHP_URL_HOST));
            if ($host === '') {
                return "error:'{$to}' is not an address";
            }
            if ($host !== $site) {
                if (stripos($to, 'https://') !== 0 || strlen($to) > self::MAX_LENGTH || preg_match('~[\s<>"\'\\\\\x00-\x1F\x7F]~', $to) === 1) {
                    return "error:'{$to}' must be an https address without spaces or quotes";
                }
                return $to;
            }
            // An address of this site is a page of it.
            $to = (string)preg_replace('~^https?://[^/?#]*~i', '', $to);
        }
        if (str_contains($to, '?') || str_contains($to, '#')) {
            return "error:'{$to}' must be the path of a page, without ?query or #anchor";
        }
        if (SchemaValidator::pathEscapes('/' . ltrim($to, '/'))) {
            return "error:'{$to}' must not contain '..' or a backslash in the path";
        }
        $slug = Cache::slugForPath(rawurldecode($to));
        if ($slug === null || Pages::row($slug) === null) {
            return "error:'{$to}' is not an existing page";
        }
        return $slug;
    }
}
