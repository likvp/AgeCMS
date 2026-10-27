<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * The answer of a module. A module chooses the status, the page title and body (HTML rendered inside the theme),
 * a JSON document or a redirect, and may set its own cookies. It cannot set other headers: the security headers
 * and the cache policy always come from the core.
 */
final class ModuleResponse
{
    /** @var list<array{name:string,value:string,ttl:int}> */
    private array $cookies = [];

    private function __construct(
        public readonly string $kind,
        public readonly int $status,
        public readonly string $title,
        public readonly string $body,
    ) {
    }

    public static function html(string $title, string $bodyHtml, int $status = 200): self
    {
        return new self('html', $status, $title, $bodyHtml);
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self('json', $status, '', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** $to is an address on this site, such as "/kabinet". */
    public static function redirect(string $to, int $status = 303): self
    {
        return new self('redirect', $status, '', $to);
    }

    /** Cookie of this module: the core adds the module prefix, HttpOnly, SameSite=Lax and Secure on https. ttl 0 lasts for the browser session. */
    public function withCookie(string $name, string $value, int $ttlSeconds = 0): self
    {
        $copy = clone $this;
        $copy->cookies[] = ['name' => $name, 'value' => $value, 'ttl' => $ttlSeconds];
        return $copy;
    }

    public function withoutCookie(string $name): self
    {
        return $this->withCookie($name, '', -1);
    }

    /** @return list<array{name:string,value:string,ttl:int}> */
    public function cookies(): array
    {
        return $this->cookies;
    }
}
