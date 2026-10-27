<?php
declare(strict_types=1);

namespace AgeCMS;

use PDO;
use PDOException;
use Throwable;

/** Singleton PDO connection to data/data.sqlite in WAL mode. */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function file(): string
    {
        return AGECMS_DATA . '/data.sqlite';
    }

    public static function exists(): bool
    {
        return is_file(self::file());
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (!self::exists()) {
                throw new NotInstalledException('AgeCMS is not installed. Run: php bin/install.php');
            }
            self::$pdo = self::open();
        }
        return self::$pdo;
    }

    /** Creates the database file and applies all migrations. Used by the installer only. */
    public static function create(): PDO
    {
        self::reset();
        if (!is_dir(AGECMS_DATA) && !mkdir(AGECMS_DATA, 0700, true) && !is_dir(AGECMS_DATA)) {
            throw new AgeCMSException('Cannot create ' . AGECMS_DATA);
        }
        self::$pdo = self::open();
        Schema::migrate(self::$pdo);
        @chmod(self::file(), 0600);
        return self::$pdo;
    }

    private static function open(): PDO
    {
        $pdo = new PDO('sqlite:' . self::file(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    /**
     * Runs $fn inside BEGIN IMMEDIATE so two writers never deadlock on lock upgrade.
     * Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function write(callable $fn): mixed
    {
        $pdo = self::pdo();
        if (self::$depth > 0) {
            return $fn($pdo);
        }
        for ($attempt = 1;; $attempt++) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                break;
            } catch (PDOException $e) {
                if (!self::isBusy($e) || $attempt >= 5) {
                    throw $e;
                }
                usleep(50000 * $attempt);
            }
        }
        self::$depth = 1;
        try {
            $result = $fn($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            // Not PDO::inTransaction(): before PHP 8.4 it does not see a BEGIN issued through exec().
            try {
                $pdo->exec('ROLLBACK');
            } catch (PDOException) {
                // SQLite already ended the transaction on its own.
            }
            throw $e;
        } finally {
            self::$depth = 0;
        }
    }

    public static function reset(): void
    {
        self::$pdo = null;
        self::$depth = 0;
    }

    /** @param array<int|string,mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function rows(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function row(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private static function isBusy(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'SQLITE_BUSY');
    }
}
