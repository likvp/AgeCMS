<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;
use Throwable;

/**
 * Applies the SQL files a module lists as "migrations" in its signed manifest (migrations/*.sql, covered by the file hashes).
 *
 * - Runs only for modules the registry has verified; a blocked or unsigned module never reaches this class.
 * - Every file runs once. All pending files of a module run in one transaction: a failure leaves nothing applied.
 * - The module's schema state lives in its own tables (module_migrations, module_state), apart from the core schema version.
 * - A migration may only create and change tables, indexes and views named mod_<module>_*. Other statements are refused,
 *   and after the run the core's own tables must be byte-for-byte what they were.
 * - A failure disables the module and is logged. The failure is remembered per manifest, so the site does not retry on
 *   every request; signing the module again (a fixed manifest) allows a new attempt.
 */
final class ModuleMigrator
{
    private const ID = '(?:"([a-z0-9_]+)"|`([a-z0-9_]+)`|\[([a-z0-9_]+)\]|([a-z0-9_]+))';

    /** @param array<string,mixed> $manifest verified manifest */
    public static function run(string $module, array $manifest, string $dir): void
    {
        $files = $manifest['migrations'] ?? [];
        if (!is_array($files) || $files === []) {
            return;
        }
        $manifestHash = hash_file('sha256', $dir . '/manifest.json');
        Database::write(static fn(PDO $pdo) => self::createBookkeeping($pdo));

        $failed = Database::row('SELECT manifest_sha, reason FROM module_state WHERE module = ?', [$module]);
        if ($failed !== null && $failed['manifest_sha'] === $manifestHash) {
            throw new AgeCMSException('migration failed earlier: ' . $failed['reason']);
        }

        try {
            $applied = Database::write(static fn(PDO $pdo): int => self::apply($pdo, $module, $files, $dir));
        } catch (Throwable $e) {
            $reason = mb_substr($e->getMessage(), 0, 500);
            Database::write(static fn(PDO $pdo) => $pdo->prepare('INSERT OR REPLACE INTO module_state (module, manifest_sha, reason, failed_at) VALUES (?, ?, ?, ?)')
                ->execute([$module, $manifestHash, $reason, ag_now()]));
            Logger::log('error', 'module.migration_failed', ['module' => $module, 'error' => $reason]);
            throw new AgeCMSException('migration failed: ' . $reason);
        }
        if ($applied > 0) {
            Logger::log('info', 'module.migrated', ['module' => $module, 'files' => $applied]);
        }
        Database::write(static fn(PDO $pdo) => $pdo->prepare('DELETE FROM module_state WHERE module = ?')->execute([$module]));
    }

    /** Number of migration files already applied for the module. */
    public static function version(string $module): int
    {
        try {
            return (int)Database::value('SELECT COUNT(*) FROM module_migrations WHERE module = ?', [$module]);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function prefix(string $module): string
    {
        return 'mod_' . $module . '_';
    }

    private static function createBookkeeping(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS module_migrations (module TEXT NOT NULL, file TEXT NOT NULL, sha256 TEXT NOT NULL, applied_at TEXT NOT NULL, PRIMARY KEY (module, file))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS module_state (module TEXT PRIMARY KEY, manifest_sha TEXT NOT NULL, reason TEXT NOT NULL, failed_at TEXT NOT NULL)');
    }

    /**
     * @param list<string> $files
     * @return int how many files were applied now
     */
    private static function apply(PDO $pdo, string $module, array $files, string $dir): int
    {
        $stmt = $pdo->prepare('SELECT file, sha256 FROM module_migrations WHERE module = ?');
        $stmt->execute([$module]);
        $done = array_column($stmt->fetchAll(), 'sha256', 'file');

        $pending = [];
        foreach ($files as $file) {
            $hash = hash_file('sha256', $dir . '/' . $file);
            if (isset($done[$file])) {
                if ($done[$file] !== $hash) {
                    throw new AgeCMSException("applied migration {$file} was changed afterwards");
                }
                continue;
            }
            $pending[$file] = ['hash' => $hash, 'sql' => (string)file_get_contents($dir . '/' . $file)];
        }
        if ($pending === []) {
            return 0;
        }

        $before = self::objects($pdo);
        $prefix = self::prefix($module);
        foreach ($pending as $file => $item) {
            try {
                foreach (self::statements($item['sql']) as $sql) {
                    self::check($sql, $prefix);
                    $pdo->exec($sql);
                }
            } catch (Throwable $e) {
                throw new AgeCMSException("{$file}: " . $e->getMessage());
            }
        }
        $after = self::objects($pdo);
        foreach ($before as $key => $sql) {
            if (($after[$key] ?? null) !== $sql && !str_starts_with(explode(':', $key, 2)[1], $prefix)) {
                throw new AgeCMSException("the migration changed the core object {$key}");
            }
        }
        foreach ($after as $key => $sql) {
            if (!isset($before[$key]) && !str_starts_with(explode(':', $key, 2)[1], $prefix)) {
                throw new AgeCMSException("the migration created {$key}: names must start with {$prefix}");
            }
        }

        $insert = $pdo->prepare('INSERT INTO module_migrations (module, file, sha256, applied_at) VALUES (?, ?, ?, ?)');
        foreach ($pending as $file => $item) {
            $insert->execute([$module, $file, $item['hash'], ag_now()]);
        }
        return count($pending);
    }

    /** @return array<string,string> "type:name" => sql of every schema object */
    private static function objects(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT type, name, COALESCE(sql, '') AS sql FROM sqlite_master WHERE name NOT LIKE 'sqlite\\_%' ESCAPE '\\'")->fetchAll() as $row) {
            $out[$row['type'] . ':' . $row['name']] = $row['sql'];
        }
        return $out;
    }

    /**
     * Splits the file into statements without comments. A semicolon inside a quoted value or name does not split.
     *
     * @return list<string>
     */
    private static function statements(string $sql): array
    {
        $out = [];
        $current = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($c === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= ' ';
            } elseif ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new AgeCMSException('unterminated comment');
                }
                $i = $end + 1;
                $current .= ' ';
            } elseif ($c === "'" || $c === '"' || $c === '`' || $c === '[') {
                $close = $c === '[' ? ']' : $c;
                $current .= $c;
                for ($i++; $i < $length; $i++) {
                    $current .= $sql[$i];
                    if ($sql[$i] === $close) {
                        if ($close !== ']' && ($sql[$i + 1] ?? '') === $close) {
                            $current .= $sql[++$i];
                            continue;
                        }
                        break;
                    }
                }
                if ($i >= $length) {
                    throw new AgeCMSException('unterminated quote');
                }
            } elseif ($c === ';') {
                if (trim($current) !== '') {
                    $out[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if (trim($current) !== '') {
            $out[] = trim($current);
        }
        return $out;
    }

    /** Only these statements, and only on the module's own tables, indexes and views. */
    private static function check(string $statement, string $prefix): void
    {
        $id = self::ID;
        $own = static function (array $m, int $from = 1) use ($prefix): bool {
            $name = strtolower(implode('', array_slice($m, $from, 4)));
            return str_starts_with($name, $prefix);
        };
        $rules = [
            "/^CREATE\\s+(?:UNIQUE\\s+)?INDEX\\s+(?:IF\\s+NOT\\s+EXISTS\\s+){$id}\\s+ON\\s+{$id}\\b/i" => 2,
            "/^CREATE\\s+(?:UNIQUE\\s+)?INDEX\\s+{$id}\\s+ON\\s+{$id}\\b/i" => 2,
            "/^CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?{$id}\\b/i" => 1,
            "/^CREATE\\s+VIEW\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?{$id}\\b/i" => 1,
            "/^DROP\\s+(?:TABLE|INDEX|VIEW)\\s+(?:IF\\s+EXISTS\\s+)?{$id}\\s*\$/i" => 1,
            "/^ALTER\\s+TABLE\\s+{$id}\\s/i" => 1,
            "/^INSERT\\s+(?:OR\\s+(?:IGNORE|REPLACE)\\s+)?INTO\\s+{$id}\\b/i" => 1,
            "/^REPLACE\\s+INTO\\s+{$id}\\b/i" => 1,
            "/^UPDATE\\s+(?:OR\\s+(?:IGNORE|REPLACE)\\s+)?{$id}\\s+SET\\b/i" => 1,
            "/^DELETE\\s+FROM\\s+{$id}\\b/i" => 1,
        ];
        foreach ($rules as $pattern => $names) {
            if (preg_match($pattern, $statement, $m) === 1) {
                // Each identifier takes four capture groups (one per quoting style); a rule with two names checks both.
                for ($n = 0; $n < $names; $n++) {
                    if (!$own($m, 1 + $n * 4)) {
                        throw new AgeCMSException('statement touches a table that is not the module\'s own (names start with ' . $prefix . '): ' . mb_substr($statement, 0, 80));
                    }
                }
                return;
            }
        }
        throw new AgeCMSException('statement is not allowed in a module migration: ' . mb_substr($statement, 0, 80));
    }
}
