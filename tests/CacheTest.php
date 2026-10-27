<?php
declare(strict_types=1);

use AgeCMS\{Cache, Content, Database, Renderer, Theme};

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

    public function testThemeCachePagesDropEveryPageWhenTheSharedPageChanges(): void
    {
        $this->freshSite();
        $name = 'zz_cache_test';
        $dir = AGECMS_ROOT . '/themes/' . $name;
        TestEnv::remove($dir);
        mkdir($dir, 0755, true);
        copy(AGECMS_ROOT . '/themes/default/layout.php', $dir . '/layout.php');
        $manifest = json_decode((string)file_get_contents(AGECMS_ROOT . '/themes/default/theme.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['name'] = $name;
        $manifest['cache_pages'] = ['contacts', 'contacts', 'Bad Slug', 'admin/x', 7];
        file_put_contents($dir . '/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        mkdir($dir . '/blocks');
        copy(AGECMS_ROOT . '/themes/default/blocks/hero.php', $dir . '/blocks/hero.php');
        try {
            $this->assertSame(['contacts'], Theme::cachePages($name), 'only valid unique slugs');
            $this->assertSame([], Theme::cachePages('default'), 'the field is optional');

            $this->createPage('contacts');
            $this->createPage('a', ['theme' => $name]);
            $this->createPage('plain');
            $this->warm('a');
            $this->warm('plain');
            $this->assertTrue(in_array('page:contacts', array_column(Database::rows('SELECT tag FROM cache_tags WHERE slug = ?', ['a']), 'tag'), true));

            Content::updateBlockField($this->admin(), 'contacts', 'hero_1', 'h1', 'New phone');
            $this->assertSame(false, is_file(Cache::fileFor('a')), 'the page of the theme is rebuilt after the shared page changed');
            $this->assertTrue(is_file(Cache::fileFor('plain')), 'pages of other themes stay cached');
        } finally {
            TestEnv::remove($dir);
        }
    }
}
