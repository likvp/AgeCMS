<?php
declare(strict_types=1);

use AgeCMS\{Cache, Content, Database, Renderer};

final class CacheTest extends TestCase
{
    private function warm(string $slug): void
    {
        $gen = Cache::generation();
        $page = Renderer::renderSlug($slug, false);
        Cache::store($slug, $page['html'], $page['tags'], $gen);
    }

    public function testStoreWritesFileAndTags(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $this->warm('a');
        $this->assertTrue(is_file(Cache::fileFor('a')));
        $this->assertContains('Heading a', (string)file_get_contents(Cache::fileFor('a')));
        $tags = Database::rows('SELECT tag FROM cache_tags WHERE slug = ?', ['a']);
        $this->assertTrue(in_array('page:a', array_column($tags, 'tag'), true));
        $this->assertTrue(in_array('settings', array_column($tags, 'tag'), true));
    }

    public function testEditDropsOnlyThePagesThatUsedTheTag(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $this->createPage('b');
        $this->warm('a');
        $this->warm('b');
        Content::updateBlockField($this->admin(), 'a', 'hero_1', 'h1', 'Changed');
        $this->assertSame(false, is_file(Cache::fileFor('a')));
        $this->assertTrue(is_file(Cache::fileFor('b')), 'unrelated pages stay cached');
    }

    public function testMenuChangeDropsEveryPageThatShowsIt(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $this->createPage('b');
        $this->warm('a');
        $this->warm('b');
        Content::setMenu($this->admin(), 'header', [['title' => 'Home', 'url' => '/']]);
        $this->assertSame(false, is_file(Cache::fileFor('a')));
        $this->assertSame(false, is_file(Cache::fileFor('b')));
        $this->assertContains('Home', Renderer::renderSlug('a', false)['html']);
    }

    public function testParentTitleChangeDropsChildren(): void
    {
        $this->freshSite();
        $this->createPage('services');
        $this->createPage('services/repair', ['parent_slug' => 'services', 'title' => 'Repair']);
        $this->warm('services/repair');
        Content::updatePage($this->admin(), 'services', ['title' => 'Our services']);
        $this->assertSame(false, is_file(Cache::fileFor('services/repair')), 'breadcrumbs of the child changed');
    }

    public function testStaleRenderIsNotStored(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $gen = Cache::generation();
        $stale = Renderer::renderSlug('a', false);
        Content::updateBlockField($this->admin(), 'a', 'hero_1', 'h1', 'Newer');
        $this->assertSame(false, Cache::store('a', $stale['html'], $stale['tags'], $gen), 'invalidated while rendering');
        $this->assertSame(false, is_file(Cache::fileFor('a')));
    }

    public function testRebuildQueueRestoresInvalidatedPages(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $this->warm('a');
        Content::updateBlockField($this->admin(), 'a', 'hero_1', 'h1', 'Rebuilt');
        $this->assertSame(1, Cache::processRebuildQueue());
        $this->assertContains('Rebuilt', (string)file_get_contents(Cache::fileFor('a')));
    }

    public function testClearAllRemovesNestedFiles(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $this->createPage('x/y', ['parent_slug' => '']);
        $this->warm('a');
        $this->warm('x/y');
        $this->assertSame(2, Cache::clearAll());
        $this->assertSame([], glob(AGECMS_CACHE . '/*.html'));
    }

    public function testUnpublishedPagesAreNeverCached(): void
    {
        $this->freshSite();
        $this->createPage('draft', ['is_published' => 0]);
        $this->assertSame(null, Renderer::renderSlug('draft', false));
        $this->assertContains('Heading draft', Renderer::renderSlug('draft', true)['html'], 'editors can preview drafts');
    }

    public function testSlugForPath(): void
    {
        $this->assertSame('index', Cache::slugForPath('/'));
        $this->assertSame('a/b', Cache::slugForPath('/a/b'));
        $this->assertSame('a/b', Cache::slugForPath('/a/b/'));
        foreach (['/../etc/passwd', '/a//b', '/A', '/a b', '/.htaccess', '/a/../b'] as $path) {
            $this->assertSame(null, Cache::slugForPath($path), $path);
        }
    }
}
