<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Net;

use AgeCMS\Http;
use AgeCMS\Logger;
use AgeCMS\Seo;

/**
 * Network fetcher on top of the core's SSRF guard (Http::guard): every hop, redirects included, must resolve to a public
 * address, and curl is pinned to that address (CURLOPT_RESOLVE) so DNS cannot be switched between the check and the request.
 * Unlike Http::get it also speaks plain http (many old sites have no https), reads the response headers (charset, type)
 * and sends its own User-Agent. Keeps a pause between requests to be polite to the source site.
 */
final class HttpFetcher implements Fetcher
{
    private const MAX_REDIRECTS = 5;
    /**
     * A request for more than this many bytes is a sitemap (Generic asks 20 MB): a big generated sitemap may take longer
     * than a page (hobby-idea.ru sends 1.1 MB in 20 s), so it gets LONG_TIMEOUT and is cut only when it stalls.
     */
    private const LONG_FROM = 16777216;
    private const LONG_TIMEOUT = 90;
    private static float $last = 0.0;
    /** Command line only (bin/import.php --allow-private): lets a test run read a source on localhost or the LAN. */
    private static bool $allowPrivate = false;
    private int $count = 0;

    public function __construct(private int $delayMs = 500, private int $timeout = 15)
    {
    }

    /**
     * Turns off the private network check for this process, any port allowed. Only for the command line, to import from a
     * WordPress or another site running on the same machine or in the local network; never set from the admin or skills.
     */
    public static function allowPrivate(bool $on): void
    {
        self::$allowPrivate = $on && PHP_SAPI === 'cli';
    }

    /** @return array{host:string,port:int,ip:string,url:string}|null */
    private static function target(string $url): ?array
    {
        if (!self::$allowPrivate) {
            return Http::guard($url, true);
        }
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $host = trim($p['host'], '[]');
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
        return ['host' => $host, 'port' => $port, 'ip' => $ip, 'url' => $url];
    }

    public static function userAgent(): string
    {
        $site = '';
        try {
            $site = Seo::siteUrl();
        } catch (\Throwable) {
            $site = '';
        }
        return 'AgeCMS-Importer/1.0 (+' . ($site !== '' ? $site : 'https://github.com/likvp/AgeCMS') . ')';
    }

    public function count(): int
    {
        return $this->count;
    }

    public function get(string $url, int $maxBytes = 8388608): ?Response
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = self::target($url);
            if ($target === null) {
                Logger::log('warning', 'importer.http_refused', ['host' => (string)parse_url($url, PHP_URL_HOST)]);
                return null;
            }
            $this->pause();
            $this->count++;
            $headers = [];
            $body = '';
            $ch = curl_init($url);
            $long = $maxBytes >= self::LONG_FROM;
            if ($long) {
                // Under 100 bytes a second for 30 s is a stalled transfer; a slow but moving one goes on to the total limit.
                curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 100);
                curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_TIMEOUT => $long ? max(self::LONG_TIMEOUT, $this->timeout) : $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
                CURLOPT_USERAGENT => self::userAgent(),
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.9,*/*;q=0.8', 'Accept-Language: ru,en;q=0.8'],
                CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $name = strtolower(trim($parts[0]));
                        $headers[$name] = isset($headers[$name]) && $name === 'set-cookie' ? $headers[$name] . "\n" . trim($parts[1]) : trim($parts[1]);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
                    $body .= $chunk;
                    return strlen($body) > $maxBytes ? 0 : strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok === false) {
                return null;
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && ($headers['location'] ?? '') !== '') {
                $url = \AgeCMS\Modules\Importer\Url::resolve($url, $headers['location']);
                if ($url === '') {
                    return null;
                }
                continue;
            }
            return new Response($status, $body, $headers, $url);
        }
        return null;
    }

    private function pause(): void
    {
        $wait = self::$last + $this->delayMs / 1000 - microtime(true);
        if ($wait > 0) {
            usleep((int)($wait * 1000000));
        }
        self::$last = microtime(true);
    }
}
