<?php
declare(strict_types=1);

use AgeCMS\{Queue, Slug};

final class SlugTest extends TestCase
{
    public function testTitlesBecomeAddresses(): void
    {
        $this->assertSame('remont-kvartir-pod-klyuch', Slug::fromTitle('Ремонт квартир под ключ'));
        $this->assertSame('shchuka-i-ezh', Slug::fromTitle('  Щука и Ёж!  '));
        $this->assertSame('o-kompanii-2026', Slug::fromTitle('О компании (2026)'));
        $this->assertSame('hello-world', Slug::fromTitle('Hello, World'));
        $this->assertSame('', Slug::fromTitle('***'));
        $this->assertSame('obem-i-tolshchina', Slug::fromTitle('Объём и толщина'));
        $long = Slug::fromTitle(str_repeat('очень длинное название ', 12));
        $this->assertTrue(strlen($long) <= 60 && !str_ends_with($long, '-'), 'long titles are cut at a word');
    }

    public function testFreeAddressesSkipTakenAndDeletedPages(): void
    {
        $this->freshSite();
        $this->assertSame('remont', Slug::free('Ремонт'));
        $this->createPage('remont');
        $this->assertSame('remont-2', Slug::free('Ремонт'));
        Queue::submit($this->admin(), 'delete_page', ['slug' => 'remont']);
        $this->assertSame('remont-2', Slug::free('Ремонт'), 'the address of a deleted page is not reused');
        $this->assertSame('page', Slug::free('???'));
        $this->assertSame('uslugi/remont', Slug::free('Ремонт', 'uslugi'));
        $this->assertSame('remont-2', Slug::free('Ремонт', 'index'), 'the home page is not a path prefix');
        $this->assertSame('admin-2', Slug::free('admin'), 'reserved words are avoided');
    }
}
