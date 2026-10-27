<?php
declare(strict_types=1);

use AgeCMS\{Actor, AgeCMSException, Database, ForbiddenException, Pages, Queue, Revisions};

final class QueueTest extends TestCase
{
    public function testForgedJobFileIsRejected(): void
    {
        $this->freshSite();
        $this->createPage('x');
        $dir = Queue::dir();
        $job = ['id' => '1-forged', 'op' => 'update_page', 'args' => ['slug' => 'x', 'fields' => ['title' => 'Owned']], 'actor' => Actor::cli('evil')->toArray(), 'created_at' => '2026-01-01T00:00:00Z', 'sig' => str_repeat('0', 64)];
        file_put_contents($dir . '/1-forged.json', json_encode($job));
        Queue::drain();
        $result = json_decode((string)file_get_contents($dir . '/done/1-forged.json'), true);
        $this->assertSame(false, $result['ok']);
        $this->assertSame('forbidden', $result['kind']);
        $this->assertSame('Page x', Pages::doc('x')['title']);
    }

    public function testTamperedJobFileIsRejected(): void
    {
        $this->freshSite();
        $this->createPage('x');
        $id = Queue::enqueue(Actor::agent('v', 'viewer', 's'), 'update_page', ['slug' => 'x', 'fields' => ['title' => 'Mine']]);
        $file = Queue::dir() . "/{$id}.json";
        $job = json_decode((string)file_get_contents($file), true);
        $job['actor']['role'] = 'admin';
        file_put_contents($file, json_encode($job));
        Queue::drain();
        $result = json_decode((string)file_get_contents(Queue::dir() . "/done/{$id}.json"), true);
        $this->assertSame('forbidden', $result['kind']);
        $this->assertSame('Page x', Pages::doc('x')['title']);
    }

    public function testMalformedJobsAreParkedNotLooped(): void
    {
        $this->freshSite();
        Queue::enqueue($this->admin(), 'create_page', ['slug' => 'ok', 'fields' => ['title' => 'Ok']]);
        file_put_contents(Queue::dir() . '/0-garbage.json', '{not json');
        Queue::drain();
        $this->assertTrue(is_file(Queue::dir() . '/failed/0-garbage.json'));
        $this->assertSame(null, glob(Queue::dir() . '/*.json') ?: null);
        $this->assertSame('Ok', Pages::doc('ok')['title']);
    }

    public function testUnknownOperationIsRefusedAtEnqueue(): void
    {
        $this->freshSite();
        $this->assertThrows(AgeCMSException::class, fn() => Queue::enqueue($this->admin(), 'drop_database', []));
    }

    public function testJobsRunInSubmissionOrder(): void
    {
        $this->freshSite();
        $this->createPage('o');
        for ($i = 1; $i <= 5; $i++) {
            Queue::enqueue($this->admin(), 'update_block_field', ['slug' => 'o', 'block_id' => 'hero_1', 'field_path' => 'h1', 'value' => "V{$i}"]);
            usleep(1500);
        }
        $this->assertSame(5, Queue::drain());
        $this->assertSame('V5', Pages::doc('o')['blocks'][0]['data']['h1']);
    }

    /** Several PHP processes write at once: nothing is lost, nothing hits SQLITE_BUSY, the ledger stays intact. */
    public function testParallelWritersDoNotLoseOrCorruptData(): void
    {
        $this->freshSite();
        // Eight writers touching four pages is exactly what the breaker exists to stop; it is tested elsewhere.
        \AgeCMS\Config::set('circuit_breaker.ratio', 5);
        for ($p = 0; $p < 4; $p++) {
            $this->createPage('par' . $p);
        }
        $workers = 8;
        $perWorker = 12;
        $procs = [];
        for ($w = 0; $w < $workers; $w++) {
            $cmd = [PHP_BINARY, __DIR__ . '/support/writer.php', (string)$w, (string)$perWorker];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
                'AGECMS_DATA_DIR' => AGECMS_DATA, 'AGECMS_CACHE_DIR' => AGECMS_CACHE, 'AGECMS_MODULES_DIR' => AGECMS_MODULES, 'PATH' => getenv('PATH'),
            ]);
            $procs[] = [$proc, $pipes];
        }
        $failures = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            if (trim($out) !== 'ok ' . $perWorker) {
                $failures[] = trim($out . ' ' . $err);
            }
        }
        $this->assertSame([], $failures, 'every worker finished all its writes');

        $expected = 4 + $workers * $perWorker;
        $this->assertSame($expected, (int)Database::value('SELECT COUNT(*) FROM revisions'), 'every write produced exactly one revision');
        $this->assertSame(['ok' => true, 'checked' => $expected, 'broken_at' => null], Revisions::verifyChain());
        $this->assertSame(0, count(glob(Queue::dir() . '/*.json') ?: []), 'queue is empty');
    }
}
