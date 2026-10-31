<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

/** URL helpers: resolving relative links, comparing hosts, the "old path" of a page. */
final class Url
{
    /** Absolute URL of $href found on the page $base. Empty for javascript:, data: and broken links. */
    public static function resolve(string $base, string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $base = self::ascii($base);
        if ($href === '' || preg_match('~^(javascript|data|vbscript|about|blob):~i', $href) === 1) {
            return '';
        }
        if (preg_match('~^tel:~i', $href) === 1) {
            return 'tel:' . preg_replace('~[^+0-9]~', '', substr($href, 4));
        }
        if (preg_match('~^(mailto|sms|skype|viber|whatsapp|tg):~i', $href) === 1) {
            return $href;
        }
        if (str_starts_with($href, '#')) {
            return self::stripFragment($base) . $href;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $href) === 1) {
            return self::cleanup(self::ascii($href));
        }
        $href = self::ascii($href);
        $b = parse_url($base);
        if ($b === false || !isset($b['scheme'], $b['host'])) {
            return '';
        }
        $origin = strtolower($b['scheme']) . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return self::cleanup(strtolower($b['scheme']) . ':' . $href);
        }
        if (str_starts_with($href, '/')) {
            return self::cleanup($origin . $href);
        }
        $dir = $b['path'] ?? '/';
        if (str_starts_with($href, '?')) {
            return self::cleanup($origin . $dir . $href);
        }
        $dir = substr($dir, 0, (int)strrpos($dir, '/') + 1);
        return self::cleanup($origin . self::dots($dir . $href));
    }

    /** Removes "." and ".." segments. */
    private static function dots(string $path): string
    {
        $query = '';
        if (($q = strpos($path, '?')) !== false) {
            $query = substr($path, $q);
            $path = substr($path, 0, $q);
        }
        $out = [];
        foreach (explode('/', $path) as $i => $seg) {
            if ($seg === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($seg !== '.' || $i === 0) {
                $out[] = $seg;
            }
        }
        $result = implode('/', $out);
        if (str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $result .= '/';
        }
        return ($result === '' ? '/' : $result) . $query;
    }

    /**
     * The address with every byte outside ASCII percent-encoded (an international host becomes punycode when intl is
     * there). parse_url() works on bytes and replaces 0x80-0x9F with "_", which broke Cyrillic letters р-я in UTF-8
     * (D1 80..8F); every helper here parses the encoded form, and oldPath() decodes the path back.
     */
    public static function ascii(string $url): string
    {
        if (preg_match('~[^\x00-\x7F]~', $url) !== 1) {
            return $url;
        }
        if (preg_match('~^([a-z][a-z0-9+.-]*:)?//([^/?#]*)(.*)$~is', $url, $m) === 1) {
            $auth = $m[2];
            $at = strrpos($auth, '@');
            $user = $at === false ? '' : substr($auth, 0, $at + 1);
            $hostPort = $at === false ? $auth : substr($auth, $at + 1);
            $port = preg_match('~(:\d*)$~', $hostPort, $pm) === 1 ? $pm[1] : '';
            $host = substr($hostPort, 0, strlen($hostPort) - strlen($port));
            if (preg_match('~[^\x00-\x7F]~', $host) === 1 && function_exists('idn_to_ascii')) {
                $puny = idn_to_ascii(mb_strtolower($host), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($puny) && $puny !== '') {
                    $host = $puny;
                }
            }
            $url = $m[1] . '//' . $user . $host . $port . $m[3];
        }
        return preg_replace_callback('~[^\x00-\x7F]+~', static fn(array $m): string => rawurlencode($m[0]), $url) ?? $url;
    }

    private static function cleanup(string $url): string
    {
        $url = preg_replace('~\s~u', '%20', $url) ?? $url;
        return $url;
    }

    public static function stripFragment(string $url): string
    {
        $hash = strpos($url, '#');
        return $hash === false ? $url : substr($url, 0, $hash);
    }

    public static function host(string $url): string
    {
        $host = strtolower((string)parse_url(self::ascii($url), PHP_URL_HOST));
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Same site: the same host, "www." ignored. */
    public static function sameSite(string $a, string $b): bool
    {
        $ha = self::host($a);
        return $ha !== '' && $ha === self::host($b);
    }

    public static function origin(string $url): string
    {
        $p = parse_url(self::ascii($url));
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            return '';
        }
        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /**
     * The old address of a page as the redirect table sees it: path plus query when the query names the page
     * (?p=12, ?page_id=5, index.php?id=3), percent-decoded, without fragment.
     */
    public static function oldPath(string $url, bool $keepQuery = true): string
    {
        $p = parse_url(self::ascii(self::stripFragment($url)));
        if ($p === false) {
            return '/';
        }
        $path = $p['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $path = rawurldecode($path);
        $path = preg_replace('~/{2,}~', '/', $path) ?? $path;
        $query = $keepQuery ? self::pageQuery($p['query'] ?? '') : '';
        return $path . ($query !== '' ? '?' . $query : '');
    }

    /** Keeps only the query parameters that address a page in common CMSes; tracking and sorting parameters go. */
    public static function pageQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $params);
        $keep = ['p', 'page_id', 'cat', 'id', 'option', 'view', 'itemid', 'route', 'product_id', 'path', 'information_id', 'q', 'pagename', 'ELEMENT_ID', 'SECTION_ID', 'ID', 'nid', 'node'];
        $out = [];
        foreach ($params as $k => $v) {
            if (in_array((string)$k, $keep, true) && is_scalar($v)) {
                $out[(string)$k] = (string)$v;
            }
        }
        if (isset($out['q']) && !preg_match('~^[a-z0-9/_-]+$~i', $out['q'])) {
            unset($out['q']);
        }
        return $out === [] ? '' : http_build_query($out);
    }

    /** Key to compare two old paths: lowercase, no trailing slash, no index file. */
    public static function key(string $path): string
    {
        $query = '';
        if (($q = strpos($path, '?')) !== false) {
            $query = substr($path, $q);
            $path = substr($path, 0, $q);
        }
        $path = mb_strtolower($path);
        $path = preg_replace('~/(index|default)\.(html?|php|aspx?)$~', '/', $path) ?? $path;
        $path = rtrim($path, '/');
        return ($path === '' ? '/' : $path) . $query;
    }

    /** True for links to files rather than pages. */
    public static function isFile(string $url): bool
    {
        $path = strtolower((string)parse_url(self::ascii($url), PHP_URL_PATH));
        return preg_match('~\.(jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|pdf|docx?|xlsx?|pptx?|odt|ods|rtf|txt|csv|zip|rar|7z|gz|tar|mp3|mp4|avi|mov|webm|ogg|wav|flv|wmv|exe|dmg|apk|css|js|json|xml|woff2?|ttf|eot|otf)$~', $path) === 1;
    }

    public static function isImage(string $url): bool
    {
        $path = strtolower((string)parse_url(self::ascii($url), PHP_URL_PATH));
        return preg_match('~\.(jpe?g|png|gif|webp|avif|bmp)$~', $path) === 1;
    }
}
