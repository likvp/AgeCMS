<?php
declare(strict_types=1);

namespace AgeCMS;

/** Full-text search over published pages: FTS5 when available, LIKE otherwise. */
final class Search
{
    /** @return list<array{slug:string,title:string,snippet:string}> */
    public static function query(string $q, int $limit = 20): array
    {
        $q = trim(mb_substr($q, 0, 100));
        if ($q === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        if (Database::value("SELECT value FROM settings WHERE key = 'fts'") !== '0') {
            // Quote every word so user input can never become FTS syntax.
            $words = preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                return [];
            }
            $match = implode(' ', array_map(static fn(string $w): string => '"' . $w . '"*', array_slice($words, 0, 8)));
            return Database::rows(
                "SELECT slug, title, snippet(pages_fts, 2, '', '', '...', 24) AS snippet FROM pages_fts WHERE pages_fts MATCH ? ORDER BY rank LIMIT ?",
                [$match, $limit],
            );
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        return Database::rows(
            "SELECT slug, title, '' AS snippet FROM pages WHERE is_published = 1 AND is_noindex = 0 AND (title LIKE ? ESCAPE '\\' OR blocks_json LIKE ? ESCAPE '\\') LIMIT ?",
            [$like, $like, $limit],
        );
    }
}
