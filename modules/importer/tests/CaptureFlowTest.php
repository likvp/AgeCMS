<?php
declare(strict_types=1);

use AgeCMS\{Actor, CopyPage, Pages};
use AgeCMS\Modules\Importer\{Compat, ImportException, Importer, Jobs, Runner};
use AgeCMS\Modules\Importer\Copy\{CaptureFlow, CaptureJob, CaptureRunner};
use AgeCMS\Modules\Importer\Extract\CapturePackage;

require_once dirname(__DIR__, 3) . '/tests/support/HttpCase.php';
require_once dirname(__DIR__) . '/src/autoload.php';

/**
 * The capture of a site on the server in the background: the quick look, the start, the progress, the stop, one capture at a time,
 * a process that died, addresses that are refused, a package that is checked. The tool is a stand-in written in Node
 * (fixtures/fake-capture.mjs) that talks like tools/capture/capture.mjs; the real tool is tested in tools/capture/test.
 */
final class CaptureFlowTest extends HttpCase
{
    /** The stand-in tool and no DNS: every test starts with this. */
    private function prepare(): void
    {
        $this->freshSite();
        CaptureRunner::$scriptOverride = __DIR__ . '/fixtures/fake-capture.mjs';
        CaptureRunner::$addressCheck = static fn(string $url): ?string => null;
        CaptureRunner::$availableOverride = null;
    }

    /** No test leaves a browser stand-in running. */
    public function __destruct()
    {
        try {
            foreach (Jobs::exists() ? Jobs::recent(50) : [] as $j) {
                if (in_array($j['status'], Jobs::CAPTURING, true)) {
                    CaptureFlow::cancel((int)$j['id']);
                }
            }
        } catch (\Throwable) {
            // the sandbox is already gone
        }
        CaptureRunner::$scriptOverride = null;
        CaptureRunner::$addressCheck = null;
        CaptureRunner::$availableOverride = null;
        parent::__destruct();
    }

    /** Steps the job until its status is one of $want (or the time is up). @param list<string> $want */
    private function until(int $id, array $want, int $seconds = 25): array
    {
        $runner = new Runner();
        $end = microtime(true) + $seconds;
        do {
            $job = $runner->step($id, microtime(true) + 3);
            if (in_array($job['status'], $want, true)) {
                return $job;
            }
            usleep(200000);
        } while (microtime(true) < $end);
        return $job;
    }

    private function alive(int $pid): bool
    {
        return $pid > 0 && function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
    }

    public function testASiteIsLookedAtThenCapturedThenTransferred(): void
    {
        $this->prepare();
        $actor = $this->admin();
        $id = Runner::create('https://old.test/', ['mode' => 'exact', 'delay_ms' => 0, 'chunk' => 20, 'replace_home' => true], $actor);
        $this->assertSame('scouting', Jobs::get($id)['status']);
        $job = $this->until($id, ['scouted']);
        $this->assertSame('scouted', $job['status'], (string)$job['error']);
        $this->assertSame('tilda', $job['platform']);
        $this->assertSame(2, $job['result']['scout']['pages_found']);
        $this->assertSame(0, Jobs::countItems($id), 'the look reads no pages');

        Runner::startApply($id, null, $actor);
        $this->assertSame('capturing', Jobs::get($id)['status']);
        $job = $this->until($id, ['done', 'failed']);
        $this->assertSame('done', $job['status'], (string)$job['error']);
        $this->assertSame('capture_dir', $job['source_type']);
        $this->assertSame('https://old.test/', $job['result']['captured_from']);
        $this->assertSame(2, Jobs::countItems($id, ['done']));
        $this->assertTrue(CopyPage::is(Pages::doc('index')), 'the home page is a copy');
        $this->assertTrue(CopyPage::is(Pages::doc('about')));
        $this->assertSame('https://old.test/', Runner::sourceLabel(['source_type' => 'url', 'source' => 'https://old.test/']));

        Runner::startRollback($id, $actor);
        $this->assertSame('rolled_back', (new Runner())->runToEnd($id)['status']);
        $this->assertTrue(Pages::row('about') === null, 'one action undoes the transfer');
    }

    public function testOnlyOneCaptureRunsAtATime(): void
    {
        $this->prepare();
        $actor = $this->admin();
        $first = Runner::create('https://slow.test/', ['mode' => 'exact'], $actor);
        $e = $this->assertThrows(ImportException::class, fn() => Runner::create('https://other.test/', ['mode' => 'exact'], $actor));
        $this->assertContains('уже идёт', $e->getMessage());
        $this->assertSame($first, Jobs::capturing()['id']);
        CaptureFlow::cancel($first);
        $this->assertSame('cancelled', Jobs::get($first)['status']);
        $second = Runner::create('https://other.test/', ['mode' => 'exact'], $actor);
        $this->assertTrue($second > $first, 'after the stop a new capture starts');
    }

    public function testStopKillsTheProcessAndKeepsTheSite(): void
    {
        $this->prepare();
        $actor = $this->admin();
        $id = Runner::create('https://slow.test/', ['mode' => 'exact'], $actor);
        $job = $this->until($id, ['scouted', 'failed'], 3);
        $this->assertSame('scouting', $job['status'], 'a slow look is still running');
        $pid = (int)$job['result']['capture']['pid'];
        $this->assertTrue($this->alive($pid), 'the process runs');
        CaptureFlow::cancel($id);
        usleep(300000);
        $this->assertTrue(!$this->alive($pid), 'the process is gone');
        $this->assertSame('cancelled', Jobs::get($id)['status']);
        $this->assertSame(0, Jobs::countItems($id));
        $this->assertSame(null, Jobs::capturing());
    }

    public function testAProcessThatDiedIsMarkedInterrupted(): void
    {
        $this->prepare();
        $actor = $this->admin();
        $id = Runner::create('https://slow.test/', ['mode' => 'exact'], $actor);
        $job = $this->until($id, ['failed'], 2);
        $pid = (int)$job['result']['capture']['pid'];
        posix_kill($pid, 9);
        usleep(300000);
        // The heartbeat in the file is old now: the site must not wait for it forever.
        $file = CaptureJob::statusFile($id, 'scout');
        $st = json_decode((string)file_get_contents($file), true);
        $st['updated'] = (time() - 300) * 1000;
        file_put_contents($file, json_encode($st));
        $job = $this->until($id, ['failed'], 5);
        $this->assertSame('failed', $job['status']);
        $this->assertContains('прервался', $job['error']);
        $this->assertSame(null, Jobs::capturing(), 'a new capture can start');
    }

    public function testAFailureOfTheToolIsShownWithItsReason(): void
    {
        $this->prepare();
        $id = Runner::create('https://boom.test/', ['mode' => 'exact'], $this->admin());
        $job = $this->until($id, ['failed'], 10);
        $this->assertSame('failed', $job['status']);
        $this->assertContains('тестовый отказ', $job['error']);
    }

    public function testAnAddressOfTheInternalNetworkIsRefusedBeforeAnyBrowser(): void
    {
        $this->prepare();
        CaptureRunner::$addressCheck = null;
        foreach (['http://127.0.0.1/', 'http://localhost/', 'http://[::1]/', 'http://10.1.2.3/', 'http://172.16.0.5/', 'http://192.168.0.1/', 'http://169.254.169.254/', 'http://100.64.1.1/', 'http://[fd12::1]/', 'http://[::ffff:127.0.0.1]/', 'http://2130706433/'] as $url) {
            $e = $this->assertThrows(ImportException::class, fn() => Runner::create($url, ['mode' => 'exact'], $this->admin()), 'Адрес не подходит');
            }
        $this->assertSame(0, count(Jobs::recent()), 'no job was left behind');
        foreach (['file:///etc/passwd', 'ftp://example.com/', 'chrome://settings'] as $url) {
            $threw = false;
            try {
                CaptureRunner::addressProblem($url) === null ? Runner::create($url, ['mode' => 'exact'], $this->admin()) : throw new ImportException('refused');
            } catch (ImportException) {
                $threw = true;
            }
            $this->assertTrue($threw, $url);
        }
    }

    public function testWithoutNodeOrChromeThePersonIsToldWhatToDo(): void
    {
        $this->prepare();
        CaptureRunner::$availableOverride = false;
        $e = $this->assertThrows(ImportException::class, fn() => Runner::create('https://old.test/', ['mode' => 'exact'], $this->admin()));
        $this->assertContains('Node.js', $e->getMessage());
        $this->assertContains('tools/capture', $e->getMessage());
        $this->assertSame(0, count(Jobs::recent()));
    }

    public function testAFilePathIsAcceptedFromTheConsoleOnly(): void
    {
        $this->prepare();
        $file = TEST_TMP . '/e.xml';
        file_put_contents($file, '<?xml version="1.0"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title></channel></rss>');
        $agent = new Actor('agent', 'token', 'admin');
        $e = $this->assertThrows(ImportException::class, fn() => Runner::create($file, ['mode' => 'blocks'], $agent));
        $this->assertContains('только консоль', $e->getMessage());
        $e = $this->assertThrows(ImportException::class, fn() => Runner::create('/etc/hosts', ['mode' => 'blocks'], $agent));
        $this->assertContains('только консоль', $e->getMessage());
        $this->assertTrue(Runner::create($file, ['mode' => 'blocks'], Actor::cli()) > 0, 'the console may');
        $this->assertTrue(Runner::create($file, ['mode' => 'blocks'], $agent, 'e.xml', true) > 0, 'a file the caller brought itself is fine');
    }

    public function testACapturePackageIsCheckedLikeAnUploadedOne(): void
    {
        $dir = TEST_TMP . '/pkg-' . bin2hex(random_bytes(3));
        mkdir($dir . '/pages', 0700, true);
        mkdir($dir . '/files/x.test', 0700, true);
        file_put_contents($dir . '/manifest.json', '{"format":"agecms-capture/1","pages":[]}');
        file_put_contents($dir . '/pages/0001.html', '<p>ok</p>');
        file_put_contents($dir . '/files/x.test/a.css', 'a{}');
        file_put_contents($dir . '/files/x.test/shell.php', '<?php echo 1;');
        file_put_contents($dir . '/outside.txt', 'x');
        @symlink('/etc/hosts', $dir . '/files/x.test/link.css');
        $r = CapturePackage::verifyDir($dir);
        $this->assertTrue(is_file($dir . '/files/x.test/a.css'));
        $this->assertTrue(!is_file($dir . '/files/x.test/shell.php'), 'a script is removed');
        $this->assertTrue(!file_exists($dir . '/files/x.test/link.css') && !is_link($dir . '/files/x.test/link.css'), 'a link is removed');
        $this->assertTrue($r['removed'] >= 2);
        $this->assertSame(3, $r['files'], 'manifest, page and style stay');
    }

    public function testRefusalsOfTheCoreAreToldInRussian(): void
    {
        $this->assertSame('адрес «/about» уже занят существующей страницей', Compat::redirectWhy("'/about' is the address of an existing page"));
        $this->assertSame('что-то своё', Compat::redirectWhy('что-то своё'));
    }
}
