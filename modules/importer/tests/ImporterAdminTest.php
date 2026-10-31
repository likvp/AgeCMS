<?php
declare(strict_types=1);

use AgeCMS\{Actor, CircuitBreaker, Content, Database, Pages, SkillRegistry};
use AgeCMS\Modules\Importer\{ImportException, Jobs, Runner};

require_once dirname(__DIR__, 3) . '/tests/support/HttpCase.php';
require_once dirname(__DIR__) . '/src/autoload.php';

/**
 * Importer in the admin on the core's tools: the export file uploaded through the form (kinds xml and zip of the core),
 * the progress through data-ag-step of admin.js (POST with the CSRF token, the bare fragment back), and the rollback of a
 * big import a day later through the explicit undo of the job session (allowed to a person and to the agent token that
 * started it, never to another token).
 */
final class ImporterAdminTest extends HttpCase
{
    private const FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];

    /** A WordPress export with $count pages, no pictures (nothing to download). */
    private static function wxr(int $count): string
    {
        $items = '';
        for ($i = 1; $i <= $count; $i++) {
            $items .= '<item><title>Страница ' . $i . '</title><link>https://site.invalid/page-' . $i . '/</link><content:encoded><![CDATA[<!-- wp:paragraph --><p>Текст ' . $i . '.</p><!-- /wp:paragraph -->]]></content:encoded>'
                . '<wp:post_id>' . (100 + $i) . '</wp:post_id><wp:post_name>page-' . $i . '</wp:post_name><wp:status>publish</wp:status><wp:post_type>page</wp:post_type></item>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>Тест</title><wp:base_site_url>https://site.invalid</wp:base_site_url>'
            . $items . '</channel></rss>';
    }

    private function login(): string
    {
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), self::FORM, true);
        preg_match('/name="csrf" value="([^"]+)"/', $this->http('GET', '/admin/m/importer/', null, [], true)['body'], $m);
        return $m[1] ?? '';
    }

    /** @param array<string,array{0:string,1:string}> $files */
    private function multipart(string $path, array $fields, array $files): array
    {
        $post = $fields;
        foreach ($files as $key => [$name, $content]) {
            $tmp = TEST_TMP . '/up-' . bin2hex(random_bytes(3));
            file_put_contents($tmp, $content);
            $post[$key] = new CURLFile($tmp, 'application/octet-stream', $name);
        }
        $out = [];
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$out): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $out[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            }]);
        $body = (string)curl_exec($ch);
        return ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $out, 'body' => $body];
    }

    /** What admin.js does: posts to the step address with the token until the fragment says done. Returns the last fragment. */
    private function steps(int $id, string $csrf): string
    {
        for ($i = 0; $i < 50; $i++) {
            $r = $this->http('POST', '/admin/m/importer/job?id=' . $id . '&action=step', http_build_query(['csrf' => $csrf]), self::FORM + ['X-AgeCMS-Poll' => '1'], true);
            $this->assertSame(200, $r['status'], $r['body']);
            $this->assertSame('1', $r['headers']['x-agecms-fragment'] ?? '');
            $this->assertNotContains('class="side"', $r['body'], 'a bare fragment');
            if (str_contains($r['body'], 'data-ag-done=')) {
                return $r['body'];
            }
        }
        throw new AssertionError('the job did not finish');
    }

    public function testUploadProgressByStepsAndRollbackInTheAdmin(): void
    {
        $this->boot();
        $csrf = $this->login();
        $page = $this->http('GET', '/admin/m/importer/', null, [], true);
        $this->assertContains('action="/admin/m/importer/upload" enctype="multipart/form-data"', $page['body']);
        $this->assertContains('type="file" name="upload"', $page['body']);
        $this->assertTrue(preg_match('~\(\.zip\), до \d+(,\d)? [МК]Б~u', $page['body']) === 1, 'the limit of this server is shown');

        $bad = $this->multipart('/admin/m/importer/upload', ['csrf' => $csrf], ['upload' => ['export.xml', '<html><body>не выгрузка</body></html>']]);
        $this->assertSame(400, $bad['status']);
        $this->assertContains('не принимается', $bad['body']);
        $both = $this->multipart('/admin/m/importer/upload', ['csrf' => $csrf, 'url' => 'https://old.example'], ['upload' => ['export.xml', self::wxr(1)]]);
        $this->assertSame(422, $both['status']);
        $this->assertContains('Выберите что-то одно', $both['body']);

        $start = $this->multipart('/admin/m/importer/upload', ['csrf' => $csrf, 'limit' => '50', 'conflict' => 'suffix'], ['upload' => ['site-export.xml', self::wxr(3)]]);
        $this->assertSame(303, $start['status'], $start['body']);
        $this->assertTrue(preg_match('~^/admin/m/importer/job\?id=(\d+)$~', $start['headers']['location'] ?? '', $m) === 1, $start['headers']['location'] ?? '');
        $id = (int)$m[1];
        $this->assertSame([], glob(AGECMS_DATA . '/module_files/importer/*') ?: [], 'the uploaded file is not kept twice');
        $this->assertTrue(is_file(Runner::dir($id) . '/source.xml'), 'the job keeps its own copy under data/');

        $view = $this->http('GET', '/admin/m/importer/job?id=' . $id, null, [], true);
        $this->assertContains('data-ag-step="/admin/m/importer/job?id=' . $id . '&amp;action=step"', $view['body']);
        $this->assertContains('<noscript><meta http-equiv="refresh"', $view['body'], 'without JavaScript the page refreshes itself');
        $done = $this->steps($id, $csrf);
        $this->assertContains('data-ag-done="/admin/m/importer/job?id=' . $id . '"', $done, 'the report opens when the analysis is over');
        $ready = $this->http('GET', '/admin/m/importer/job?id=' . $id, null, [], true);
        $this->assertContains('Что будет перенесено', $ready['body']);
        $this->assertNotContains('data-ag-step', $ready['body'], 'nothing to poll on the preview');

        preg_match_all('~name="pages\[\]" value="(\d+)"~', $ready['body'], $pm);
        $this->assertSame(3, count($pm[1]));
        $apply = $this->http('POST', '/admin/m/importer/job?id=' . $id, http_build_query(['csrf' => $csrf, 'action' => 'apply', 'pages' => $pm[1]]), self::FORM, true);
        $this->assertSame(303, $apply['status']);
        $this->steps($id, $csrf);
        $report = $this->http('GET', '/admin/m/importer/job?id=' . $id, null, [], true);
        $this->assertContains('Создано страниц: <b>3</b>', $report['body']);
        $this->assertSame(200, $this->http('GET', '/page-2')['status']);

        $rollback = $this->http('POST', '/admin/m/importer/job?id=' . $id, http_build_query(['csrf' => $csrf, 'action' => 'rollback']), self::FORM, true);
        $this->assertSame(303, $rollback['status']);
        $this->steps($id, $csrf);
        $this->assertContains('Импорт отменён: убрано страниц 3', $this->http('GET', '/admin/m/importer/job?id=' . $id, null, [], true)['body']);
        $this->assertSame(404, $this->http('GET', '/page-2')['status']);
        $this->stop();
    }

    /** Runs a job from the export to "done" as $actor. */
    private function imported(int $pages, Actor $actor): int
    {
        $file = TEST_TMP . '/export-' . bin2hex(random_bytes(3)) . '.xml';
        file_put_contents($file, self::wxr($pages));
        $id = Runner::create($file, ['delay_ms' => 0, 'limit' => 500, 'chunk' => 20], $actor);
        $runner = new Runner();
        $this->assertSame('ready', $runner->runToEnd($id)['status']);
        Runner::startApply($id, null, $actor);
        $job = $runner->runToEnd($id);
        $this->assertSame('done', $job['status'], (string)$job['error']);
        return $id;
    }

    /** The job session is a day and an hour old: the circuit breaker has forgotten what it created. */
    private function aDayLater(int $id): void
    {
        Database::write(static fn(PDO $pdo) => $pdo->prepare('UPDATE agent_sessions SET created_at = ? WHERE id = ?')->execute([gmdate('Y-m-d\\TH:i:s\\Z', time() - 90000), Runner::session($id)]));
    }

    private function siteOf(int $pages): void
    {
        $this->freshSite();
        SkillRegistry::boot();
        for ($i = 1; $i <= $pages; $i++) {
            $this->createPage('old-' . $i, [], Actor::cli('setup')->withSession('setup'));
        }
    }

    public function testAPersonRollsBackABigImportADayLater(): void
    {
        $this->siteOf(10);
        $owner = Actor::human('owner', 'admin')->withSession('h-owner');
        $id = $this->imported(30, $owner);
        $this->assertSame(40, (int)Database::value('SELECT COUNT(*) FROM pages'));
        $this->aDayLater($id);
        Content::updatePage(Actor::human('editor', 'editor')->withSession('h-editor'), 'page-7', ['title' => 'Правка']);

        Runner::startRollback($id, $owner);
        $this->assertSame('session', Jobs::get($id)['result']['rollback_mode']);
        $job = (new Runner())->runToEnd($id);
        $this->assertSame('rolled_back', $job['status'], (string)$job['error']);
        $this->assertSame(false, CircuitBreaker::isFrozen(), '29 deletions on a 40 page site, outside the 15%');
        $this->assertSame(11, (int)Database::value('SELECT COUNT(*) FROM pages'), 'the ten old pages and the edited one stay');
        $this->assertTrue(Pages::row('page-7') !== null);
        $this->assertSame(29, Jobs::countItems($id, ['rolled_back']));
        $this->assertContains('Разрешена отмена сессии ' . Runner::session($id), (string)Database::value("SELECT action_desc FROM revisions WHERE page_slug = '@system' AND action_desc LIKE 'Разрешена отмена%' ORDER BY id DESC LIMIT 1"));
    }

    public function testAnAgentRollsBackOnlyAnImportOfItsOwnToken(): void
    {
        $this->siteOf(10);
        $token5 = Actor::agent('bot', 'admin', 'tok-5-2026103110');
        $other = Actor::agent('bot', 'admin', 'tok-6-2026103112');
        $mine = $this->imported(12, $token5);
        $this->aDayLater($mine);

        $e = $this->assertThrows(ImportException::class, fn() => SkillRegistry::call('importer', 'import_rollback', ['job_id' => $mine], $other));
        $this->assertContains('тот же токен агента', $e->getMessage());
        $this->assertSame('done', Jobs::get($mine)['status'], 'nothing started');

        $later = Actor::agent('bot', 'admin', 'tok-5-2026103118');
        $r = SkillRegistry::call('importer', 'import_rollback', ['job_id' => $mine], $later);
        for ($i = 0; $i < 20 && $r['status'] === 'rolling_back'; $i++) {
            $r = SkillRegistry::call('importer', 'import_status', ['job_id' => $mine], $later);
        }
        $this->assertSame('rolled_back', $r['status'], (string)($r['error'] ?? ''));
        $this->assertSame(10, (int)Database::value('SELECT COUNT(*) FROM pages'));
        $this->assertSame(false, CircuitBreaker::isFrozen());

        // An import of a person is not the agent's to undo.
        $persons = $this->imported(5, Actor::human('owner', 'admin')->withSession('h-owner'));
        $this->assertThrows(ImportException::class, fn() => SkillRegistry::call('importer', 'import_rollback', ['job_id' => $persons], $later), 'администратор');
        $this->assertSame(15, (int)Database::value('SELECT COUNT(*) FROM pages'));
    }
}
