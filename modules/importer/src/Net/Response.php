<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Net;

/** One fetched document. Headers are lowercased. */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly string $url,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->header('content-type'))[0]));
    }

    public function isHtml(): bool
    {
        $type = $this->contentType();
        if ($type === '') {
            return preg_match('~<(html|body|head|div|p)[\s>]~i', substr($this->body, 0, 4096)) === 1;
        }
        return $type === 'text/html' || $type === 'application/xhtml+xml';
    }

    /** The body as UTF-8: charset from the header, then from the page itself, then a guess for old Cyrillic sites. */
    public function text(): string
    {
        $charset = '';
        if (preg_match('~charset=["\']?([a-z0-9_-]+)~i', $this->header('content-type'), $m) === 1) {
            $charset = $m[1];
        } elseif (preg_match('~<meta[^>]+charset=["\']?([a-z0-9_-]+)~i', substr($this->body, 0, 4096), $m) === 1) {
            $charset = $m[1];
        } elseif (preg_match('~^<\?xml[^>]+encoding=["\']([a-z0-9_-]+)~i', substr($this->body, 0, 200), $m) === 1) {
            $charset = $m[1];
        }
        return self::utf8($this->body, $charset);
    }

    public static function utf8(string $body, string $charset = ''): string
    {
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $charset = strtolower($charset);
        if ($charset !== '' && !in_array($charset, ['utf-8', 'utf8'], true)) {
            $map = ['cp1251' => 'Windows-1251', 'windows-1251' => 'Windows-1251', 'win-1251' => 'Windows-1251', 'koi8-r' => 'KOI8-R', 'iso-8859-1' => 'ISO-8859-1', 'windows-1252' => 'Windows-1252', 'cp866' => 'CP866'];
            $from = $map[$charset] ?? $charset;
            try {
                $converted = @mb_convert_encoding($body, 'UTF-8', $from);
                if (is_string($converted)) {
                    return $converted;
                }
            } catch (\ValueError) {
                // Unknown charset name: fall through to the guess.
            }
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            return (string)mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
        }
        return $body;
    }
}
