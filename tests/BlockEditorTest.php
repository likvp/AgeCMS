<?php
declare(strict_types=1);

use AgeCMS\{BlockEditor, Pages, Queue, Renderer, ValidationException, NotFoundException};

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

    /** @param array<string,array<string,mixed>> $fields @param list<string> $bad */
    private function unnamed(array $fields, string $where, array &$bad): void
    {
        foreach ($fields as $name => $spec) {
            if (!is_string($spec['label'] ?? null) || preg_match('/\p{Cyrillic}/u', $spec['label']) !== 1) {
                $bad[] = "{$where}.{$name}";
            }
            if (($spec['type'] ?? '') === 'enum') {
                foreach ($spec['values'] as $v) {
                    if (preg_match('/\p{Cyrillic}/u', (string)($spec['labels'][$v] ?? '')) !== 1) {
                        $bad[] = "{$where}.{$name}={$v}";
                    }
                }
            }
            if (($spec['type'] ?? '') === 'list') {
                $this->unnamed($spec['of'], "{$where}.{$name}", $bad);
            }
        }
    }

    public function testEveryBlockAndFieldOfTheDefaultThemeHasARussianName(): void
    {
        $this->freshSite();
        $theme = json_decode((string)file_get_contents(AGECMS_ROOT . '/themes/default/theme.json'), true);
        $bad = [];
        foreach ($theme['blocks'] as $type => $schema) {
            if (preg_match('/\p{Cyrillic}/u', (string)$schema['title']) !== 1) {
                $bad[] = "{$type} (title)";
            }
            foreach ((array)$schema['fields'] as $name => $spec) {
                if (!is_string($spec['label'] ?? null) || preg_match('/\p{Cyrillic}/u', $spec['label']) !== 1) {
                    $bad[] = "{$type}.{$name} (theme.json has no Russian label)";
                }
            }
        }
        $this->assertSame([], $bad, 'theme.json names every block and field');

        // What the form editor of the admin gets: no English left, also inside lists and enums.
        $admin = BlockEditor::forAdmin('default');
        $this->assertSame(array_keys($theme['blocks']), array_keys($admin));
        foreach ($admin as $type => $spec) {
            $this->unnamed($spec['fields'], $type, $bad);
        }
        $this->assertSame([], $bad);
        $this->assertSame('Статья', $admin['article']['title']);
        $this->assertContains('форматированием', $admin['article']['hint']);
        $this->assertSame('Главный заголовок', $admin['hero']['fields']['h1']['label']);
        $this->assertSame(['normal' => 'Обычная', 'wide' => 'Широкая'], $admin['features']['fields']['items']['of']['size']['labels']);
        $this->assertSame('string', $admin['hero']['fields']['h1']['type'], 'the schema itself is untouched');

        // The "add block" window of the page editor shows the same names.
        $types = array_column(BlockEditor::types('default'), null, 'type');
        $this->assertSame('Статья', $types['article']['title']);
        $this->assertSame('Подзаголовок', array_column($types['hero']['fields'], 'label', 'name')['subtitle']);
        $this->assertSame('Текст кнопки', array_column($types['hero']['fields'], 'label', 'name')['cta_text']);
    }

    public function testAThemeWithoutNamesStillGetsReadableOnesInTheAdminEditor(): void
    {
        $this->freshSite();
        // The names that themes usually do not set come from the table of the core; the rest is the key made readable.
        $dir = AGECMS_ROOT . '/themes/zz_names_test';
        @mkdir($dir . '/blocks', 0777, true);
        file_put_contents($dir . '/theme.json', json_encode(['name' => 'zz_names_test', 'title' => 'P', 'version' => '1', 'blocks' => ['promo' => ['title' => 'Promo', 'fields' => [
            'h1' => ['type' => 'string'], 'weird_key' => ['type' => 'string'], 'side' => ['type' => 'enum', 'values' => ['left', 'odd']],
        ]]]]));
        file_put_contents($dir . '/layout.php', '<?php ');
        try {
            $fields = BlockEditor::forAdmin('zz_names_test')['promo']['fields'];
            $this->assertSame('Главный заголовок', $fields['h1']['label']);
            $this->assertSame('Weird key', $fields['weird_key']['label']);
            $this->assertSame(['left' => 'Слева', 'odd' => 'odd'], $fields['side']['labels']);
            $this->assertSame('Promo', BlockEditor::forAdmin('zz_names_test')['promo']['title']);
        } finally {
            @unlink($dir . '/theme.json');
            @unlink($dir . '/layout.php');
            @rmdir($dir . '/blocks');
            @rmdir($dir);
        }
    }

    public function testABlockMayHaveAPictureForTheAddBlockWindow(): void
    {
        $this->freshSite();
        $types = array_column(\AgeCMS\BlockEditor::types('default'), null, 'type');
        $this->assertTrue(preg_match('~^/themes/default/previews/hero\.svg\?v=\d+$~', $types['hero']['preview'] ?? '') === 1, 'the file of the theme with ?v=');
        foreach (['../../core/agent.md', '/etc/passwd.png', 'previews/../layout.php', 'previews/none.webp', 'previews/hero.php', 42] as $bad) {
            $this->assertSame(null, \AgeCMS\BlockEditor::preview('default', $bad), 'no picture for ' . var_export($bad, true));
        }
        $script = (string)file_get_contents(AGECMS_ROOT . '/assets/editor.js');
        $this->assertContains("t.preview ? h('img'", $script, 'the card shows the picture, a block without one stays as it was');
    }
}
