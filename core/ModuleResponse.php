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

    private string $description = '';

    private string $image = '';

    /** @var array<string,string> */
    private array $meta = [];

    /** og:* and twitter:* the core writes itself; withImage() sets the picture, the rest comes from the page. og:type may be replaced. */
    private const RESERVED_META = ['og:title', 'og:description', 'og:url', 'og:site_name', 'og:image', 'og:image:url', 'og:image:secure_url', 'twitter:card', 'twitter:image', 'twitter:title', 'twitter:description'];

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

    /** Text for the description meta tag of a page of an indexable route (cut to 300 characters); other routes ignore it. */
    public function withDescription(string $description): self
    {
        $copy = clone $this;
        $copy->description = mb_substr(trim(strip_tags($description)), 0, 300);
        return $copy;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * The picture of the page for link previews (og:image, twitter:image, twitter:card summary_large_image) on a page of an
     * indexable route; other routes ignore it. An https or http address, or a path from the root of the site ("/assets/..."),
     * which the core turns into a full address; spaces become %20. Anything else (quotes, line breaks, javascript:, "//host") is dropped.
     */
    public function withImage(string $url): self
    {
        $url = str_replace(' ', '%20', trim($url));
        $ok = strlen($url) <= 2000 && (
            preg_match('~^https?://[^\s"\'<>\\\\\x00-\x1F\x7F]+$~i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false
            || preg_match('~^/(?![/\\\\])[^\s"\'<>\\\\\x00-\x1F\x7F]*$~', $url) === 1
        );
        $copy = clone $this;
        $copy->image = $ok ? $url : '';
        return $copy;
    }

    public function image(): string
    {
        return $this->image;
    }

    /**
     * One more meta tag for link previews on a page of an indexable route: og:* and product:* / article:* as property,
     * twitter:* as name (for example og:type "product", og:image:alt, product:price:amount, twitter:image:alt). Tags the core
     * writes itself (title, description, url, image, card) cannot be replaced. Text is stripped of tags, cut to 300 characters
     * and escaped. At most 12 tags; a key that is not allowed is dropped. No other HTML can reach the head.
     */
    public function withMeta(string $key, string $content): self
    {
        $key = strtolower(trim($key));
        $copy = clone $this;
        if (preg_match('/^(og|product|article|twitter):[a-z0-9_]+(:[a-z0-9_]+){0,2}$/', $key) !== 1 || strlen($key) > 60 || in_array($key, self::RESERVED_META, true)
            || (!isset($copy->meta[$key]) && count($copy->meta) >= 12)) {
            return $copy;
        }
        $copy->meta[$key] = mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags($content))), 0, 300);
        return $copy;
    }

    /** @return array<string,string> key => content */
    public function meta(): array
    {
        return $this->meta;
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
