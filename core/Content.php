<?php
declare(strict_types=1);

namespace AgeCMS;

use InvalidArgumentException;
use PDO;

/**
 * The only place that writes pages and menus. Every operation checks the role, the freeze flag,
 * the schema and the circuit breaker, records a revision and then invalidates the cache by tags.
 * Normally called from the write queue, which makes sure only one writer runs at a time.
 */
final class Content
{
    /**
     * Sets one field of one block. $path is dotted: "h1" or "items.2.title".
     *
     * @return array<string,mixed>
     */
    public static function updateBlockField(Actor $actor, string $slug, string $blockId, string $path, mixed $value): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $blockId, $path, $value): array {
            $before = self::requireDoc($slug);
            $after = $before;
            $index = self::blockIndex($after, $blockId);
            try {
                self::setPath($after['blocks'][$index]['data'], explode('.', $path), $value);
            } catch (InvalidArgumentException $e) {
                throw new ValidationException([$e->getMessage()]);
            }
            return self::commit($pdo, $actor, $slug, "Правка поля {$blockId}.{$path}", $before, SchemaValidator::document($after), false);
        });
    }

    /**
     * Merges $payload into the block's data (top-level keys only).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function updateBlockData(Actor $actor, string $slug, string $blockId, array $payload): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $blockId, $payload): array {
            if ($payload === []) {
                throw new ValidationException(['payload must not be empty']);
            }
            $before = self::requireDoc($slug);
            $after = $before;
            $index = self::blockIndex($after, $blockId);
            foreach ($payload as $key => $value) {
                $after['blocks'][$index]['data'][$key] = $value;
            }
            return self::commit($pdo, $actor, $slug, "Правка блока {$blockId}: " . implode(', ', array_keys($payload)), $before, SchemaValidator::document($after), false);
        });
    }

    /**
     * @param array<string,mixed> $fields page document fields, "title" is required
     * @return array<string,mixed>
     */
    public static function createPage(Actor $actor, string $slug, array $fields): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $fields): array {
            if (!Pages::slugValid($slug)) {
                throw new ValidationException(["'{$slug}' is not a valid slug"]);
            }
            if (Pages::row($slug) !== null) {
                throw new ValidationException(["Page '{$slug}' already exists"]);
            }
            return self::commit($pdo, $actor, $slug, 'Создание страницы', [], SchemaValidator::document($fields), true);
        });
    }

    /**
     * Changes meta fields and/or replaces the blocks list.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public static function updatePage(Actor $actor, string $slug, array $fields): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $fields): array {
            if ($fields === []) {
                throw new ValidationException(['No fields to update']);
            }
            $before = self::requireDoc($slug);
            $after = array_replace($before, $fields);
            return self::commit($pdo, $actor, $slug, 'Правка страницы: ' . implode(', ', array_keys($fields)), $before, SchemaValidator::document($after), false);
        });
    }

    /**
     * Restores the page to its state right after revision $revisionId. The rollback is itself a new revision.
     *
     * @return array<string,mixed>
     */
    public static function rollback(Actor $actor, string $slug, int $revisionId): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $revisionId): array {
            $before = self::requireDoc($slug);
            $target = Revisions::stateAt($slug, $revisionId);
            try {
                $target = SchemaValidator::document($target);
            } catch (ValidationException $e) {
                throw new ValidationException(["Revision {$revisionId} no longer fits the current theme schema: " . $e->getMessage()]);
            }
            return self::commit($pdo, $actor, $slug, "Откат к ревизии #{$revisionId}", $before, $target, false);
        });
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public static function setMenu(Actor $actor, string $name, array $items): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $name, $items): array {
            if (preg_match('/^[a-z0-9_]{1,30}$/', $name) !== 1) {
                throw new ValidationException(['Menu name must match [a-z0-9_]{1,30}']);
            }
            $errors = [];
            $clean = [];
            if (!array_is_list($items) || count($items) > 30) {
                $errors[] = 'items must be a list of at most 30 entries';
            } else {
                foreach ($items as $i => $item) {
                    $title = is_array($item) ? ($item['title'] ?? null) : null;
                    $url = is_array($item) ? ($item['url'] ?? null) : null;
                    if (!is_string($title) || $title === '' || mb_strlen($title) > 60) {
                        $errors[] = "items[{$i}].title must be 1-60 characters";
                    } elseif (!is_string($url) || preg_match('~^(https?://[^\s<>"\'\\\\]+|/(?![/\\\\])[^\s<>"\'\\\\]*|#[^\s<>"\'\\\\]*)$~u', $url) !== 1) {
                        $errors[] = "items[{$i}].url must be http(s), a relative path or #anchor";
                    } else {
                        $clean[] = ['title' => $title, 'url' => $url];
                    }
                }
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            $key = '@menu/' . $name;
            $stmt = $pdo->prepare('SELECT items_json FROM menus WHERE name = ?');
            $stmt->execute([$name]);
            $current = $stmt->fetchColumn();
            $before = $current === false ? [] : ['items' => json_decode((string)$current, true, 512, JSON_THROW_ON_ERROR)];
            $after = ['items' => $clean];

            $revisionId = Revisions::record($pdo, $key, $actor, "Меню {$name}", $before, $after);
            if ($revisionId !== null) {
                $pdo->prepare('INSERT OR REPLACE INTO menus (name, items_json) VALUES (?, ?)')
                    ->execute([$name, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            return ['slug' => $key, 'revision_id' => $revisionId, 'changed' => $revisionId !== null, 'tags' => ['menu:' . $name], 'ping' => false];
        });
    }

    /**
     * Site name and description (administrators only). Versioned as the pseudo page "@settings".
     *
     * @param array<string,mixed> $values only "site_name" and "site_description" are accepted
     * @return array<string,mixed>
     */
    public static function setSettings(Actor $actor, array $values): array
    {
        Security::requirePermission($actor, 'system.manage');
        return self::run($actor, static function (PDO $pdo) use ($actor, $values): array {
            $limits = ['site_name' => [1, 100], 'site_description' => [0, 300]];
            $errors = [];
            $clean = [];
            foreach ($values as $key => $value) {
                if (!isset($limits[$key])) {
                    $errors[] = "Unknown setting '{$key}'";
                } elseif (!is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    $errors[] = "{$key} must be plain text on one line";
                } else {
                    $value = trim($value);
                    $length = mb_strlen($value);
                    if ($length < $limits[$key][0] || $length > $limits[$key][1]) {
                        $errors[] = "{$key} must be {$limits[$key][0]}-{$limits[$key][1]} characters";
                    }
                    $clean[$key] = $value;
                }
            }
            if ($clean === [] && $errors === []) {
                $errors[] = 'No settings to update';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            $before = [];
            foreach (array_keys($limits) as $key) {
                $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
                $stmt->execute([$key]);
                $before[$key] = (string)($stmt->fetchColumn() ?: '');
            }
            $after = array_replace($before, $clean);
            $revisionId = Revisions::record($pdo, '@settings', $actor, 'Настройки сайта: ' . implode(', ', array_keys($clean)), $before, $after);
            if ($revisionId !== null) {
                $set = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
                foreach ($after as $key => $value) {
                    $set->execute([$key, $value]);
                }
            }
            return ['slug' => '@settings', 'revision_id' => $revisionId, 'changed' => $revisionId !== null, 'tags' => ['settings'], 'ping' => false];
        });
    }

    /**
     * Role check, freeze check, one IMMEDIATE transaction, then side effects after commit.
     *
     * @param callable(PDO):array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private static function run(Actor $actor, callable $operation): array
    {
        Security::requirePermission($actor, 'content.write');
        if (CircuitBreaker::isFrozen()) {
            $info = CircuitBreaker::frozenInfo();
            throw new FrozenException('Writes are frozen since ' . ($info['since'] ?? '?') . ': ' . ($info['reason'] ?? 'circuit breaker') . '. An administrator must run bin/unfreeze.php.');
        }

        try {
            $result = Database::write($operation);
        } catch (CircuitBreakerException $e) {
            CircuitBreaker::trip($actor, $e->getMessage());
            throw $e;
        }

        if ($result['changed']) {
            Cache::invalidate($result['tags']);
            EventBus::trigger('content.changed', ['slug' => $result['slug'], 'revision_id' => $result['revision_id'], 'actor' => $actor->name]);
            if ($result['ping']) {
                Seo::queuePing($result['slug']);
            }
        }
        unset($result['tags'], $result['ping']);
        return $result;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    private static function commit(PDO $pdo, Actor $actor, string $slug, string $description, array $before, array $after, bool $isNew): array
    {
        $session = CircuitBreaker::ensureSession($pdo, $actor);
        $isNew ? CircuitBreaker::guardCreate($pdo, $session) : CircuitBreaker::guardModify($pdo, $session, $slug);

        $revisionId = Revisions::record($pdo, $slug, $actor, $description, $before, $after);
        if ($revisionId === null) {
            return ['slug' => $slug, 'revision_id' => null, 'changed' => false, 'tags' => [], 'ping' => false];
        }
        Pages::save($pdo, $slug, $after, $isNew);

        $tags = ['page:' . $slug];
        return [
            'slug' => $slug,
            'revision_id' => $revisionId,
            'changed' => true,
            'tags' => $tags,
            'ping' => (int)$after['is_published'] === 1 && (int)$after['is_noindex'] === 0,
        ];
    }

    /** @return array<string,mixed> */
    private static function requireDoc(string $slug): array
    {
        $doc = Pages::doc($slug);
        if ($doc === null) {
            throw new NotFoundException("Page '{$slug}' not found");
        }
        return $doc;
    }

    /** @param array<string,mixed> $doc */
    private static function blockIndex(array $doc, string $blockId): int
    {
        foreach ($doc['blocks'] as $i => $block) {
            if ($block['id'] === $blockId) {
                return $i;
            }
        }
        throw new NotFoundException("Block '{$blockId}' not found");
    }

    /**
     * Sets a value by a path of existing containers. Only the last key may be new (the schema decides
     * whether it is allowed), list indexes must already exist.
     *
     * @param array<string|int,mixed> $data
     * @param list<string> $tokens
     */
    private static function setPath(array &$data, array $tokens, mixed $value): void
    {
        $node = &$data;
        $last = array_pop($tokens);
        foreach ($tokens as $token) {
            $key = is_array($node) && array_is_list($node) && ctype_digit($token) ? (int)$token : $token;
            if (!is_array($node) || !array_key_exists($key, $node) || !is_array($node[$key])) {
                throw new InvalidArgumentException("Path segment '{$token}' does not exist");
            }
            $node = &$node[$key];
        }
        if (is_array($node) && array_is_list($node) && $node !== []) {
            if (!ctype_digit($last) || !array_key_exists((int)$last, $node)) {
                throw new InvalidArgumentException("List index '{$last}' does not exist");
            }
            $last = (int)$last;
        }
        $node[$last] = $value;
    }
}
