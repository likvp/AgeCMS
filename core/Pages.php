<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;

/** Page storage. A page is a document: meta fields plus a list of blocks. */
final class Pages
{
    private const RESERVED = ['admin', 'assets', 'core', 'data', 'modules', 'themes', 'bin', 'tests', 'bench', 'docs', 'search', '_'];
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
        return [
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
                'INSERT INTO pages (slug, parent_slug, title, meta_title, meta_description, canonical_url, og_image, is_noindex, is_published, sort_order, schema_type, theme, blocks_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$slug, $doc['parent_slug'], $doc['title'], $doc['meta_title'], $doc['meta_description'], $doc['canonical_url'], $doc['og_image'], $doc['is_noindex'], $doc['is_published'], $doc['sort_order'], $doc['schema_type'], $doc['theme'], $blocksJson, $now, $now]);
        } else {
            $pdo->prepare(
                'UPDATE pages SET parent_slug = ?, title = ?, meta_title = ?, meta_description = ?, canonical_url = ?, og_image = ?, is_noindex = ?, is_published = ?, sort_order = ?, schema_type = ?, theme = ?, blocks_json = ?, updated_at = ? WHERE slug = ?'
            )->execute([$doc['parent_slug'], $doc['title'], $doc['meta_title'], $doc['meta_description'], $doc['canonical_url'], $doc['og_image'], $doc['is_noindex'], $doc['is_published'], $doc['sort_order'], $doc['schema_type'], $doc['theme'], $blocksJson, $now, $slug]);
        }
        self::index($pdo, $slug, $doc);
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
                    $texts[] = $v;
                }
            });
            $pdo->prepare('INSERT INTO pages_fts (slug, title, body) VALUES (?, ?, ?)')->execute([$slug, $doc['title'], implode("\n", $texts)]);
        }
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
