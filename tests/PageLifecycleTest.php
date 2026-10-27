<?php
declare(strict_types=1);

use AgeCMS\{Actor, Api, Auth, Cache, Database, Media, NotFoundException, Pages, Queue, Revisions, ValidationException, ForbiddenException};

final class PageLifecycleTest extends TestCase
{
    public function testDeleteKeepsHistoryAndRestoreBringsPageBack(): void
    {
        $this->freshSite();
        $this->createPage('gone', ['meta_title' => 'Meta']);
        Queue::submit($this->admin(), 'update_block_field', ['slug' => 'gone', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'Edited']);
        $before = Pages::doc('gone');

        $deleted = Queue::submit($this->admin(), 'delete_page', ['slug' => 'gone']);
        $this->assertSame(true, $deleted['changed']);
        $this->assertSame(null, Pages::row('gone'));
        $this->assertSame(['gone'], array_column(Pages::deleted(), 'slug'));
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM pages_fts WHERE slug = 'gone'"));
        $this->assertSame(true, Revisions::verifyChain()['ok'], 'the ledger chain survives a deletion');
        $this->assertSame([], Revisions::stateAt('gone', $deleted['revision_id']));

        $restored = Queue::submit($this->admin(), 'restore_page', ['slug' => 'gone']);
        $this->assertSame($before, Pages::doc('gone'), 'the page comes back exactly as it was');
        $this->assertSame([], Pages::deleted());
        $this->assertContains('Восстановление', (string)Database::value('SELECT action_desc FROM revisions WHERE id = ?', [$restored['revision_id']]));
        $this->assertSame(true, Revisions::verifyChain()['ok']);
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'restore_page', ['slug' => 'gone']), 'nothing to restore');
    }

    public function testProtectedPagesAndPermissions(): void
    {
        $this->freshSite(['demo' => true]);
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'delete_page', ['slug' => 'index']), 'home page');
        $this->createPage('parent');
        $this->createPage('parent/child', ['parent_slug' => 'parent']);
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'delete_page', ['slug' => 'parent']), 'child pages');
        $this->assertThrows(NotFoundException::class, fn() => Queue::submit($this->admin(), 'delete_page', ['slug' => 'nope']));
        $this->assertThrows(NotFoundException::class, fn() => Queue::submit($this->admin(), 'restore_page', ['slug' => 'never-existed']), 'was not deleted');
        $this->assertThrows(ForbiddenException::class, fn() => Queue::submit(Actor::agent('v', 'viewer', 'v1'), 'delete_page', ['slug' => 'parent/child']));

        $editor = Actor::agent('ed', 'editor', 'ed1');
        Queue::submit($editor, 'delete_page', ['slug' => 'parent/child']);
        $this->assertSame(null, Pages::row('parent/child'), 'editors may delete, history keeps the page');
    }

    public function testRestoreDropsAParentThatNoLongerExists(): void
    {
        $this->freshSite(['demo' => true]);
        $this->createPage('p1');
        $this->createPage('p1/c1', ['parent_slug' => 'p1']);
        Queue::submit($this->admin(), 'delete_page', ['slug' => 'p1/c1']);
        Queue::submit($this->admin(), 'delete_page', ['slug' => 'p1']);
        Queue::submit($this->admin(), 'restore_page', ['slug' => 'p1/c1']);
        $this->assertSame('', Pages::doc('p1/c1')['parent_slug']);
    }

    public function testRollbackWorksOnADeletedPageAndRecreatesIt(): void
    {
        $this->freshSite();
        $created = $this->createPage('back');
        Queue::submit($this->admin(), 'update_page', ['slug' => 'back', 'fields' => ['title' => 'Second']]);
        $del = Queue::submit($this->admin(), 'delete_page', ['slug' => 'back']);
        $this->assertThrows(ValidationException::class, fn() => Queue::submit($this->admin(), 'rollback', ['slug' => 'back', 'revision_id' => $del['revision_id']]), 'deletion');
        Queue::submit($this->admin(), 'rollback', ['slug' => 'back', 'revision_id' => $created['revision_id']]);
        $this->assertSame('Page back', Pages::doc('back')['title']);
    }

    public function testDeletedPageStopsBeingServed(): void
    {
        $this->freshSite(['demo' => true]);
        $this->createPage('temp', ['is_published' => 1]);
        $this->assertSame(true, Cache::warm('temp'));
        $this->assertSame(true, is_file(Cache::fileFor('temp')));
        Queue::submit($this->admin(), 'delete_page', ['slug' => 'temp']);
        $this->assertSame(false, is_file(Cache::fileFor('temp')), 'cached copy is dropped');
        $this->assertSame(false, Cache::warm('temp'));
    }

    public function testAgentToolsForDeletion(): void
    {
        $this->freshSite(['demo' => true]);
        $this->createPage('tool');
        $agent = Actor::agent('bot', 'editor', 'b1');
        Api::call($agent, 'delete_page', ['slug' => 'tool']);
        $this->assertSame(['tool'], array_column(Api::call($agent, 'list_deleted_pages', [])['pages'], 'slug'));
        Api::call($agent, 'restore_page', ['slug' => 'tool']);
        $this->assertSame([], Api::call($agent, 'list_deleted_pages', [])['pages']);
        $names = array_column(Api::tools(Actor::agent('v', 'viewer')), 'name');
        $this->assertTrue(in_array('list_deleted_pages', $names, true));
        $this->assertTrue(!in_array('delete_page', $names, true), 'viewers do not see write tools');
    }

    public function testUserManagementRules(): void
    {
        $this->freshSite();
        $adminId = (int)Database::value("SELECT id FROM users WHERE username = 'admin'");
        $editorId = Auth::createUser('anna', 'long-enough-pass', 'editor');

        Auth::changeRole($editorId, 'viewer', $adminId);
        $this->assertSame('viewer', Database::value('SELECT role FROM users WHERE id = ?', [$editorId]));
        $this->assertThrows(ValidationException::class, fn() => Auth::changeRole($adminId, 'editor', $adminId), 'own role');
        $this->assertThrows(ValidationException::class, fn() => Auth::changeRole($editorId, 'boss', $adminId), 'Unknown role');

        $second = Auth::createUser('boss2', 'long-enough-pass', 'admin');
        Auth::changeRole($adminId, 'editor', $second);
        $this->assertThrows(ValidationException::class, fn() => Auth::changeRole($second, 'viewer', $adminId), 'last administrator');
        $this->assertThrows(ValidationException::class, fn() => Auth::deleteUser($second, $adminId), 'last administrator');
        $this->assertThrows(ValidationException::class, fn() => Auth::deleteUser($second, $second), 'own account');

        Auth::deleteUser($editorId, $second);
        $this->assertSame(null, Database::row('SELECT id FROM users WHERE id = ?', [$editorId]));
        $this->assertThrows(NotFoundException::class, fn() => Auth::deleteUser($editorId, $second));
        $this->assertThrows(ValidationException::class, fn() => Auth::createUser('anna', 'short', 'editor'), 'at least 10');
    }

    public function testPasswordChangeNeedsTheCurrentPassword(): void
    {
        $this->freshSite();
        $id = (int)Database::value("SELECT id FROM users WHERE username = 'admin'");
        $this->assertThrows(ValidationException::class, fn() => Auth::changeOwnPassword($id, 'wrong-password', 'new-long-password'), 'wrong');
        $this->assertThrows(ValidationException::class, fn() => Auth::changeOwnPassword($id, 'Passw0rd-test-1', 'short'), 'at least 10');
        Auth::changeOwnPassword($id, 'Passw0rd-test-1', 'new-long-password');
        $this->assertTrue(password_verify('new-long-password', Database::row('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash']));
        Auth::resetPassword($id, 'reset-by-admin-1');
        $row = Database::row('SELECT password_hash FROM users WHERE id = ?', [$id]);
        $this->assertTrue(password_verify('reset-by-admin-1', $row['password_hash']));
        $generated = Auth::generatePassword();
        $this->assertSame(16, strlen($generated));
        $this->assertSame(1, preg_match('/^[A-Za-z2-9]+$/', $generated));
    }

    public function testMediaListUsageAndDelete(): void
    {
        $this->freshSite(['demo' => true]);
        if (!Media::available()) {
            return;
        }
        $png = TEST_TMP . '/pic.png';
        $im = imagecreatetruecolor(40, 30);
        imagepng($im, $png);
        $url = Media::optimize($png);
        $name = basename($url);
        $this->assertSame([$url], array_column(Media::items(), 'url'));

        $this->createPage('withimg', ['blocks' => [['id' => 'image_1', 'type' => 'image', 'data' => ['src' => $url, 'alt' => 'pic']]]]);
        $this->assertSame(['withimg'], array_column(Media::usage($name), 'slug'));
        $this->assertThrows(ValidationException::class, fn() => Media::delete($name), 'used on');
        $this->assertThrows(ValidationException::class, fn() => Media::delete('../../etc/passwd'), 'Bad image name');

        Queue::submit($this->admin(), 'update_page', ['slug' => 'withimg', 'fields' => ['blocks' => []]]);
        Media::delete($name);
        $this->assertSame([], Media::items());
        $this->assertThrows(NotFoundException::class, fn() => Media::delete($name));
    }
}
