<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;
use Throwable;

/**
 * Shadow cache: public pages rendered once and kept as assets/cache/<slug>.html so the web server
 * (or the first lines of index.php) can answer without PHP or SQLite. Each file is tagged with what it
 * was built from, so changing one menu or page only drops the files that used it.
 */
final class Cache
{
    public static function dir(): string
    {
        return AGECMS_CACHE;
    }

    public static function fileFor(string $slug): string
    {
        return self::dir() . '/' . $slug . '.html';
    }

    /** Maps a URL path to a page slug, or null when the path cannot be a page. */
    public static function slugForPath(string $path): ?string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return 'index';
        }
        return Pages::slugValid($path) ? $path : null;
    }

    public static function generation(): int
    {
        return (int)(Database::value("SELECT value FROM settings WHERE key = 'cache_gen'") ?? 0);
    }

    /**
     * Stores rendered HTML. $generation is the value read before rendering: if the cache was
     * invalidated in between, the page may be stale and is not stored.
     *
     * @param list<string> $tags
     */
    public static function store(string $slug, string $html, array $tags, int $generation): bool
    {
        try {
            return (bool)Database::write(static function (PDO $pdo) use ($slug, $html, $tags, $generation): bool {
                $current = $pdo->query("SELECT value FROM settings WHERE key = 'cache_gen'")->fetchColumn();
                if ((int)($current === false ? 0 : $current) !== $generation) {
                    return false;
                }
                $file = self::fileFor($slug);
                $dir = dirname($file);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return false;
                }
                $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
                if (file_put_contents($tmp, $html, LOCK_EX) === false) {
                    return false;
                }
                @chmod($tmp, 0644);
                rename($tmp, $file);

                $pdo->prepare('DELETE FROM cache_tags WHERE slug = ?')->execute([$slug]);
                $insert = $pdo->prepare('INSERT OR IGNORE INTO cache_tags (tag, slug) VALUES (?, ?)');
                foreach (array_unique($tags) as $tag) {
                    $insert->execute([$tag, $slug]);
                }
                return true;
            });
        } catch (Throwable $e) {
            Logger::log('error', 'cache.store_failed', ['slug' => $slug, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Drops every cached page that carries one of the tags and queues them for a background rebuild.
     * The tag "all" clears everything.
     *
     * @param list<string> $tags
     * @return list<string> slugs that were dropped
     */
    public static function invalidate(array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $slugs = Database::write(static function (PDO $pdo) use ($tags): array {
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('cache_gen', '1') ON CONFLICT(key) DO UPDATE SET value = CAST(value AS INTEGER) + 1");
            if (in_array('all', $tags, true)) {
                $found = $pdo->query('SELECT DISTINCT slug FROM cache_tags')->fetchAll(PDO::FETCH_COLUMN);
                $pdo->exec('DELETE FROM cache_tags');
                return $found;
            }
            $marks = implode(',', array_fill(0, count($tags), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT slug FROM cache_tags WHERE tag IN ({$marks})");
            $stmt->execute(array_values($tags));
            $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($found !== []) {
                $m2 = implode(',', array_fill(0, count($found), '?'));
                $pdo->prepare("DELETE FROM cache_tags WHERE slug IN ({$m2})")->execute($found);
            }
            return $found;
        });
        foreach ($slugs as $slug) {
            @unlink(self::fileFor((string)$slug));
        }
        if ($slugs !== []) {
            self::queueRebuild(array_map('strval', $slugs));
        }
        return array_map('strval', $slugs);
    }

    /** Removes every cached file, also files left over without tags. */
    public static function clearAll(): int
    {
        $count = 0;
        $dir = self::dir();
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.html')) {
                    @unlink($file->getPathname());
                    $count++;
                } elseif ($file->isDir()) {
                    @rmdir($file->getPathname());
                }
            }
        }
        Database::write(static function (PDO $pdo): void {
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('cache_gen', '1') ON CONFLICT(key) DO UPDATE SET value = CAST(value AS INTEGER) + 1");
            $pdo->exec('DELETE FROM cache_tags');
        });
        return $count;
    }

    /** @param list<string> $slugs */
    private static function queueRebuild(array $slugs): void
    {
        $dir = Queue::dir() . '/rebuild';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($dir . '/' . (int)(microtime(true) * 1000000) . '-' . bin2hex(random_bytes(3)) . '.json', json_encode($slugs));
    }

    /**
     * Renders and stores the slugs queued by invalidate(). Run after a save and by the optional cron worker.
     * Slugs left over when the limit or the time budget runs out are queued again, or built on the next visit.
     *
     * @param float|null $deadline microtime() after which no more pages are started
     * @return int number of pages rebuilt
     */
    public static function processRebuildQueue(int $limit = 200, ?float $deadline = null): int
    {
        $files = glob(Queue::dir() . '/rebuild/*.json') ?: [];
        sort($files, SORT_STRING);
        $slugs = [];
        foreach ($files as $file) {
            foreach ((array)json_decode((string)@file_get_contents($file), true) as $s) {
                $slugs[(string)$s] = true;
            }
            @unlink($file);
        }
        $done = 0;
        $todo = array_map('strval', array_keys($slugs));
        foreach ($todo as $i => $slug) {
            if ($i >= $limit || ($deadline !== null && microtime(true) > $deadline)) {
                self::queueRebuild(array_slice($todo, $i));
                break;
            }
            if (self::warm($slug)) {
                $done++;
            }
        }
        return $done;
    }

    /** Renders one public page and stores it. */
    public static function warm(string $slug): bool
    {
        $generation = self::generation();
        $page = Renderer::renderSlug($slug, false);
        if ($page === null || $page['status'] !== 200) {
            return false;
        }
        return self::store($slug, $page['html'], $page['tags'], $generation);
    }
}
