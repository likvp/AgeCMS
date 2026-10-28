<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * One tool registry for both front doors: the REST endpoint used by the in-page editor (admin/api.php)
 * and the MCP endpoint used by agents (admin/mcp.php). Writes always go through the queue.
 */
final class Api
{
    /** Tool name => [permission, description, JSON schema of arguments]. */
    private static function definitions(): array
    {
        $slug = ['type' => 'string', 'description' => 'Page slug, "index" is the home page'];
        $obj = static fn(array $props, array $required): array => ['type' => 'object', 'properties' => $props, 'required' => $required, 'additionalProperties' => false];
        return [
            'get_site_structure' => ['content.read', 'Pages, settings, menus, theme block schemas, modules and the freeze state. Call this first.', $obj([], [])],
            'get_page' => ['content.read', 'Full document of one page: meta fields and blocks.', $obj(['slug' => $slug], ['slug'])],
            'search_pages' => ['search', 'Full-text search over published pages.', $obj(['q' => ['type' => 'string']], ['q'])],
            'update_block_field' => ['content.write', 'Set one text field of one block. field_path is dotted, e.g. "h1" or "items.2.title".', $obj(['slug' => $slug, 'block_id' => ['type' => 'string'], 'field_path' => ['type' => 'string'], 'value' => ['type' => 'string']], ['slug', 'block_id', 'field_path', 'value'])],
            'update_block_data' => ['content.write', 'Merge new values into a block (top-level fields of its data).', $obj(['slug' => $slug, 'block_id' => ['type' => 'string'], 'payload' => ['type' => 'object']], ['slug', 'block_id', 'payload'])],
            'create_page' => ['content.write', 'Create a page. fields: title (required), meta_title, meta_description, parent_slug, schema_type, blocks.', $obj(['slug' => $slug, 'fields' => ['type' => 'object']], ['slug', 'fields'])],
            'batch_create_pages' => ['content.write', 'Create up to 100 pages in order. Each item is {slug, ...page fields}. Stops at the first failure.', $obj(['pages' => ['type' => 'array', 'items' => ['type' => 'object']]], ['pages'])],
            'update_page' => ['content.write', 'Change meta fields and/or replace the whole blocks list of a page.', $obj(['slug' => $slug, 'fields' => ['type' => 'object']], ['slug', 'fields'])],
            'rename_page' => ['content.write', 'Move a page to another address. The old address redirects (301) to the new one, child pages and menu entries follow. Not for the home page.', $obj(['slug' => $slug, 'new_slug' => ['type' => 'string', 'description' => 'New address, lowercase latin letters, digits, - and _, parts separated by /']], ['slug', 'new_slug'])],
            'delete_page' => ['content.write', 'Delete a page (not the home page, not a page with children). It stays in history and can be restored.', $obj(['slug' => $slug], ['slug'])],
            'restore_page' => ['content.write', 'Restore a deleted page to its last state.', $obj(['slug' => $slug], ['slug'])],
            'list_deleted_pages' => ['content.read', 'Deleted pages that can be restored.', $obj([], [])],
            'set_menu' => ['content.write', 'Replace a menu ("header" or "footer"). items: [{title, url}].', $obj(['name' => ['type' => 'string'], 'items' => ['type' => 'array', 'items' => ['type' => 'object']]], ['name', 'items'])],
            'set_settings' => ['system.manage', 'Change site_name (the title of every page), site_description (default meta description), llms_text (free Markdown for language models, published in /llms.txt) and the analytics settings: yandex_metrika_id (counter number, digits), yandex_metrika_options (comma list of webvisor, clickmap, trackLinks, accurateTrackBounce, trackHash), yandex_webmaster (verification code) and verification_tags (lines name=value, meta tags for Google, Bing and others; Yandex Direct needs no code, it uses the Metrika counter). Empty string clears a setting. Administrators only.', $obj(['values' => ['type' => 'object']], ['values'])],
            'list_revisions' => ['content.read', 'Revision history of a page, newest first.', $obj(['slug' => $slug, 'limit' => ['type' => 'integer']], ['slug'])],
            'rollback_revision' => ['content.write', 'Restore a page to its state right after the given revision. Creates a new revision.', $obj(['slug' => $slug, 'revision_id' => ['type' => 'integer']], ['slug', 'revision_id'])],
            'import_image' => ['content.write', 'Download a public image by https URL, convert it to WebP and return its site path for use in og_image or url fields.', $obj(['url' => ['type' => 'string']], ['url'])],
            'clear_cache' => ['cache.clear', 'Drop all cached pages. They are rebuilt on the next visit.', $obj([], [])],
            'check_core_update' => ['system.manage', 'Ask the update source now: installed core version, the newest one, its notes, whether update_core can install it here (blockers says why not), auto-update state and the last install attempt. Administrators only.', $obj([], [])],
            'update_core' => ['system.manage', 'Install the newer core found by check_core_update: signed package only, database and old files are backed up first, a failed write puts the old files back. Pages, uploads, settings, modules and own themes are not touched. Tell the owner before you call it. Administrators only.', $obj([], [])],
            'rollback_core' => ['system.manage', 'Put back the core files saved before the last update_core (the database stays as it is). Administrators only.', $obj([], [])],
            'set_core_updates' => ['system.manage', 'Change where updates are checked (url: https address of release.json, empty switches checks off) and/or auto (true installs new signed versions by itself within a day). Administrators only.', $obj(['url' => ['type' => 'string'], 'auto' => ['type' => 'boolean']], [])],
        ];
    }

    /**
     * Tool descriptors the caller is allowed to use, in MCP format.
     *
     * @return list<array{name:string,description:string,inputSchema:array<string,mixed>}>
     */
    public static function tools(Actor $actor): array
    {
        $tools = [];
        foreach (self::definitions() as $name => [$permission, $description, $schema]) {
            if ($actor->can($permission)) {
                $tools[] = ['name' => $name, 'description' => $description, 'inputSchema' => $schema];
            }
        }
        foreach (SkillRegistry::skills() as $skill) {
            if ($actor->can($skill['permission'])) {
                $tools[] = ['name' => $skill['module'] . '__' . $skill['name'], 'description' => '[module ' . $skill['module'] . '] ' . $skill['description'], 'inputSchema' => $skill['inputSchema']];
            }
        }
        return $tools;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function call(Actor $actor, string $tool, array $args): array
    {
        if (str_contains($tool, '__')) {
            [$module, $skill] = explode('__', $tool, 2);
            return SkillRegistry::call($module, $skill, $args, $actor);
        }
        $definitions = self::definitions();
        if (!isset($definitions[$tool])) {
            throw new NotFoundException("Unknown tool '{$tool}'");
        }
        Security::requirePermission($actor, $definitions[$tool][0]);

        return match ($tool) {
            'get_site_structure' => self::structure(),
            'get_page' => self::page(self::string($args, 'slug')),
            'search_pages' => ['results' => Search::query(self::string($args, 'q'))],
            'update_block_field' => Queue::submit($actor, 'update_block_field', [
                'slug' => self::string($args, 'slug'), 'block_id' => self::string($args, 'block_id'),
                'field_path' => self::string($args, 'field_path'), 'value' => $args['value'] ?? '',
            ]),
            'update_block_data' => Queue::submit($actor, 'update_block_data', [
                'slug' => self::string($args, 'slug'), 'block_id' => self::string($args, 'block_id'), 'payload' => self::object($args, 'payload'),
            ]),
            'create_page' => Queue::submit($actor, 'create_page', self::pageArgs($args)),
            'batch_create_pages' => self::batchCreate($actor, $args),
            'update_page' => Queue::submit($actor, 'update_page', self::pageArgs($args)),
            'rename_page' => Queue::submit($actor, 'rename_page', ['slug' => self::string($args, 'slug'), 'new_slug' => self::string($args, 'new_slug')]),
            'delete_page' => Queue::submit($actor, 'delete_page', ['slug' => self::string($args, 'slug')]),
            'restore_page' => Queue::submit($actor, 'restore_page', ['slug' => self::string($args, 'slug')]),
            'list_deleted_pages' => ['pages' => Pages::deleted()],
            'set_menu' => Queue::submit($actor, 'set_menu', ['name' => self::string($args, 'name'), 'items' => self::object($args, 'items')]),
            'set_settings' => Queue::submit($actor, 'set_settings', ['values' => self::object($args, 'values')]),
            'list_revisions' => ['revisions' => Revisions::history(self::string($args, 'slug'), (int)($args['limit'] ?? 50))],
            'rollback_revision' => Queue::submit($actor, 'rollback', ['slug' => self::string($args, 'slug'), 'revision_id' => (int)($args['revision_id'] ?? 0)]),
            'import_image' => ['path' => Media::importUrl(self::string($args, 'url'))],
            'clear_cache' => ['cleared' => Cache::clearAll()],
            'check_core_update' => self::coreUpdate(true),
            'update_core' => self::updateCore($actor),
            'rollback_core' => Updater::rollback() + ['note' => 'Files are back. The database was not rolled back.'],
            'set_core_updates' => self::setCoreUpdates($args),
        };
    }

    /** @return array<string,mixed> */
    private static function structure(): array
    {
        $menus = [];
        foreach (Database::rows('SELECT name, items_json FROM menus ORDER BY name') as $row) {
            $menus[$row['name']] = json_decode((string)$row['items_json'], true);
        }
        $theme = Theme::active();
        return [
            'site_url' => Seo::siteUrl(),
            'core' => ['version' => Updates::version(), 'newer' => Updates::available()['version'] ?? null],
            'active_theme' => $theme,
            'pages' => Pages::listing(),
            'settings' => Seo::settings(),
            'menus' => $menus,
            'block_types' => Theme::blocks($theme),
            'modules' => array_map(static fn(array $m): array => ['status' => $m['status'], 'reason' => $m['reason']], SkillRegistry::modules()),
            'frozen' => CircuitBreaker::frozenInfo(),
            'token' => Auth::currentToken(),
        ];
    }

    /** @return array<string,mixed> */
    private static function coreUpdate(bool $checkNow): array
    {
        $state = $checkNow && Updates::sourceUrl() !== '' ? Updates::check() : Updates::state();
        $newer = Updates::available();
        $blockers = $newer === null ? [] : array_values(array_filter(array_merge([Updater::verifyRelease($newer)], Updater::preflight())));
        return [
            'version' => Updates::version(),
            'source' => Updates::sourceUrl(),
            'checked_at' => $state['checked_at'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $state['checked_at']) : null,
            'check_error' => $state['error'] !== '' ? $state['error'] : null,
            'latest' => $state['latest']['version'] ?? null,
            'newer' => $newer === null ? null : ['version' => $newer['version'], 'date' => $newer['date'], 'notes' => $newer['notes'], 'url' => $newer['url']],
            'can_install' => $newer !== null && $blockers === [],
            'blockers' => $blockers,
            'auto' => Updater::autoEnabled(),
            'last_attempt' => Updater::last(),
        ];
    }

    /** @return array<string,mixed> */
    private static function updateCore(Actor $actor): array
    {
        $state = Updates::check();
        if ($state['error'] !== '') {
            throw new AgeCMSException('Update check failed: ' . $state['error']);
        }
        return Updater::install(null, $actor->type . ':' . $actor->name) + ['note' => 'Installed. The next request runs the new core; call get_site_structure to see core.version.'];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function setCoreUpdates(array $args): array
    {
        if (array_key_exists('url', $args)) {
            $url = trim((string)$args['url']);
            if ($url !== '' && (strlen($url) > 500 || preg_match('~^https://[^\s"<>]+$~', $url) !== 1)) {
                throw new AgeCMSException('url must be an https address or empty');
            }
            Config::set('updates.url', $url);
        }
        if (array_key_exists('auto', $args)) {
            Config::set('updates.auto', $args['auto'] === true);
        }
        if (Updates::sourceUrl() === '') {
            Config::set('updates.auto', false);
        }
        return self::coreUpdate(array_key_exists('url', $args));
    }

    /** @return array<string,mixed> */
    private static function page(string $slug): array
    {
        $doc = Pages::doc($slug);
        if ($doc === null) {
            throw new NotFoundException("Page '{$slug}' not found");
        }
        return ['slug' => $slug, 'page' => $doc];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function batchCreate(Actor $actor, array $args): array
    {
        $pages = $args['pages'] ?? null;
        if (!is_array($pages) || !array_is_list($pages) || $pages === [] || count($pages) > 100) {
            throw new ValidationException(['pages must be a list of 1-100 items']);
        }
        $created = [];
        foreach ($pages as $i => $item) {
            if (!is_array($item) || !isset($item['slug']) || !is_string($item['slug'])) {
                throw new ValidationException(["pages[{$i}] needs a string slug"]);
            }
            $slug = $item['slug'];
            unset($item['slug']);
            try {
                Queue::submit($actor, 'create_page', ['slug' => $slug, 'fields' => $item]);
                $created[] = $slug;
            } catch (AgeCMSException $e) {
                // Report what was done so the agent can continue from here instead of starting over.
                throw new AgeCMSException("pages[{$i}] ('{$slug}') failed after " . count($created) . ' created: ' . $e->getMessage());
            }
        }
        return ['created' => $created, 'count' => count($created)];
    }

    /** @param array<string,mixed> $args */
    /**
     * slug plus fields for create_page and update_page. A page field given next to the slug instead of inside
     * `fields` (is_published, title, blocks...) is refused: silently dropping it once published a page meant to stay hidden.
     *
     * @return array{slug:string,fields:array<string,mixed>}
     */
    private static function pageArgs(array $args): array
    {
        $misplaced = array_values(array_intersect(array_keys($args), ['title', 'parent_slug', 'meta_title', 'meta_description', 'canonical_url', 'og_image', 'is_noindex', 'is_published', 'sort_order', 'schema_type', 'theme', 'blocks']));
        if ($misplaced !== []) {
            throw new ValidationException(["Put " . implode(', ', $misplaced) . " inside 'fields', next to the slug is not read: {\"slug\": \"...\", \"fields\": {\"" . $misplaced[0] . "\": ...}}"]);
        }
        return ['slug' => self::string($args, 'slug'), 'fields' => self::object($args, 'fields')];
    }

    private static function string(array $args, string $key): string
    {
        $value = $args[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ValidationException(["'{$key}' is required and must be a non-empty string"]);
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<int|string,mixed>
     */
    private static function object(array $args, string $key): array
    {
        $value = $args[$key] ?? null;
        if (!is_array($value)) {
            throw new ValidationException(["'{$key}' is required and must be an object or list"]);
        }
        return $value;
    }

    /** HTTP status for an exception raised by a tool. */
    public static function statusFor(\Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof NotFoundException => 404,
            $e instanceof ForbiddenException => 403,
            $e instanceof FrozenException => 423,
            $e instanceof CircuitBreakerException => 409,
            $e instanceof AgeCMSException => 400,
            default => 500,
        };
    }
}
