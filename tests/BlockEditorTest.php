<?php
declare(strict_types=1);

use AgeCMS\{Pages, Queue, Renderer, ValidationException, NotFoundException};

final class BlockEditorTest extends TestCase
{
    /** @return list<string> */
    private function order(string $slug): array
    {
        return array_column(Pages::doc($slug)['blocks'], 'id');
    }

    private function threeBlocks(string $slug): void
    {
        $this->createPage($slug, ['blocks' => [
            ['id' => 'a', 'type' => 'hero', 'data' => ['h1' => 'A']],
            ['id' => 'b', 'type' => 'hero', 'data' => ['h1' => 'B']],
            ['id' => 'c', 'type' => 'hero', 'data' => ['h1' => 'C']],
        ]]);
    }

    public function testMoveBlock(): void
    {
        $this->freshSite();
        $this->threeBlocks('p');
        Queue::submit($this->admin(), 'move_block', ['slug' => 'p', 'block_id' => 'c', 'after' => '']);
        $this->assertSame(['c', 'a', 'b'], $this->order('p'));
        Queue::submit($this->admin(), 'move_block', ['slug' => 'p', 'block_id' => 'c', 'after' => 'b']);
        $this->assertSame(['a', 'b', 'c'], $this->order('p'));
        Queue::submit($this->admin(), 'move_block', ['slug' => 'p', 'block_id' => 'a', 'after' => 'b']);
        $this->assertSame(['b', 'a', 'c'], $this->order('p'));
        $this->assertThrows(NotFoundException::class, fn() => Queue::submit($this->admin(), 'move_block', ['slug' => 'p', 'block_id' => 'zzz', 'after' => '']));
        $this->assertThrows(NotFoundException::class, fn() => Queue::submit($this->admin(), 'move_block', ['slug' => 'p', 'block_id' => 'a', 'after' => 'zzz']));
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'move_block', ['slug' => 'p', 'block_id' => 'a', 'after' => 'a']), 'follow itself');
    }

    public function testMoveToSamePlaceChangesNothing(): void
    {
        $this->freshSite();
        $this->threeBlocks('p');
        $r = Queue::submit($this->admin(), 'move_block', ['slug' => 'p', 'block_id' => 'b', 'after' => 'a']);
        $this->assertSame(false, $r['changed']);
    }

    public function testAddBlockGetsPlaceholdersAndPosition(): void
    {
        $this->freshSite();
        $this->threeBlocks('p');
        $r = Queue::submit($this->admin(), 'add_block', ['slug' => 'p', 'type' => 'faq', 'after' => 'a']);
        $id = $r['block_id'];
        $this->assertSame(['a', $id, 'b', 'c'], $this->order('p'));
        $block = Pages::doc('p')['blocks'][1];
        $this->assertSame('faq', $block['type']);
        $this->assertTrue($block['data']['items'][0]['q'] !== '', 'a sample question is there');

        $first = Queue::submit($this->admin(), 'add_block', ['slug' => 'p', 'type' => 'text', 'after' => '']);
        $last = Queue::submit($this->admin(), 'add_block', ['slug' => 'p', 'type' => 'text']);
        $ids = $this->order('p');
        $this->assertSame($first['block_id'], $ids[0]);
        $this->assertSame($last['block_id'], $ids[count($ids) - 1]);
        $this->assertTrue(preg_match('/^[a-z][a-z0-9_]{0,39}$/', $first['block_id']) === 1);
    }

    public function testAddBlockWithDataAndUnknownType(): void
    {
        $this->freshSite();
        $this->threeBlocks('p');
        $r = Queue::submit($this->admin(), 'add_block', ['slug' => 'p', 'type' => 'text', 'data' => ['body' => 'Hello']]);
        $this->assertSame('Hello', Pages::doc('p')['blocks'][3]['data']['body']);
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'add_block', ['slug' => 'p', 'type' => 'nope']), 'not defined');
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'add_block', ['slug' => 'p', 'type' => 'image']), 'image');
    }

    public function testDuplicateAndDelete(): void
    {
        $this->freshSite();
        $this->threeBlocks('p');
        $r = Queue::submit($this->admin(), 'duplicate_block', ['slug' => 'p', 'block_id' => 'b']);
        $copy = $r['block_id'];
        $this->assertSame(['a', 'b', $copy, 'c'], $this->order('p'));
        $this->assertSame('B', Pages::doc('p')['blocks'][2]['data']['h1']);

        Queue::submit($this->admin(), 'delete_block', ['slug' => 'p', 'block_id' => 'b']);
        $this->assertSame(['a', $copy, 'c'], $this->order('p'));
        $this->assertThrows(NotFoundException::class, fn() => Queue::submit($this->admin(), 'delete_block', ['slug' => 'p', 'block_id' => 'b']));
    }

    public function testUndoThroughRollback(): void
    {
        $this->freshSite();
        $this->threeBlocks('p');
        $deleted = Queue::submit($this->admin(), 'delete_block', ['slug' => 'p', 'block_id' => 'b']);
        $history = \AgeCMS\Revisions::history('p');
        $before = (int)$history[1]['id'];
        $this->assertSame((int)$deleted['revision_id'], (int)$history[0]['id']);
        Queue::submit($this->admin(), 'rollback', ['slug' => 'p', 'revision_id' => $before]);
        $this->assertSame(['a', 'b', 'c'], $this->order('p'));
    }

    public function testEditModeWrapsBlocksAndCarriesEditorData(): void
    {
        $this->freshSite();
        $this->threeBlocks('p');
        $edit = Renderer::renderSlug('p', true, 'csrf-token');
        $this->assertContains('data-ag-wrap="a"', $edit['html']);
        $this->assertContains('data-ag-wrap="c"', $edit['html']);
        $this->assertContains('id="ag-editor-data"', $edit['html']);
        $this->assertContains('"csrf":"csrf-token"', $edit['html']);
        $this->assertContains('"label":"Заголовок"', $edit['html'], 'fields carry readable names');
        $view = Renderer::renderSlug('p', false);
        $this->assertNotContains('data-ag-wrap', $view['html'], 'visitors see no editor markup');
        $this->assertNotContains('ag-editor-data', $view['html']);
    }

    public function testEditorDataCannotBreakOutOfTheScriptTag(): void
    {
        $this->freshSite();
        $this->createPage('x', ['blocks' => [['id' => 'a', 'type' => 'hero', 'data' => ['h1' => '</script><b>x</b>']]]]);
        $html = Renderer::renderSlug('x', true, 'c')['html'];
        $start = strpos($html, 'id="ag-editor-data"');
        $json = substr($html, $start, strpos($html, '</script>', $start) - $start);
        $this->assertNotContains('<b>', $json);
        $this->assertContains('\\' . 'u003C', $json);
    }
}
