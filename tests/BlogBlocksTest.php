<?php
declare(strict_types=1);

use AgeCMS\{Cache, Database, Pages, Queue, Renderer, Revisions, SchemaValidator, Seo, ValidationException};

final class BlogBlocksTest extends TestCase
{
    /** @param array<string,mixed> $fields */
    private function post(string $slug, array $fields = []): void
    {
        Queue::submit($this->admin(), 'create_page', ['slug' => $slug, 'fields' => $fields + ['title' => 'Пост ' . $slug, 'parent_slug' => 'blog', 'blocks' => [
            ['id' => 'text', 'type' => 'article', 'data' => ['body' => '<p>Текст ' . $slug . '</p>']],
        ]]]);
    }

    private function blog(): void
    {
        Queue::submit($this->admin(), 'create_page', ['slug' => 'blog', 'fields' => ['title' => 'Блог', 'blocks' => [
            ['id' => 'list', 'type' => 'child_pages', 'data' => ['heading' => 'Записи']],
        ]]]);
    }

    public function testPublishedAtIsOptionalAndValidated(): void
    {
        $this->freshSite();
        $this->createPage('old');
        $this->assertSame(false, array_key_exists('published_at', Pages::doc('old')), 'a page without the date has no such key, as before');

        $doc = SchemaValidator::document(['title' => 't', 'published_at' => '2019-05-04T10:20:30']);
        $this->assertSame('2019-05-04T10:20:30', $doc['published_at']);
        $this->assertSame(['title', 'parent_slug', 'meta_title', 'meta_description', 'canonical_url', 'og_image', 'is_noindex', 'is_published', 'sort_order', 'published_at', 'schema_type', 'theme', 'blocks'], array_keys($doc));
        $this->assertSame(false, array_key_exists('published_at', SchemaValidator::document(['title' => 't', 'published_at' => ''])));
        foreach (['2019-02-30', '2019-5-4', '04.05.2019', '2019-05-04 10:20:30', '2019-05-04T25:00:00', '2019-05-04T10:20', '2019-05-04Z', 20190504] as $bad) {
            $this->assertThrows(ValidationException::class, fn() => SchemaValidator::document(['title' => 't', 'published_at' => $bad]), 'published_at');
        }

        Queue::submit($this->admin(), 'update_page', ['slug' => 'old', 'fields' => ['published_at' => '2020-01-02']]);
        $this->assertSame('2020-01-02', Pages::doc('old')['published_at']);
        $this->assertSame('2020-01-02', Database::value("SELECT published_at FROM pages WHERE slug = 'old'"));
        $set = (int)Revisions::history('old')[0]['id'];
        Queue::submit($this->admin(), 'update_page', ['slug' => 'old', 'fields' => ['meta_title' => 'x']]);
        $this->assertSame('2020-01-02', Pages::doc('old')['published_at'], 'another edit keeps the date');
        Queue::submit($this->admin(), 'update_page', ['slug' => 'old', 'fields' => ['published_at' => '']]);
        $this->assertSame(false, array_key_exists('published_at', Pages::doc('old')), 'an empty value clears it');
        Queue::submit($this->admin(), 'rollback', ['slug' => 'old', 'revision_id' => $set]);
        $this->assertSame('2020-01-02', Pages::doc('old')['published_at'], 'the ledger replays the date');

        $jsonLd = json_encode(Seo::jsonLd('old', Pages::doc('old'), Seo::settings()));
        $this->assertContains('"datePublished":"2020-01-02"', $jsonLd);
    }

    public function testRssFeedListsDatedPublishedPagesNewestFirst(): void
    {
        $this->freshSite();
        $this->blog();
        $this->post('blog/old', ['published_at' => '2020-01-02', 'title' => 'Старая & важная', 'meta_description' => 'Про <старое>']);
        $this->post('blog/new', ['published_at' => '2021-03-04T10:20:30', 'title' => 'Новая']);
        $this->post('blog/hidden', ['published_at' => '2022-01-01', 'title' => 'Скрытая', 'is_published' => false]);
        $this->post('blog/noindex', ['published_at' => '2022-01-01', 'title' => 'Закрытая', 'is_noindex' => true]);
        $this->createPage('plain');

        $rss = Seo::rss();
        $xml = simplexml_load_string($rss);
        $this->assertSame(true, $xml !== false, 'the feed is well formed XML');
        $titles = [];
        foreach ($xml->channel->item as $item) {
            $titles[] = (string)$item->title;
        }
        $this->assertSame(['Новая', 'Старая & важная'], $titles, 'dated, published and indexable pages only, newest first');
        $this->assertContains('<pubDate>Thu, 04 Mar 2021 10:20:30 +0000</pubDate>', $rss);
        $this->assertContains('<description>Про &lt;старое&gt;</description>', $rss);
    }

    public function testArticleRendersCleanHtmlAndEditsInPlace(): void
    {
        $this->freshSite();
        Queue::submit($this->admin(), 'create_page', ['slug' => 'stat', 'fields' => ['title' => 'Статья', 'blocks' => [
            ['id' => 'a', 'type' => 'article', 'data' => ['heading' => 'Заголовок статьи', 'body' => '<h1>Большой</h1><p>Текст <a href="/x" onclick="x">ссылка</a></p>']],
        ]]]);
        $html = Renderer::renderSlug('stat', false)['html'];
        $this->assertContains('<h2>Заголовок статьи</h2>', $html);
        $this->assertContains("<div class=\"rich\"><h2>Большой</h2>\n<p>Текст <a href=\"/x\">ссылка</a></p></div>", $html);
        $this->assertNotContains('onclick', $html);
        $this->assertNotContains('data-ag-rich', $html, 'visitors get no editing marks');

        $editing = Renderer::renderSlug('stat', true, 'csrf')['html'];
        $this->assertContains('data-ag-field="body" data-ag-multi data-ag-rich', $editing);
        $this->assertContains('"type":"richtext"', $editing, 'the block form knows the field type');

        // A value stored before the white list existed is cleaned again on output.
        Database::write(static fn(PDO $pdo) => $pdo->exec("UPDATE pages SET blocks_json = '[{\"id\":\"a\",\"type\":\"article\",\"data\":{\"body\":\"<p>ok</p><script>alert(1)</script>\"}}]' WHERE slug = 'stat'"));
        $this->assertNotContains('<script>alert', Renderer::renderSlug('stat', false)['html']);
        $this->assertSame(['heading' => 'Заголовок', 'body' => 'Текст'], \AgeCMS\BlockEditor::skeleton(\AgeCMS\Theme::blocks('default')['article']['fields']), 'a new article block gets placeholder text');
        $added = Queue::submit($this->admin(), 'add_block', ['slug' => 'stat', 'type' => 'article']);
        $this->assertSame('<p>Текст</p>', Pages::doc('stat')['blocks'][1]['data']['body'], 'the placeholder becomes a paragraph');
        $this->assertTrue(is_string($added['block_id']));
    }

    public function testChildPagesListPublishedChildrenInOrder(): void
    {
        $this->freshSite();
        $this->blog();
        $this->post('blog/old', ['published_at' => '2018-01-01', 'meta_description' => 'Старая запись', 'og_image' => 'https://cdn.example/old.jpg']);
        $this->post('blog/new', ['published_at' => '2024-06-01T09:00:00']);
        $this->post('blog/pinned', ['published_at' => '2010-01-01', 'sort_order' => -1]);
        $this->post('blog/draft', ['is_published' => 0]);
        $this->post('blog/undated');
        $this->createPage('other');

        $this->assertSame(['blog/pinned', 'blog/undated', 'blog/new', 'blog/old'], array_column(Pages::children('blog'), 'slug'), 'sort_order first, then the newest; a page without a date counts by its creation time');
        $page = Renderer::renderSlug('blog', false);
        $html = $page['html'];
        $this->assertContains('<h2>Записи</h2>', $html);
        $this->assertTrue(strpos($html, 'href="/blog/pinned"') < strpos($html, 'href="/blog/new"') && strpos($html, 'href="/blog/new"') < strpos($html, 'href="/blog/old"'));
        $this->assertNotContains('/blog/draft', $html, 'drafts are not listed');
        $this->assertNotContains('href="/other"', $html);
        $this->assertContains('Старая запись', $html);
        $this->assertContains('<img src="https://cdn.example/old.jpg"', $html);
        $this->assertContains('<time datetime="2024-06-01T09:00:00">1 июня 2024</time>', $html);
        $this->assertContains('children:blog', implode(' ', $page['tags']), 'the page carries the tag of its children list');

        // Another parent given explicitly, and nothing for a bad one.
        Queue::submit($this->admin(), 'create_page', ['slug' => 'archive', 'fields' => ['title' => 'Архив', 'blocks' => [['id' => 'l', 'type' => 'child_pages', 'data' => ['parent' => '/blog/']]]]]);
        $this->assertContains('href="/blog/old"', Renderer::renderSlug('archive', false)['html']);
        Queue::submit($this->admin(), 'update_page', ['slug' => 'archive', 'fields' => ['blocks' => [['id' => 'l', 'type' => 'child_pages', 'data' => ['parent' => 'Не адрес']]]]]);
        $this->assertNotContains('class="cards"', Renderer::renderSlug('archive', false)['html']);
    }

    public function testTheCachedListIsDroppedWhenAChildChanges(): void
    {
        $this->freshSite();
        $this->blog();
        $this->post('blog/one', ['published_at' => '2020-01-01']);
        $this->assertTrue(Cache::warm('blog'));
        $this->assertTrue(is_file(Cache::fileFor('blog')));

        Queue::submit($this->admin(), 'update_page', ['slug' => 'blog/one', 'fields' => ['title' => 'Новое название']]);
        clearstatcache();
        $this->assertSame(false, is_file(Cache::fileFor('blog')), 'an edit of a child drops the listing');

        Cache::warm('blog');
        $this->post('blog/two');
        clearstatcache();
        $this->assertSame(false, is_file(Cache::fileFor('blog')), 'a new child drops it');

        Cache::warm('blog');
        Queue::submit($this->admin(), 'delete_page', ['slug' => 'blog/two']);
        clearstatcache();
        $this->assertSame(false, is_file(Cache::fileFor('blog')), 'a deleted child drops it');

        Cache::warm('blog');
        Queue::submit($this->admin(), 'rename_page', ['slug' => 'blog/one', 'new_slug' => 'blog/first']);
        clearstatcache();
        $this->assertSame(false, is_file(Cache::fileFor('blog')), 'a moved child drops it');
        Cache::warm('blog');
        $this->assertContains('Новое название', (string)file_get_contents(Cache::fileFor('blog')));
        $this->assertContains('href="/blog/first"', (string)file_get_contents(Cache::fileFor('blog')));

        $this->createPage('unrelated');
        clearstatcache();
        $this->assertSame(true, is_file(Cache::fileFor('blog')), 'other pages leave it alone');
    }
}
