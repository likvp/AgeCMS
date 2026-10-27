<?php
declare(strict_types=1);

use AgeCMS\{Actor, Database, Pages, Queue, Revisions};

final class LedgerTriggerTest extends TestCase
{
    public function testUpdateIsRejectedByTrigger(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $pdo = Database::pdo();
        $e = $this->assertThrows(PDOException::class, fn() => $pdo->exec("UPDATE revisions SET actor_name = 'mallory'"));
        $this->assertContains('append-only', $e->getMessage());
        $this->assertThrows(PDOException::class, fn() => $pdo->prepare('UPDATE revisions SET action_desc = ? WHERE id = 1')->execute(['x']), 'append-only');
        $this->assertSame('test', Database::value('SELECT actor_name FROM revisions WHERE id = 1'));
    }

    public function testDeleteIsRejectedByTrigger(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $this->assertThrows(PDOException::class, fn() => Database::pdo()->exec('DELETE FROM revisions'), 'append-only');
        $this->assertThrows(PDOException::class, fn() => Database::pdo()->exec('DELETE FROM revisions WHERE id = 1'), 'append-only');
        $this->assertSame(1, (int)Database::value('SELECT COUNT(*) FROM revisions'));
    }

    public function testRejectedWriteInsideTransactionLeavesNothingBehind(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $this->assertThrows(PDOException::class, fn() => Database::write(function (PDO $pdo) {
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('probe', '1')");
            $pdo->exec("UPDATE revisions SET page_slug = 'other'");
        }));
        $this->assertSame(null, Database::value("SELECT value FROM settings WHERE key = 'probe'"));
    }

    public function testHashChainDetectsTamperingEvenWithoutTriggers(): void
    {
        $this->freshSite();
        $this->createPage('a');
        Queue::submit($this->admin(), 'update_block_field', ['slug' => 'a', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'Two']);
        Queue::submit($this->admin(), 'update_block_field', ['slug' => 'a', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'Three']);
        $this->assertSame(['ok' => true, 'checked' => 3, 'broken_at' => null], Revisions::verifyChain());

        // An attacker with file access drops the trigger and rewrites history.
        $pdo = Database::pdo();
        $pdo->exec('DROP TRIGGER revisions_no_update');
        $pdo->exec("UPDATE revisions SET actor_name = 'someone-else' WHERE id = 2");
        $result = Revisions::verifyChain();
        $this->assertSame(false, $result['ok']);
        $this->assertSame(2, $result['broken_at']);
    }

    public function testStateAtMatchesLivePageAcrossCheckpoints(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $ids = [];
        for ($i = 1; $i <= 60; $i++) {
            $r = Queue::submit($this->admin(), 'update_block_field', ['slug' => 'a', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => "Version {$i}"]);
            $ids[$i] = $r['revision_id'];
        }
        foreach ([1, 24, 25, 26, 49, 50, 51, 60] as $i) {
            $state = Revisions::stateAt('a', $ids[$i]);
            $this->assertSame("Version {$i}", $state['blocks'][0]['data']['h1'], "state at version {$i}");
        }
        $this->assertSame(Pages::doc('a'), Revisions::stateAt('a', $ids[60]));
        $checkpoints = (int)Database::value('SELECT COUNT(*) FROM revisions WHERE snapshot_json IS NOT NULL');
        $this->assertTrue($checkpoints >= 3, 'checkpoints were written');
    }

    public function testRevisionStoresPatchNotFullDocument(): void
    {
        $this->freshSite();
        $this->createPage('a', ['blocks' => [['id' => 'text_1', 'type' => 'text', 'data' => ['body' => str_repeat('long text ', 200)]]]]);
        Queue::submit($this->admin(), 'update_page', ['slug' => 'a', 'fields' => ['title' => 'New title']]);
        $patch = (string)Database::value('SELECT patch_json FROM revisions WHERE id = 2');
        $this->assertTrue(strlen($patch) < 120, 'patch is small: ' . strlen($patch));
        $this->assertSame([['op' => 'replace', 'path' => '/title', 'value' => 'New title']], json_decode($patch, true));
        $this->assertSame(null, Database::value('SELECT snapshot_json FROM revisions WHERE id = 2'));
    }

    public function testNoOpWriteCreatesNoRevision(): void
    {
        $this->freshSite();
        $this->createPage('a');
        $r = Queue::submit($this->admin(), 'update_block_field', ['slug' => 'a', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => 'Heading a']);
        $this->assertSame(false, $r['changed']);
        $this->assertSame(1, (int)Database::value('SELECT COUNT(*) FROM revisions'));
    }
}
