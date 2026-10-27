<?php
declare(strict_types=1);

use AgeCMS\{Actor, Content, Database, Pages, Queue, Revisions, Security};

final class RenameTest extends TestCase
{
    private function rename(string $from, string $to): array
    {
        return Queue::submit($this->admin(), 'rename_page', ['slug' => $from, 'new_slug' => $to]);
    }

    public function testAPageMovesAndLeavesARedirect(): void
    {
        $this->freshSite();
        $this->createPage('stary');
        $before = Pages::doc('stary');
        $result = $this->rename('stary', 'novy');

        $this->assertSame('novy', $result['slug']);
        $this->assertSame(null, Pages::row('stary'));
        $after = Pages::doc('novy');
        $this->assertSame($before['title'], $after['title']);
        $this->assertSame($before['blocks'], $after['blocks']);
        $this->assertSame('novy', Pages::redirectFor('stary'));
        $this->assertSame(null, Pages::redirectFor('novy'));

        $this->assertSame('Перенос с /stary', Revisions::history('novy')[0]['action_desc']);
        $this->assertSame('Перенос на /novy', Revisions::history('stary')[0]['action_desc']);
        $this->assertSame([], array_column(Pages::deleted(), 'slug'), 'a moved page is not in the trash');
        $this->assertSame(true, Revisions::verifyChain()['ok'], 'the hash chain still verifies');
        $this->assertSame($before['blocks'], Revisions::stateAt('novy', (int)Revisions::history('novy')[0]['id'])['blocks'], 'the new address can be read back from its history');
    }

    public function testChildrenMenusAndRedirectChains(): void
    {
        $this->freshSite();
        $this->createPage('uslugi');
        $this->createPage('uslugi/remont', ['parent_slug' => 'uslugi']);
        Queue::submit($this->admin(), 'set_menu', ['name' => 'header', 'items' => [['title' => 'Услуги', 'url' => '/uslugi'], ['title' => 'Ремонт', 'url' => '/uslugi/remont'], ['title' => 'Блок', 'url' => '/uslugi#prices']]]);

        $this->rename('uslugi', 'services');
        $this->assertSame('services', Pages::doc('uslugi/remont')['parent_slug'], 'the child follows its parent');
        $this->assertTrue(Pages::row('uslugi/remont') !== null, 'and keeps its own address');
        $items = json_decode((string)Database::value("SELECT items_json FROM menus WHERE name = 'header'"), true);
        $this->assertSame(['/services', '/uslugi/remont', '/services#prices'], array_column($items, 'url'));

        // a second move: the first redirect follows along instead of becoming a chain
        $this->rename('services', 'uslugi-2');
        $this->assertSame('uslugi-2', Pages::redirectFor('uslugi'));
        $this->assertSame('uslugi-2', Pages::redirectFor('services'));
        // and back to the very first address: its redirect is gone, the others point at it
        $this->rename('uslugi-2', 'uslugi');
        $this->assertSame(null, Pages::redirectFor('uslugi'));
        $this->assertSame('uslugi', Pages::redirectFor('services'));
        $this->assertSame('uslugi', Pages::redirectFor('uslugi-2'));
        $this->assertSame(true, Revisions::verifyChain()['ok']);

        // a redirect to a page that was deleted later is not followed
        Queue::submit($this->admin(), 'delete_page', ['slug' => 'uslugi/remont']);
        Queue::submit($this->admin(), 'delete_page', ['slug' => 'uslugi']);
        $this->assertSame(null, Pages::redirectFor('services'));
    }

    public function testRefusals(): void
    {
        $this->freshSite(['demo' => true]);
        $this->createPage('a');
        $this->createPage('b');
        $this->assertThrows(AgeCMS\ValidationException::class, fn() => $this->rename('index', 'home'), 'home page');
        $this->assertThrows(AgeCMS\ValidationException::class, fn() => $this->rename('a', 'a'), 'same');
        $this->assertThrows(AgeCMS\ValidationException::class, fn() => $this->rename('a', 'b'), 'already exists');
        $this->assertThrows(AgeCMS\ValidationException::class, fn() => $this->rename('a', 'Bad Slug'), 'not a valid slug');
        $this->assertThrows(AgeCMS\ValidationException::class, fn() => $this->rename('a', 'admin/x'), 'not a valid slug');
        $this->assertThrows(AgeCMS\NotFoundException::class, fn() => $this->rename('nothing', 'x'), 'not found');

        Queue::submit($this->admin(), 'delete_page', ['slug' => 'b']);
        $this->assertThrows(AgeCMS\ValidationException::class, fn() => $this->rename('a', 'b'), 'belongs to a deleted page');

        $viewer = new Actor('agent', 'v', 'viewer', 's');
        $this->assertThrows(AgeCMS\ForbiddenException::class, fn() => Queue::submit($viewer, 'rename_page', ['slug' => 'a', 'new_slug' => 'c']));

        // the old address cannot be rolled back or restored while the page lives elsewhere
        $this->rename('a', 'c');
        $revision = (int)Revisions::history('a')[1]['id'];
        $this->assertThrows(AgeCMS\ValidationException::class, fn() => Queue::submit($this->admin(), 'rollback', ['slug' => 'a', 'revision_id' => $revision]), 'moved to another address');
        $this->assertThrows(AgeCMS\NotFoundException::class, fn() => Queue::submit($this->admin(), 'restore_page', ['slug' => 'a']));
    }

    public function testANewPageAtAnOldAddressDropsTheRedirect(): void
    {
        $this->freshSite();
        $this->createPage('old');
        $this->rename('old', 'new');
        $this->assertSame('new', Pages::redirectFor('old'));
        // the old address is reserved for the moved page, so creating there goes through a clean slug check
        $this->createPage('fresh');
        $this->rename('new', 'old'); // moving back is allowed and clears the redirect
        $this->assertSame(null, Pages::redirectFor('old'));
        $this->assertSame('old', Pages::redirectFor('new'));
    }
}
