<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\Actor;
use AgeCMS\CircuitBreaker;
use AgeCMS\Content;
use AgeCMS\Media;
use AgeCMS\Modules\Importer\Html\Sanitizer;
use AgeCMS\SchemaValidator;
use AgeCMS\ValidationException;

/**
 * The parts of the core the importer uses that may be missing on an older core: rich text, published_at, redirects from
 * old addresses, file import into the media library, permission for a bulk create. Each one is probed once and falls back
 * softly; Importer::capabilities() reports what is there.
 */
final class Compat
{
    private static ?bool $publishedAt = null;

    public static function richTextClass(): bool
    {
        return class_exists('AgeCMS\\RichText') && method_exists('AgeCMS\\RichText', 'sanitize');
    }

    /** HTML for a richtext field: the importer's own white list, then the core's sanitizer when it is there. */
    public static function richText(string $html): string
    {
        $html = Sanitizer::rich($html);
        if (self::richTextClass()) {
            return (string)\AgeCMS\RichText::sanitize($html);
        }
        return $html;
    }

    /** Whether page documents take published_at (asked from the validator, the only place that knows). */
    public static function publishedAt(): bool
    {
        if (self::$publishedAt !== null) {
            return self::$publishedAt;
        }
        try {
            SchemaValidator::document(['title' => 'probe', 'published_at' => '2020-01-02']);
            return self::$publishedAt = true;
        } catch (ValidationException $e) {
            return self::$publishedAt = !str_contains($e->getMessage(), "'published_at'");
        } catch (\Throwable) {
            return self::$publishedAt = false;
        }
    }

    public static function redirects(): bool
    {
        return method_exists(Content::class, 'addRedirects') && method_exists(Content::class, 'removeRedirects');
    }

    /** Checks one redirect the way the core will; null when the core has no checker. @return array{0:string,1:string}|string|null */
    public static function checkRedirect(string $from, string $to): array|string|null
    {
        if (!class_exists('AgeCMS\\Redirects') || !method_exists('AgeCMS\\Redirects', 'check')) {
            return null;
        }
        return \AgeCMS\Redirects::check($from, $to);
    }

    /** Stores a local image file in the media library; one picture is one file (the name is a hash of the content). */
    public static function importFile(string $path): string
    {
        if (method_exists(Media::class, 'importFile')) {
            return (string)Media::importFile($path);
        }
        return Media::optimize($path);
    }

    public static function allowBulkCreate(Actor $actor, int $count, string $reason): bool
    {
        if (!method_exists(CircuitBreaker::class, 'allowBulkCreate')) {
            return false;
        }
        CircuitBreaker::allowBulkCreate($actor, $count, $reason);
        return true;
    }

    /** @return array<string,bool> */
    public static function capabilities(): array
    {
        return [
            'richtext' => self::richTextClass(),
            'published_at' => self::publishedAt(),
            'redirects' => self::redirects(),
            'media_import_file' => method_exists(Media::class, 'importFile'),
            'bulk_create' => method_exists(CircuitBreaker::class, 'allowBulkCreate'),
            'images' => Media::available(),
            'dom' => class_exists(\DOMDocument::class),
            'zip' => class_exists(\ZipArchive::class),
            'curl' => function_exists('curl_init'),
        ];
    }

    public static function reset(): void
    {
        self::$publishedAt = null;
    }
}
