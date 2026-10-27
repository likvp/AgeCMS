<?php
declare(strict_types=1);

namespace AgeCMS;

/** Request helpers and an outbound HTTP client that refuses to talk to private networks (SSRF guard). */
final class Http
{
    public static function clientIp(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $proxies = (array)Config::get('trusted_proxies', []);
        if ($proxies !== [] && in_array($remote, $proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        return $remote;
    }

    public static function bearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9_\-\.~+\/=]{20,200})$/', (string)$header, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    /** Sends the response to the client and keeps running (for pings and logging). */
    public static function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            // CGNAT 100.64.0.0/10 and benchmarking 198.18.0.0/15 are not covered by the PHP flags.
            if (($long & 0xFFC00000) === 0x64400000 || ($long & 0xFFFE0000) === 0xC6120000) {
                return false;
            }
        }
        return true;
    }

    /** Returns a public IP for the host (an IP literal is checked as is), or null. */
    public static function resolvePublic(string $host): ?string
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? $host : null;
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
            $ips[] = $r['ip'];
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ipv6'];
        }
        if ($ips === []) {
            return null;
        }
        // Every address must be public, otherwise a mixed answer could be used to reach an internal host.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        return $ips[0];
    }

    /** @return array{host:string,port:int,ip:string,url:string}|null */
    public static function guard(string $url, bool $allowHttp = false): ?array
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
            return null;
        }
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            return null;
        }
        $ip = self::resolvePublic($p['host']);
        return $ip === null ? null : ['host' => trim($p['host'], '[]'), 'port' => $port, 'ip' => $ip, 'url' => $url];
    }

    /**
     * @param array<string,mixed> $body
     * @param list<string> $headers
     * @return array{status:int,body:string}|null null when the URL is refused or the request failed
     */
    public static function postJson(string $url, array $body, array $headers = [], int $timeout = 3): ?array
    {
        return self::request($url, 'POST', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), array_merge(['Content-Type: application/json'], $headers), $timeout, 1048576);
    }

    /**
     * @param array<string,mixed> $fields may contain CURLFile values
     * @return array{status:int,body:string}|null
     */
    public static function postForm(string $url, array $fields, int $timeout = 30): ?array
    {
        return self::request($url, 'POST', $fields, [], $timeout, 1048576);
    }

    /** @return array{status:int,body:string}|null */
    public static function get(string $url, int $timeout = 5, int $maxBytes = 10485760): ?array
    {
        return self::request($url, 'GET', null, [], $timeout, $maxBytes);
    }

    /**
     * @param string|array<string,mixed>|null $payload
     * @param list<string> $headers
     * @return array{status:int,body:string}|null
     */
    private static function request(string $url, string $method, string|array|null $payload, array $headers, int $timeout, int $maxBytes): ?array
    {
        $target = self::guard($url);
        if ($target === null || !function_exists('curl_init')) {
            Logger::log('warning', 'http.refused', ['host' => (string)parse_url($url, PHP_URL_HOST)]);
            return null;
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
            CURLOPT_USERAGENT => 'AgeCMS/1.0',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return $ok === false ? null : ['status' => $status, 'body' => $body];
    }
}
