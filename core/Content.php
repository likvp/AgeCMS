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
    public const DELETE_DESC = 'Удаление страницы';
    public const PURGE_DESC = 'Удаление навсегда';
    /** The last revision of an address a page moved away from reads "Перенос на /new-address". */
    public const MOVED_TO = 'Перенос на ';
    public const MOVED_FROM = 'Перенос с ';

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
    /**
     * Adds a block of a type from the theme. $after is the id of the block it follows, '' puts it first, null last.
     * Without $data the block gets the placeholder text of its required fields (see BlockEditor::skeleton).
     *
     * @param array<string,mixed>|null $data
     * @return array<string,mixed> the usual result and block_id
     */
    public static function addBlock(Actor $actor, string $slug, string $type, ?string $after, ?array $data): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $type, $after, $data): array {
            $before = self::requireDoc($slug);
            $theme = $before['theme'] !== '' ? $before['theme'] : Theme::active();
            $schema = Theme::exists($theme) ? (Theme::blocks($theme)[$type] ?? null) : null;
            if ($schema === null) {
                throw new ValidationException(["Block type '{$type}' is not defined by theme '{$theme}'"]);
            }
            $id = BlockEditor::newId($before, $type);
            $block = ['id' => $id, 'type' => $type, 'data' => $data ?? BlockEditor::skeleton($schema['fields'] ?? [])];
            $next = $before;
            array_splice($next['blocks'], self::insertAt($before, $after), 0, [$block]);
            $result = self::commit($pdo, $actor, $slug, "Добавление блока {$id}", $before, SchemaValidator::document($next), false);
            return $result + ['block_id' => $id];
        });
    }

    /**
     * Moves a block behind $after ('' moves it to the top).
     *
     * @return array<string,mixed>
     */
    public static function moveBlock(Actor $actor, string $slug, string $blockId, string $after): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $blockId, $after): array {
            $before = self::requireDoc($slug);
            $index = self::blockIndex($before, $blockId);
            if ($after === $blockId) {
                throw new ValidationException(['A block cannot follow itself']);
            }
            $moved = $before;
            [$block] = array_splice($moved['blocks'], $index, 1);
            array_splice($moved['blocks'], self::insertAt($moved, $after), 0, [$block]);
            return self::commit($pdo, $actor, $slug, "Перенос блока {$blockId}", $before, SchemaValidator::document($moved), false);
        });
    }

    /**
     * Copies a block right behind itself under a new id.
     *
     * @return array<string,mixed> the usual result and block_id of the copy
     */
    public static function duplicateBlock(Actor $actor, string $slug, string $blockId): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $blockId): array {
            $before = self::requireDoc($slug);
            $index = self::blockIndex($before, $blockId);
            $copy = $before['blocks'][$index];
            $copy['id'] = BlockEditor::newId($before, $copy['type']);
            $after = $before;
            array_splice($after['blocks'], $index + 1, 0, [$copy]);
            $result = self::commit($pdo, $actor, $slug, "Копия блока {$blockId}", $before, SchemaValidator::document($after), false);
            return $result + ['block_id' => $copy['id']];
        });
    }

    /**
     * Removes a block. It stays in the history and comes back with rollback_revision.
     *
     * @return array<string,mixed>
     */
    public static function deleteBlock(Actor $actor, string $slug, string $blockId): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $blockId): array {
            $before = self::requireDoc($slug);
            $index = self::blockIndex($before, $blockId);
            $after = $before;
            array_splice($after['blocks'], $index, 1);
            return self::commit($pdo, $actor, $slug, "Удаление блока {$blockId}", $before, SchemaValidator::document($after), false);
        });
    }

    /** @param array<string,mixed> $doc */
    private static function insertAt(array $doc, ?string $after): int
    {
        if ($after === null) {
            return count($doc['blocks']);
        }
        return $after === '' ? 0 : self::blockIndex($doc, $after) + 1;
    }

    public static function createPage(Actor $actor, string $slug, array $fields): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $fields): array {
            if (!Pages::slugValid($slug)) {
                throw new ValidationException(["'{$slug}' is not a valid slug"]);
            }
            if (Pages::row($slug) !== null) {
                throw new ValidationException(["Page '{$slug}' already exists"]);
            }
            if (($owner = ModuleRoutes::claimedBy($slug)) !== null) {
                throw new ValidationException(["The address '/{$slug}' belongs to the module {$owner}"]);
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
            $before = Pages::doc($slug) ?? [];
            if ($before === [] && Database::value('SELECT action_desc FROM revisions WHERE page_slug = ? ORDER BY id DESC LIMIT 1', [$slug]) === self::PURGE_DESC) {
                throw new ValidationException(["Page '{$slug}' was deleted for good and cannot be brought back"]);
            }
            if ($before === [] && str_starts_with((string)Database::value('SELECT action_desc FROM revisions WHERE page_slug = ? ORDER BY id DESC LIMIT 1', [$slug]), self::MOVED_TO)) {
                throw new ValidationException(["Page '{$slug}' moved to another address, open the page at its new address"]);
            }
            $target = Revisions::stateAt($slug, $revisionId);
            if ($target === []) {
                throw new ValidationException(["Revision {$revisionId} is the deletion of the page, pick an earlier one or use restore_page"]);
            }
            try {
                $target = SchemaValidator::document(self::withoutMissingParent($target));
            } catch (ValidationException $e) {
                throw new ValidationException(["Revision {$revisionId} no longer fits the current theme schema: " . $e->getMessage()]);
            }
            $isNew = $before === [];
            return self::commit($pdo, $actor, $slug, $isNew ? "Восстановление страницы из ревизии #{$revisionId}" : "Откат к ревизии #{$revisionId}", $before, $target, $isNew);
        });
    }

    /**
     * Moves a page to another address and leaves a permanent redirect (301) at the old one. The page keeps its
     * content and settings, child pages follow it as their parent, menu entries are updated. History stays at the
     * old address up to the move and continues at the new one.
     *
     * @return array<string,mixed>
     */
    public static function renamePage(Actor $actor, string $slug, string $newSlug): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug, $newSlug): array {
            if ($slug === 'index') {
                throw new ValidationException(['The home page keeps its address']);
            }
            if ($newSlug === $slug) {
                throw new ValidationException(['The new address is the same as the current one']);
            }
            if (!Pages::slugValid($newSlug)) {
                throw new ValidationException(["'{$newSlug}' is not a valid slug"]);
            }
            $before = self::requireDoc($slug);
            if (Pages::row($newSlug) !== null) {
                throw new ValidationException(["Page '{$newSlug}' already exists"]);
            }
            if (($owner = ModuleRoutes::claimedBy($newSlug)) !== null) {
                throw new ValidationException(["The address '/{$newSlug}' belongs to the module {$owner}"]);
            }
            // An address that belongs to a deleted page stays reserved so that it can still be restored.
            $lastAtTarget = Database::value('SELECT action_desc FROM revisions WHERE page_slug = ? ORDER BY id DESC LIMIT 1', [$newSlug]);
            if ($lastAtTarget !== null && !str_starts_with((string)$lastAtTarget, self::MOVED_TO)) {
                throw new ValidationException(["The address '{$newSlug}' belongs to a deleted page, pick another"]);
            }
            $session = CircuitBreaker::ensureSession($pdo, $actor);
            CircuitBreaker::guardModify($pdo, $session, $slug);

            $created = (string)Database::value('SELECT created_at FROM pages WHERE slug = ?', [$slug]);
            $tags = ['page:' . $slug, 'page:' . $newSlug];

            $revisionId = Revisions::record($pdo, $newSlug, $actor, self::MOVED_FROM . '/' . $slug, [], $before);
            Pages::save($pdo, $newSlug, $before, true);
            $pdo->prepare('UPDATE pages SET created_at = ? WHERE slug = ?')->execute([$created, $newSlug]);

            Revisions::record($pdo, $slug, $actor, self::MOVED_TO . '/' . $newSlug, $before, []);
            Pages::delete($pdo, $slug);

            // Redirects: the old address points to the new one, earlier redirects to the old one follow along.
            $now = ag_now();
            $pdo->prepare('UPDATE redirects SET to_slug = ? WHERE to_slug = ?')->execute([$newSlug, $slug]);
            $pdo->prepare('INSERT OR REPLACE INTO redirects (from_slug, to_slug, created_at) VALUES (?, ?, ?)')->execute([$slug, $newSlug, $now]);

            // Children keep their own addresses and now point at the moved parent.
            $kids = $pdo->prepare('SELECT slug FROM pages WHERE parent_slug = ?');
            $kids->execute([$slug]);
            foreach ($kids->fetchAll(PDO::FETCH_COLUMN) as $child) {
                $childBefore = self::requireDoc((string)$child);
                $childAfter = $childBefore;
                $childAfter['parent_slug'] = $newSlug;
                if (Revisions::record($pdo, (string)$child, $actor, "Родитель перенесён: /{$slug} на /{$newSlug}", $childBefore, $childAfter) !== null) {
                    Pages::save($pdo, (string)$child, $childAfter, false);
                    $tags[] = 'page:' . $child;
                }
            }

            // Menu entries that pointed at the old address.
            $from = '/' . $slug;
            foreach ($pdo->query('SELECT name, items_json FROM menus')->fetchAll(PDO::FETCH_ASSOC) as $menu) {
                $items = json_decode((string)$menu['items_json'], true, 512, JSON_THROW_ON_ERROR);
                $changed = false;
                foreach ($items as &$item) {
                    $url = (string)($item['url'] ?? '');
                    if ($url === $from || str_starts_with($url, $from . '#') || str_starts_with($url, $from . '?')) {
                        $item['url'] = '/' . $newSlug . substr($url, strlen($from));
                        $changed = true;
                    }
                }
                unset($item);
                if ($changed) {
                    Revisions::record($pdo, '@menu/' . $menu['name'], $actor, "Меню {$menu['name']}: адрес /{$slug} заменён на /{$newSlug}", ['items' => json_decode((string)$menu['items_json'], true)], ['items' => $items]);
                    $pdo->prepare('UPDATE menus SET items_json = ? WHERE name = ?')->execute([json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $menu['name']]);
                    $tags[] = 'menu:' . $menu['name'];
                }
            }

            return [
                'slug' => $newSlug, 'old_slug' => $slug, 'revision_id' => $revisionId, 'changed' => true, 'tags' => $tags,
                'ping' => (int)$before['is_published'] === 1 && (int)$before['is_noindex'] === 0,
            ];
        });
    }

    /**
     * Deletes a page. The ledger keeps every revision, so restorePage() can bring it back.
     * The home page and pages that still have children cannot be deleted.
     *
     * @return array<string,mixed>
     */
    public static function deletePage(Actor $actor, string $slug): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug): array {
            if ($slug === 'index') {
                throw new ValidationException(['The home page cannot be deleted']);
            }
            $before = self::requireDoc($slug);
            $stmt = $pdo->prepare('SELECT slug FROM pages WHERE parent_slug = ? ORDER BY slug LIMIT 3');
            $stmt->execute([$slug]);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($children !== []) {
                throw new ValidationException(["Page '{$slug}' has child pages (" . implode(', ', $children) . '): delete or move them first']);
            }
            $session = CircuitBreaker::ensureSession($pdo, $actor);
            CircuitBreaker::guardModify($pdo, $session, $slug);

            $revisionId = Revisions::record($pdo, $slug, $actor, self::DELETE_DESC, $before, []);
            Pages::delete($pdo, $slug);
            return ['slug' => $slug, 'revision_id' => $revisionId, 'changed' => true, 'tags' => ['page:' . $slug], 'ping' => false];
        });
    }

    /**
     * Brings a deleted page back in the state it had before the deletion.
     *
     * @return array<string,mixed>
     */
    public static function restorePage(Actor $actor, string $slug): array
    {
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug): array {
            if (Pages::row($slug) !== null) {
                throw new ValidationException(["Page '{$slug}' exists, nothing to restore"]);
            }
            $last = Database::value('SELECT MAX(id) FROM revisions WHERE page_slug = ?', [$slug]);
            if ($last === null || Database::value('SELECT action_desc FROM revisions WHERE id = ?', [$last]) !== self::DELETE_DESC) {
                throw new NotFoundException("Page '{$slug}' was not deleted");
            }
            $previous = Database::value('SELECT MAX(id) FROM revisions WHERE page_slug = ? AND id < ?', [$slug, $last]);
            $target = Revisions::stateAt($slug, (int)$previous);
            try {
                $target = SchemaValidator::document(self::withoutMissingParent($target));
            } catch (ValidationException $e) {
                throw new ValidationException(["The deleted page no longer fits the current theme schema: " . $e->getMessage()]);
            }
            return self::commit($pdo, $actor, $slug, 'Восстановление страницы', [], $target, true);
        });
    }

    /**
     * Removes a deleted page from the trash for good. The revisions stay: the ledger is append-only and
     * its hash chain must keep verifying, but the page can no longer be restored from the trash.
     *
     * @return array<string,mixed>
     */
    public static function purgePage(Actor $actor, string $slug): array
    {
        Security::requirePermission($actor, 'system.manage');
        return self::run($actor, static function (PDO $pdo) use ($actor, $slug): array {
            if (Pages::row($slug) !== null) {
                throw new ValidationException(["Page '{$slug}' is not in the trash"]);
            }
            $last = Database::value('SELECT MAX(id) FROM revisions WHERE page_slug = ?', [$slug]);
            if ($last === null || Database::value('SELECT action_desc FROM revisions WHERE id = ?', [$last]) !== self::DELETE_DESC) {
                throw new NotFoundException("Page '{$slug}' is not in the trash");
            }
            $revisionId = Revisions::mark($pdo, $slug, $actor, self::PURGE_DESC);
            return ['slug' => $slug, 'revision_id' => $revisionId, 'changed' => true, 'tags' => [], 'ping' => false];
        });
    }

    /**
     * A restored page must not point at a parent that has been deleted meanwhile.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function withoutMissingParent(array $doc): array
    {
        if (($doc['parent_slug'] ?? '') !== '' && Pages::row((string)$doc['parent_slug']) === null) {
            $doc['parent_slug'] = '';
        }
        return $doc;
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
     * Settings of the site (administrators only): the core's keys and the fields the active theme declared, see SiteSettings.
     * Versioned as the pseudo page "@settings".
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function setSettings(Actor $actor, array $values): array
    {
        Security::requirePermission($actor, 'system.manage');
        return self::run($actor, static function (PDO $pdo) use ($actor, $values): array {
            [$clean, $errors] = SiteSettings::clean($values, Theme::active());
            if ($clean === [] && $errors === []) {
                $errors[] = 'No settings to update';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            // A setting that was never saved is not part of the recorded state.
            $before = [];
            $known = array_keys(SiteSettings::LIMITS);
            foreach ($pdo->query("SELECT key, value FROM settings WHERE key LIKE 'theme.%' OR key IN ('" . implode("', '", $known) . "') ORDER BY key")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $before[(string)$row['key']] = (string)$row['value'];
            }
            $after = array_replace($before, $clean);
            $revisionId = Revisions::record($pdo, '@settings', $actor, 'Настройки сайта: ' . implode(', ', array_keys($clean)), $before, $after);
            if ($revisionId !== null) {
                $set = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
                foreach ($clean as $key => $value) {
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

        Backup::beforeAgentEdit($actor);
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
