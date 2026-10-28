<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Journal of everything that is not a page edit: core updates, users, tokens, modules, mail, backups and so on.
 * Entries go into the same hash-chained `revisions` ledger under pseudo-slugs that start with '@', so the
 * admin history shows them next to page edits. Never put a password, token or key into a description.
 */
final class Audit
{
    public const CORE = '@core';
    public const USERS = '@users';
    public const TOKENS = '@tokens';
    public const MODULES = '@modules';
    public const MAIL = '@mail';
    public const BACKUP = '@backup';
    public const SYSTEM = '@system';
    public const MEDIA = '@media';

    private const LABELS = [
        self::CORE => 'Ядро',
        self::USERS => 'Пользователи',
        self::TOKENS => 'Токены агентов',
        self::MODULES => 'Модули',
        self::MAIL => 'Почта',
        self::BACKUP => 'Бэкапы',
        self::SYSTEM => 'Система',
        self::MEDIA => 'Медиа',
    ];

    private static ?Actor $actor = null;
    private static bool $quiet = false;

    /** Runs $fn without journal entries: the first administrator of a fresh install is not an event. */
    public static function quietly(callable $fn): mixed
    {
        $before = self::$quiet;
        self::$quiet = true;
        try {
            return $fn();
        } finally {
            self::$quiet = $before;
        }
    }

    /** Readable name of a history scope for the admin; null for pages and the older '@settings'/'@menu/*' scopes. */
    public static function label(string $scope): ?string
    {
        return self::LABELS[$scope] ?? null;
    }


    /** The entry points (admin, REST, MCP, CLI) say who is acting; entries made later in the request use it. */
    public static function as(?Actor $actor): void
    {
        self::$actor = $actor;
    }

    public static function actor(): Actor
    {
        return self::$actor ?? (PHP_SAPI === 'cli' ? Actor::cli() : Actor::system());
    }

    /** Actor from the `$by` strings the older code passes around: "human:name", "agent:name", plain name. */
    public static function actorFor(?string $by): Actor
    {
        if ($by === null || $by === '') {
            return self::actor();
        }
        if (preg_match('/^(human|agent|system|cli):(.*)$/', $by, $m) === 1) {
            return new Actor($m[1], $m[2] !== '' ? $m[2] : $m[1], 'admin');
        }
        $current = self::$actor;
        return $current !== null && $current->name === $by ? $current : Actor::human($by);
    }

    /**
     * The history gets the last core update if the core that installed it had no journal yet (the update
     * from such a version to this one is the case). Called on the history page and by the background work.
     */
    public static function catchUp(): void
    {
        try {
            $last = Updater::last();
            if ($last === null || Updater::lastAudited()) {
                return;
            }
            $when = gmdate('Y-m-d H:i', $last['at']) . ' UTC';
            $text = $last['ok']
                ? "Обновление ядра: {$last['from']} -> {$last['to']} (выполнено {$when}, записано позже: прежнее ядро не вело журнал)"
                : "Обновление ядра не удалось ({$last['from']} -> {$last['to']}, {$when}): {$last['error']}";
            self::record(self::CORE, $text, Updater::journalActor($last['by']));
            Updater::markAudited();
        } catch (Throwable $e) {
            Logger::log('error', 'audit.catchup_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Writes one line to the history. Best effort: a failed journal write must not undo the action it describes.
     * Inside a running transaction the entry becomes part of it.
     */
    public static function record(string $scope, string $description, ?string $by = null): void
    {
        if (self::$quiet) {
            return;
        }
        try {
            $actor = self::actorFor($by);
            $description = mb_substr(trim($description), 0, 500);
            Database::write(static function (\PDO $pdo) use ($scope, $actor, $description): void {
                Revisions::mark($pdo, $scope, $actor, $description);
            });
        } catch (Throwable $e) {
            Logger::log('error', 'audit.failed', ['scope' => $scope, 'error' => $e->getMessage()]);
        }
    }
}
