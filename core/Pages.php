<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/** Page storage. A page is a document: meta fields plus a list of blocks. */
final class Pages
{
    public const RESERVED = ['admin', 'assets', 'core', 'data', 'modules', 'themes', 'bin', 'tests', 'bench', 'docs', 'search', '_'];
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public static function slugValid(string $slug): bool
    {
        if ($slug === '' || strlen($slug) > 120) {
            return false;
        }
        if (preg_match('~^[a-z0-9]([a-z0-9_-]*[a-z0-9])?(/[a-z0-9]([a-z0-9_-]*[a-z0-9])?)*$~', $slug) !== 1) {
            return false;
        }
        return !in_array(explode('/', $slug)[0], self::RESERVED, true);
    }

    /** @return array<string,mixed>|null */
    public static function row(string $slug): ?array
    {
        return Database::row('SELECT * FROM pages WHERE slug = ?', [$slug]);
    }

    /** @return array<string,mixed>|null */
    public static function doc(string $slug): ?array
    {
        $row = self::row($slug);
        return $row === null ? null : self::toDoc($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toDoc(array $row): array
    {
        $doc = [
            'title' => (string)$row['title'],
            'parent_slug' => (string)$row['parent_slug'],
            'meta_title' => (string)$row['meta_title'],
            'meta_description' => (string)$row['meta_description'],
            'canonical_url' => (string)$row['canonical_url'],
            'og_image' => (string)$row['og_image'],
            'is_noindex' => (int)$row['is_noindex'],
            'is_published' => (int)$row['is_published'],
            'sort_order' => (int)$row['sort_order'],
            'schema_type' => (string)$row['schema_type'],
            'theme' => (string)$row['theme'],
            'blocks' => json_decode((string)$row['blocks_json'], true, 512, JSON_THROW_ON_ERROR),
        ];
        // Same place and the same rule as in SchemaValidator::document(): present only when set.
        if ((string)($row['published_at'] ?? '') !== '') {
            $at = (int)array_search('schema_type', array_keys($doc), true);
            $doc = array_slice($doc, 0, $at, true) + ['published_at' => (string)$row['published_at']] + array_slice($doc, $at, null, true);
        }
        return $doc;
    }

    public static function count(): int
    {
        return (int)Database::value('SELECT COUNT(*) FROM pages');
    }

    /** @return list<array<string,mixed>> */
    public static function listing(bool $publishedOnly = false): array
    {
        $where = $publishedOnly ? 'WHERE is_published = 1' : '';
        return Database::rows("SELECT slug, parent_slug, title, schema_type, is_published, is_noindex, updated_at FROM pages {$where} ORDER BY sort_order, slug");
    }

    /**
     * Inserts or updates the page row and its search index. Must run inside Database::write().
     *
     * @param array<string,mixed> $doc normalized document
     */
    public static function save(PDO $pdo, string $slug, array $doc, bool $isNew): void
    {
        $now = ag_now();
        $blocksJson = json_encode($doc['blocks'], self::JSON);
        if ($isNew) {
            $pdo->prepare(
                'INSERT INTO pages (slug, parent_slug, title, meta_title, meta_description, canonical_url, og_image, is_noindex, is_published, sort_order, published_at, schema_type, theme, blocks_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$slug, $doc['parent_slug'], $doc['title'], $doc['meta_title'], $doc['meta_description'], $doc['canonical_url'], $doc['og_image'], $doc['is_noindex'], $doc['is_published'], $doc['sort_order'], (string)($doc['published_at'] ?? ''), $doc['schema_type'], $doc['theme'], $blocksJson, $now, $now]);
        } else {
            $pdo->prepare(
                'UPDATE pages SET parent_slug = ?, title = ?, meta_title = ?, meta_description = ?, canonical_url = ?, og_image = ?, is_noindex = ?, is_published = ?, sort_order = ?, published_at = ?, schema_type = ?, theme = ?, blocks_json = ?, updated_at = ? WHERE slug = ?'
            )->execute([$doc['parent_slug'], $doc['title'], $doc['meta_title'], $doc['meta_description'], $doc['canonical_url'], $doc['og_image'], $doc['is_noindex'], $doc['is_published'], $doc['sort_order'], (string)($doc['published_at'] ?? ''), $doc['schema_type'], $doc['theme'], $blocksJson, $now, $slug]);
        }
        if ($isNew) {
            // A page now lives at this address: an old redirect from it (after a move or from an import) must not shadow the page.
            $pdo->prepare('DELETE FROM redirects WHERE from_slug IN (?, ?)')->execute([$slug, '/' . ($slug === 'index' ? '' : $slug)]);
        }
        self::index($pdo, $slug, $doc);
    }

    /**
     * Published pages whose parent is $parent: sort_order first, then the newest post (published_at, else the creation time).
     * For listings such as the child_pages block; the template adds the cache tag "children:<parent>" (see ag_child_pages).
     *
     * @return list<array{slug:string,title:string,meta_description:string,og_image:string,published_at:string,sort_order:int}>
     */
    public static function children(string $parent, int $limit = 100): array
    {
        $rows = Database::rows(
            "SELECT slug, title, meta_description, og_image, published_at, sort_order FROM pages WHERE parent_slug = ? AND is_published = 1
             ORDER BY sort_order, CASE WHEN published_at <> '' THEN published_at ELSE created_at END DESC, slug LIMIT ?",
            [$parent, max(1, min(1000, $limit))],
        );
        return array_map(static fn(array $r): array => [
            'slug' => (string)$r['slug'], 'title' => (string)$r['title'], 'meta_description' => (string)$r['meta_description'],
            'og_image' => (string)$r['og_image'], 'published_at' => (string)$r['published_at'], 'sort_order' => (int)$r['sort_order'],
        ], $rows);
    }

    /** Where an address that no longer holds a page now lives, or null. Only when the target page exists. */
    public static function redirectFor(string $slug): ?string
    {
        $to = Database::value('SELECT to_slug FROM redirects WHERE from_slug = ?', [$slug]);
        return $to !== null && self::row((string)$to) !== null ? (string)$to : null;
    }

    /** Removes the page row and its search entry. Must run inside Database::write(). */
    public static function delete(PDO $pdo, string $slug): void
    {
        $pdo->prepare('DELETE FROM pages WHERE slug = ?')->execute([$slug]);
        $fts = $pdo->query("SELECT value FROM settings WHERE key = 'fts'")->fetchColumn();
        if ($fts !== '0') {
            $pdo->prepare('DELETE FROM pages_fts WHERE slug = ?')->execute([$slug]);
        }
    }

    /**
     * Pages whose last revision is a deletion, newest first. They can be restored from the ledger.
     *
     * @return list<array<string,mixed>>
     */
    public static function deleted(): array
    {
        return Database::rows(
            "SELECT r.page_slug AS slug, r.id AS revision_id, r.created_at, r.actor_name FROM revisions r
             JOIN (SELECT page_slug, MAX(id) AS last_id FROM revisions WHERE page_slug NOT LIKE '@%' GROUP BY page_slug) m ON m.last_id = r.id
             WHERE r.action_desc = ? AND r.page_slug NOT IN (SELECT slug FROM pages) ORDER BY r.id DESC",
            [Content::DELETE_DESC],
        );
    }

    /** @param array<string,mixed> $doc */
    private static function index(PDO $pdo, string $slug, array $doc): void
    {
        $fts = $pdo->query("SELECT value FROM settings WHERE key = 'fts'")->fetchColumn();
        if ($fts === '0') {
            return;
        }
        $pdo->prepare('DELETE FROM pages_fts WHERE slug = ?')->execute([$slug]);
        if ((int)$doc['is_published'] === 1 && (int)$doc['is_noindex'] === 0) {
            $texts = [];
            array_walk_recursive($doc['blocks'], static function ($v, $k) use (&$texts): void {
                if (is_string($v) && !in_array($k, ['id', 'type', 'size', 'cta_url'], true)) {
                    $texts[] = self::plainText($v);
                }
            });
            $pdo->prepare('INSERT INTO pages_fts (slug, title, body) VALUES (?, ?, ?)')->execute([$slug, $doc['title'], implode("\n", $texts)]);
        }
    }

    /** Text of a richtext value for the search index: tags out, block ends as line breaks. Other values pass unchanged. */
    public static function plainText(string $value): string
    {
        if (!str_contains($value, '<') || preg_match('~</?(p|br|h[1-6]|ul|ol|li|a|strong|b|em|i|u|s|blockquote|figure|figcaption|img|table|tr|td|th|hr|code|pre)\b~i', $value) !== 1) {
            return $value;
        }
        $value = preg_replace('~<(br|hr)\b[^>]*>|</(p|h[1-6]|li|blockquote|figcaption|tr|td|th|pre)>~i', "$0\n", $value) ?? $value;
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Slugs of all ancestors, nearest last: "a/b/c" gives ["a", "a/b"]. @return list<string> */
    public static function ancestors(string $slug): array
    {
        $parts = explode('/', $slug);
        array_pop($parts);
        $out = [];
        $acc = '';
        foreach ($parts as $part) {
            $acc = $acc === '' ? $part : $acc . '/' . $part;
            $out[] = $acc;
        }
        return $out;
    }
}
