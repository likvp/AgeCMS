<?php
declare(strict_types=1);

use AgeCMS\{Actor, Cache, Database, ForbiddenException, NotFoundException, Pages, Queue, Renderer, Search, ValidationException};

final class ContentTest extends TestCase
{
    public function testCreateAndDuplicate(): void
    {
        $this->freshSite();
        $r = $this->createPage('services/repair');
        $this->assertSame(true, $r['changed']);
        $this->assertSame('Page services/repair', Pages::doc('services/repair')['title']);
        $this->assertThrows(ValidationException::class, fn() => $this->createPage('services/repair'), 'already exists');
    }

    public function testSlugRules(): void
    {
        $this->freshSite();
        foreach (['admin', 'admin/x', 'core', 'data/x', 'Upper', 'a b', '../x', 'a//b', '-a', 'a-', '_/x', ''] as $slug) {
            $this->assertThrows(ValidationException::class, fn() => $this->createPage($slug), 'slug');
        }
        $this->createPage('about-us');
        $this->createPage('a/b/c');
    }

    public function testFieldPathsIncludingLists(): void
    {
        $this->freshSite();
        $this->createPage('f', ['blocks' => [['id' => 'feat', 'type' => 'features', 'data' => ['items' => [['title' => 'One'], ['title' => 'Two']]]]]]);
        Queue::submit($this->admin(), 'update_block_field', ['slug' => 'f', 'block_id' => 'feat', 'field_path' => 'items.1.title', 'value' => 'Second']);
        $this->assertSame('Second', Pages::doc('f')['blocks'][0]['data']['items'][1]['title']);
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'update_block_field', ['slug' => 'f', 'block_id' => 'feat', 'field_path' => 'items.5.title', 'value' => 'x']), 'does not exist');
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'update_block_field', ['slug' => 'f', 'block_id' => 'feat', 'field_path' => 'nope.0.title', 'value' => 'x']), 'does not exist');
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'update_block_field', ['slug' => 'f', 'block_id' => 'feat', 'field_path' => 'items.0.title', 'value' => '<script>' . str_repeat('x', 120)]), 'longer than 100');
    }

    public function testBlockDataMerge(): void
    {
        $this->freshSite();
        $this->createPage('m', ['blocks' => [['id' => 'hero_1', 'type' => 'hero', 'data' => ['h1' => 'A', 'subtitle' => 'S']]]]);
        Queue::submit($this->admin(), 'update_block_data', ['slug' => 'm', 'block_id' => 'hero_1', 'payload' => ['h1' => 'B', 'cta_text' => 'Go', 'cta_url' => '/go']]);
        $this->assertSame(['h1' => 'B', 'subtitle' => 'S', 'cta_text' => 'Go', 'cta_url' => '/go'], Pages::doc('m')['blocks'][0]['data']);
    }

    public function testRollbackRestoresAndIsItselfARevision(): void
    {
        $this->freshSite();
        $created = $this->createPage('r');
        Queue::submit($this->admin(), 'update_block_field', ['slug' => 'r', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'Changed']);
        Queue::submit($this->admin(), 'update_page', ['slug' => 'r', 'fields' => ['title' => 'New title']]);
        $rb = Queue::submit($this->admin(), 'rollback', ['slug' => 'r', 'revision_id' => $created['revision_id']]);
        $doc = Pages::doc('r');
        $this->assertSame('Heading r', $doc['blocks'][0]['data']['h1']);
        $this->assertSame('Page r', $doc['title']);
        $this->assertSame(4, $rb['revision_id']);
        $this->assertSame(4, (int)Database::value('SELECT COUNT(*) FROM revisions'), 'history was not rewritten');
        $this->assertContains('Откат', (string)Database::value('SELECT action_desc FROM revisions WHERE id = 4'));
        $this->assertThrows(NotFoundException::class, fn() => Queue::submit($this->admin(), 'rollback', ['slug' => 'r', 'revision_id' => 999]));
    }

    public function testRolesAreEnforcedInsideTheQueue(): void
    {
        $this->freshSite();
        $this->createPage('x');
        $viewer = Actor::agent('v', 'viewer', 'v1');
        $this->assertThrows(ForbiddenException::class, fn() => Queue::submit($viewer, 'update_page', ['slug' => 'x', 'fields' => ['title' => 'Hacked']]));
        $this->assertSame('Page x', Pages::doc('x')['title']);
        $editor = Actor::agent('e', 'editor', 'e1');
        Queue::submit($editor, 'update_page', ['slug' => 'x', 'fields' => ['title' => 'Edited']]);
        $this->assertSame('Edited', Pages::doc('x')['title']);
    }

    public function testUnpublishedPagesAreHiddenAndNotSearchable(): void
    {
        $this->freshSite();
        $this->createPage('secret', ['blocks' => [['id' => 'text_1', 'type' => 'text', 'data' => ['body' => 'zanzibar treasure']]]]);
        $this->assertSame(1, count(Search::query('zanzibar')));
        Queue::submit($this->admin(), 'update_page', ['slug' => 'secret', 'fields' => ['is_published' => 0]]);
        $this->assertSame(null, Renderer::renderSlug('secret', false));
        $this->assertTrue(Renderer::renderSlug('secret', true) !== null, 'editors still see it');
        $this->assertSame(0, count(Search::query('zanzibar')));
    }

    public function testSearchTreatsInputAsPlainWords(): void
    {
        $this->freshSite();
        $this->createPage('s', ['blocks' => [['id' => 'text_1', 'type' => 'text', 'data' => ['body' => 'Ремонт квартир недорого']]]]);
        $this->assertSame('s', Search::query('ремонт')[0]['slug']);
        $this->assertSame('s', Search::query('квар')[0]['slug'], 'prefix match');
        foreach (['"', '* OR 1', 'NEAR(a b)', "x' --", 'a:b'] as $hostile) {
            Search::query($hostile);
        }
        $this->assertTrue(true);
    }

    public function testMenuIsValidatedAndVersioned(): void
    {
        $this->freshSite();
        Queue::submit($this->admin(), 'set_menu', ['name' => 'header', 'items' => [['title' => 'Home', 'url' => '/']]]);
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_menu', ['name' => 'header', 'items' => [['title' => 'X', 'url' => 'javascript:alert(1)']]]));
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_menu', ['name' => 'Bad Name', 'items' => []]));
        $this->assertSame('@menu/header', Database::value("SELECT page_slug FROM revisions WHERE page_slug LIKE '@menu/%' LIMIT 1"));
    }

    public function testChangesInvalidateOnlyAffectedCacheFiles(): void
    {
        $this->freshSite();
        Queue::submit($this->admin(), 'set_menu', ['name' => 'header', 'items' => [['title' => 'Home', 'url' => '/']]]);
        foreach (['a', 'b', 'c/d'] as $slug) {
            $this->createPage($slug);
            $gen = Cache::generation();
            $page = Renderer::renderSlug($slug, false);
            $this->assertTrue(Cache::store($slug, $page['html'], $page['tags'], $gen));
        }
        $this->createPage('c', ['title' => 'Parent']);
        foreach (['a', 'b', 'c/d'] as $slug) {
            $gen = Cache::generation();
            $page = Renderer::renderSlug($slug, false);
            Cache::store($slug, $page['html'], $page['tags'], $gen);
        }
        Queue::submit($this->admin(), 'update_block_field', ['slug' => 'a', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'A2']);
        $this->assertSame(false, is_file(Cache::fileFor('a')), 'edited page dropped');
        $this->assertTrue(is_file(Cache::fileFor('b')), 'unrelated page kept');
        $this->assertTrue(is_file(Cache::fileFor('c/d')), 'unrelated child kept');

        Queue::submit($this->admin(), 'update_page', ['slug' => 'c', 'fields' => ['title' => 'Parent renamed']]);
        $this->assertSame(false, is_file(Cache::fileFor('c/d')), 'breadcrumb of the child changed, so it was dropped');
        $this->assertTrue(is_file(Cache::fileFor('b')));

        Queue::submit($this->admin(), 'set_menu', ['name' => 'header', 'items' => [['title' => 'Home', 'url' => '/'], ['title' => 'New', 'url' => '/new']]]);
        $this->assertSame(false, is_file(Cache::fileFor('b')), 'a menu change drops every page that used the menu');
    }

    public function testSettingsAreAdminOnlyValidatedAndVersioned(): void
    {
        $this->freshSite();
        $this->createPage('a');
        \AgeCMS\Cache::store('a', '<html></html>', ['page:a', 'settings'], \AgeCMS\Cache::generation());

        $editor = Actor::agent('ed', 'editor');
        $this->assertThrows(ForbiddenException::class, fn() => Queue::submit($editor, 'set_settings', ['values' => ['site_name' => 'Hacked']]));
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => ['site_name' => '']]));
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => ['active_theme' => 'x']]), 'Unknown setting');
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'set_settings', ['values' => ['site_name' => "two\nlines"]]));

        $r = Queue::submit($this->admin(), 'set_settings', ['values' => ['site_name' => 'Новое имя', 'site_description' => 'Описание']]);
        $this->assertSame(true, $r['changed']);
        $this->assertSame('Новое имя', Database::value("SELECT value FROM settings WHERE key = 'site_name'"));
        $this->assertSame('@settings', Database::value('SELECT page_slug FROM revisions ORDER BY id DESC LIMIT 1'));
        $this->assertSame(false, is_file(\AgeCMS\Cache::fileFor('a')), 'settings tag drops cached pages');
        $this->assertSame(false, Queue::submit($this->admin(), 'set_settings', ['values' => ['site_name' => 'Новое имя']])['changed']);
        $this->assertSame('Новое имя', \AgeCMS\Seo::settings()['site_name']);
    }
}
