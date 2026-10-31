<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Net;

/**
 * Fetcher over a fixed map of URL => response, for tests and for files already on disk. A value is the body string,
 * or [status, body, headers]. A trailing "*" in a key matches any URL with that prefix.
 */
final class ArrayFetcher implements Fetcher
{
    private int $count = 0;
    /** @var list<string> */
    public array $requested = [];

    /** @param array<string,string|array{0:int,1:string,2?:array<string,string>}> $map */
    public function __construct(private array $map)
    {
    }

    public function count(): int
    {
        return $this->count;
    }

    public function get(string $url, int $maxBytes = 8388608): ?Response
    {
        $this->count++;
        $this->requested[] = $url;
        $value = $this->map[$url] ?? null;
        if ($value === null) {
            foreach ($this->map as $key => $candidate) {
                if (str_ends_with($key, '*') && str_starts_with($url, substr($key, 0, -1))) {
                    $value = $candidate;
                    break;
                }
            }
        }
        if ($value === null) {
            return new Response(404, 'Not found', ['content-type' => 'text/html'], $url);
        }
        if (is_string($value)) {
            $type = str_starts_with(ltrim($value), '{') || str_starts_with(ltrim($value), '[') ? 'application/json' : (str_starts_with(ltrim($value), '<?xml') ? 'application/xml' : 'text/html; charset=utf-8');
            return new Response(200, $value, ['content-type' => $type], $url);
        }
        $headers = array_change_key_case($value[2] ?? [], CASE_LOWER);
        if (in_array($value[0], [301, 302], true) && isset($headers['location'])) {
            return $this->get(\AgeCMS\Modules\Importer\Url::resolve($url, $headers['location']), $maxBytes);
        }
        return new Response($value[0], $value[1], $headers + ['content-type' => 'text/html; charset=utf-8'], $url);
    }
}
