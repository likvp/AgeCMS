<?php
declare(strict_types=1);

use AgeCMS\{Actor, Cache, Config, Database, EventBus, Installer, Queue, SkillRegistry};

/** Minimal test base: assertions plus a fresh installed site per call of freshSite(). */
abstract class TestCase
{
    public int $assertions = 0;

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== true) {
            throw new AssertionError($message !== '' ? $message : 'Expected true, got ' . var_export($value, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionError(($message !== '' ? $message . ': ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionError(($message !== '' ? $message . ': ' : '') . "Expected to find '{$needle}' in " . substr($haystack, 0, 300));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            throw new AssertionError(($message !== '' ? $message . ': ' : '') . "Did not expect '{$needle}'");
        }
    }

    /** @param class-string<Throwable> $class */
    protected function assertThrows(string $class, callable $fn, string $messagePart = ''): Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionError("Expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messagePart !== '' && !str_contains($e->getMessage(), $messagePart)) {
                throw new AssertionError("Exception message '{$e->getMessage()}' lacks '{$messagePart}'");
            }
            return $e;
        }
        throw new AssertionError("Expected {$class}, nothing was thrown");
    }

    /** Wipes the test sandbox and installs a clean site. @param array<string,mixed> $options */
    protected function freshSite(array $options = []): void
    {
        TestEnv::wipe();
        Config::reset();
        Database::reset();
        EventBus::reset();
        SkillRegistry::reset();
        TestEnv::copyModules();
        Installer::install($options + ['site_url' => 'https://example.test', 'site_name' => 'Test Site', 'admin_password' => 'Passw0rd-test-1', 'demo' => false]);
        // Tests never ask the real update source.
        Config::set('updates.url', '');
    }

    protected function admin(): Actor
    {
        return Actor::cli('test');
    }

    /** @param array<string,mixed> $fields */
    protected function createPage(string $slug, array $fields = [], ?Actor $actor = null): array
    {
        return Queue::submit($actor ?? $this->admin(), 'create_page', ['slug' => $slug, 'fields' => $fields + [
            'title' => 'Page ' . $slug,
            'blocks' => [['id' => 'hero_1', 'type' => 'hero', 'data' => ['h1' => 'Heading ' . $slug]]],
        ]]);
    }
}

/** Paths of the sandbox. Constants are set once in run.php. */
final class TestEnv
{
    public static function root(): string
    {
        return TEST_TMP;
    }

    public static function wipe(): void
    {
        self::remove(TEST_TMP);
        mkdir(TEST_TMP, 0700, true);
    }

    public static function copyModules(): void
    {
        $dest = TEST_TMP . '/modules';
        mkdir($dest, 0700, true);
        $src = dirname(__DIR__) . '/modules';
        foreach (glob($src . '/*', GLOB_ONLYDIR) as $module) {
            self::copy($module, $dest . '/' . basename($module));
        }
    }

    public static function copy(string $from, string $to): void
    {
        mkdir($to, 0700, true);
        foreach (scandir($from) as $item) {
            if ($item === '.' || $item === '..' || $item === 'manifest.json.sig') {
                continue;
            }
            is_dir("{$from}/{$item}") ? self::copy("{$from}/{$item}", "{$to}/{$item}") : copy("{$from}/{$item}", "{$to}/{$item}");
        }
    }

    /**
     * ALTER TABLE ... DROP COLUMN on any SQLite. The statement exists from 3.35; on older builds (3.26 on REG.RU, the
     * oldest the core supports) the table is rebuilt without the columns, with its indexes and triggers put back.
     * Tests use it to put a database back into an older shape.
     *
     * @param list<string> $columns
     */
    public static function dropColumns(PDO $pdo, string $table, array $columns): void
    {
        if (version_compare((string)$pdo->query('SELECT sqlite_version()')->fetchColumn(), '3.35.0', '>=')) {
            foreach ($columns as $column) {
                $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
            }
            return;
        }
        $drop = array_map('strtolower', $columns);
        $create = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetchColumn();
        $body = substr($create, strpos($create, '(') + 1, strrpos($create, ')') - strpos($create, '(') - 1);
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($body) as $ch) {
            $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
            if ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;
        $keep = array_filter($parts, static fn(string $p): bool => !in_array(strtolower(trim((string)strtok(trim($p), " \t\n"), '"`[]')), $drop, true));
        $names = array_values(array_diff(array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name'), $columns));
        $extras = $pdo->query("SELECT sql FROM sqlite_master WHERE tbl_name = " . $pdo->quote($table) . " AND type IN ('index', 'trigger') AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $list = implode(', ', array_map(static fn(string $n): string => '"' . $n . '"', $names));
        $pdo->exec("CREATE TABLE {$table}__rebuilt (" . implode(',', $keep) . ')');
        $pdo->exec("INSERT INTO {$table}__rebuilt ({$list}) SELECT {$list} FROM {$table}");
        $pdo->exec("DROP TABLE {$table}");
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec("ALTER TABLE {$table}__rebuilt RENAME TO {$table}");
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
        foreach ($extras as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException) {
                // an index or trigger on a dropped column goes away with it, as DROP COLUMN would refuse it anyway
            }
        }
    }

    public static function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $item) {
                if ($item !== '.' && $item !== '..') {
                    self::remove($path . '/' . $item);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
