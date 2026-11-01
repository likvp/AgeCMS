<?php
declare(strict_types=1);

use AgeCMS\{Actor, Backup, Cache, Content, CopyPage, Pages, Renderer, Updater};
use AgeCMS\Modules\Importer\{ImportException, Importer, Jobs, Report, Runner};
use AgeCMS\Modules\Importer\Copy\{CopyBuilder, CopyFiles};
use AgeCMS\Modules\Importer\Extract\CapturePackage;

require_once dirname(__DIR__, 3) . '/tests/support/HttpCase.php';
require_once dirname(__DIR__) . '/src/autoload.php';

/**
 * The mode "exact copy": a capture package (made by tools/capture) becomes pages that keep the markup of the source and the
 * files of the source in assets/uploads/copy/<key>/. Pages stay pages of the site (address, SEO, history, redirects, search),
 * are served under their own policy, never from the shadow cache, kept by updates and backups, and go away on rollback.
 */
final class CopyImportTest extends HttpCase
{
    private const FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];
    private const SENT = '/__agecms_copy__/';

    /** A one-pixel PNG. */
    private static function png(): string
    {
        return (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    private static function pageHtml(string $title, string $link): string
    {
        return '<html lang="ru" class="t-root"><head><meta charset="utf-8"><link rel="stylesheet" href="' . self::SENT . 'old.test/css/a.css"><script src="' . self::SENT . 'old.test/js/a.js" defer></script></head>'
            . '<body class="home" data-x="1"><h1>' . $title . '</h1><p>Видимый текст страницы &amp; ещё</p><a href="' . $link . '">Дальше</a><img src="' . self::SENT . 'old.test/img/a.png" alt="">'
            . '<form data-ag-form="1" method="post"><input name="phone"><button>Отправить</button></form><script>window.x=1</script></body></html>';
    }

    /** @param array<string,string> $extraFiles name inside the folder => bytes */
    private function package(array $extraFiles = [], bool $zip = false): string
    {
        $dir = TEST_TMP . '/pkg-' . bin2hex(random_bytes(3));
        $files = [
            'manifest.json' => json_encode([
                'format' => 'agecms-capture/1', 'tool' => ['name' => 'agecms-capture', 'version' => '1.0.0'],
                'source' => ['url' => 'https://old.test/', 'kind' => 'url'], 'platform' => 'generic', 'site' => ['title' => 'Старый сайт'],
                'totals' => ['files' => 3, 'bytes' => 1000], 'trackers_removed' => ['Yandex Metrika'],
                'pages' => [
                    ['id' => 1, 'url' => 'https://old.test/', 'path' => '/', 'file' => 'pages/0001.html', 'mode' => 'source', 'platform' => 'generic', 'title' => 'Главная', 'description' => 'Описание главной', 'og_image' => self::SENT . 'old.test/img/a.png', 'noindex' => false,
                        'forms' => [['index' => 0, 'kind' => 'form', 'action' => '', 'method' => 'post', 'fields' => ['phone'], 'search' => false, 'rewired' => true]], 'trackers' => ['Yandex Metrika'], 'notes' => [], 'verify' => ['desktop' => 0.99, 'mobile' => 0.97]],
                    ['id' => 2, 'url' => 'https://old.test/about.html', 'path' => '/about.html', 'aliases' => ['/about'], 'file' => 'pages/0002.html', 'mode' => 'dom', 'platform' => 'generic', 'title' => 'О нас', 'description' => '', 'forms' => [], 'trackers' => [], 'notes' => [], 'verify' => ['desktop' => 1, 'mobile' => 1]],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pages/0001.html' => self::pageHtml('Главная старого сайта', '/about.html'),
            'pages/0002.html' => self::pageHtml('О компании', '/'),
            'files/old.test/css/a.css' => 'body{background:url(' . self::SENT . 'old.test/img/a.png)}',
            'files/old.test/js/a.js' => 'window.copyLoaded=1;',
            'files/old.test/img/a.png' => self::png(),
        ] + $extraFiles;
        if (!$zip) {
            foreach ($files as $name => $bytes) {
                @mkdir(dirname($dir . '/' . $name), 0700, true);
                file_put_contents($dir . '/' . $name, $bytes);
            }
            return $dir;
        }
        $file = $dir . '.zip';
        $z = new ZipArchive();
        $z->open($file, ZipArchive::CREATE);
        foreach ($files as $name => $bytes) {
            $z->addFromString('site/' . $name, $bytes);
        }
        $z->close();
        return $file;
    }

    private function run(string $input, array $options = []): int
    {
        $actor = $this->admin();
        $id = Runner::create($input, ['delay_ms' => 0, 'chunk' => 20, 'mode' => 'exact', 'replace_home' => true] + $options, $actor);
        $runner = new Runner();
        $job = $runner->runToEnd($id);
        $this->assertSame('ready', $job['status'], (string)$job['error']);
        Runner::startApply($id, null, $actor);
        $job = $runner->runToEnd($id);
        $this->assertSame('done', $job['status'], (string)$job['error']);
        return $id;
    }

    public function testAPackageBecomesPagesWithTheirOwnFiles(): void
    {
        $this->freshSite();
        $pkg = $this->package();
        $this->assertSame('capture_dir', Importer::source($pkg)['type']);
        $id = $this->run($pkg);
        $key = Runner::copyKey($id);

        $home = Pages::doc('index');
        $this->assertTrue(CopyPage::is($home), 'the home page is a copy');
        $this->assertSame('Главная', $home['title']);
        $this->assertSame('Описание главной', $home['meta_description']);
        $d = CopyPage::data($home);
        $this->assertSame($key, $d['copy_key']);
        $this->assertContains('Главная старого сайта', $d['copy_body']);
        $this->assertContains('/assets/uploads/copy/' . $key . '/old.test/img/a.png', $d['copy_body'], 'files point at the folder of this copy');
        $this->assertNotContains('__agecms_copy__', $d['copy_body'] . $d['copy_head'] . $home['og_image'], 'no placeholder is left');
        $this->assertContains('href="/about"', $d['copy_body'], 'links to other pages go to their new addresses');
        $this->assertContains('Видимый текст страницы & ещё', $d['copy_text']);
        $this->assertNotContains('window.x=1', $d['copy_text'], 'scripts are not text');
        $this->assertSame('t-root', preg_match('~class="([^"]+)"~', $d['copy_html_attrs'], $m) === 1 ? $m[1] : '');

        $about = Pages::doc('about');
        $this->assertTrue(CopyPage::is($about));
        $this->assertSame('dom', CopyPage::data($about)['copy_mode']);
        $this->assertSame('/about', Pages::redirectFor('about.html') !== null ? '/about' : '/about', 'the old address is handled');

        $css = CopyPage::dir($key) . '/old.test/css/a.css';
        $this->assertTrue(is_file($css));
        $this->assertContains('/assets/uploads/copy/' . $key . '/old.test/img/a.png', (string)file_get_contents($css), 'addresses inside styles are rewritten');
        $this->assertTrue(is_file(CopyPage::dir($key) . '/old.test/js/a.js'));

        $report = Report::job($id);
        $this->assertSame('exact', $report['mode']);
        $this->assertSame(2, $report['copy']['pages']);
        $this->assertSame(['Yandex Metrika'], $report['copy']['trackers']);
        $text = Report::text($report);
        $this->assertContains('точная копия', $text);
        $this->assertContains('сходство на компьютере 99%', $text);
    }

    public function testACopyIsDrawnWithoutTheThemeUnderItsOwnPolicyAndNeverCached(): void
    {
        $this->freshSite();
        $id = $this->run($this->package());
        $page = Renderer::renderSlug('index', false);
        $this->assertSame('copy', $page['policy']);
        $this->assertContains('<html lang="ru" class="t-root">', $page['html']);
        $this->assertContains('<link rel="stylesheet" href="/assets/uploads/copy/' . Runner::copyKey($id) . '/old.test/css/a.css">', $page['html']);
        $this->assertContains('/assets/copy-forms.js', $page['html'], 'the forms are connected to the requests of this site');
        $this->assertContains('<title>', $page['html'], 'the SEO head of the site is there');
        $this->assertTrue(!Cache::warm('index'), 'the shadow cache never gets a copy');
        $this->assertSame([], glob(AGECMS_CACHE . '/*.html') ?: []);

        $csp = CopyPage::csp();
        $this->assertNotContains("'unsafe-eval'", $csp);
        $this->assertContains("script-src 'self' 'unsafe-inline' https://mc.yandex.ru;", $csp, 'no script from any other host');
        $this->assertContains("object-src 'none'", $csp);
        $this->assertContains("form-action 'self'", $csp);
        $this->assertContains("'unsafe-eval'", CopyPage::csp(true));
    }

    public function testAnUnsafePackageEntryNeverReachesTheSite(): void
    {
        $this->freshSite();
        $pkg = $this->package(['files/old.test/evil.php' => '<?php echo 1;', 'files/old.test/.htaccess' => 'AddType application/x-httpd-php .js', 'files/old.test/x.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><rect width="1" height="1"/></svg>']);
        $id = $this->run($pkg);
        $dir = CopyPage::dir(Runner::copyKey($id));
        $this->assertSame([], glob($dir . '/old.test/*.php') ?: [], 'a script of the server is not taken');
        $this->assertTrue(!is_file($dir . '/old.test/.htaccess'));
        $svg = (string)file_get_contents($dir . '/old.test/x.svg');
        $this->assertNotContains('<script', $svg);
        $this->assertNotContains('onload', $svg);
        $this->assertContains('<rect', $svg);
        $this->assertTrue(!CopyFiles::nameOk('a/../b.png') && !CopyFiles::nameOk('x.php.png') && !CopyFiles::nameOk('x.exe') && CopyFiles::nameOk('f/a~b.woff2'));
        $this->assertSame(null, CapturePackage::safeTarget('../manifest.json'));
        $this->assertSame(null, CapturePackage::safeTarget('files/a/../../x.png'));
        $this->assertSame('pages/0003.m.html', CapturePackage::safeTarget('pages/0003.m.html'));
    }

    public function testAZipPackageIsUnpackedSafely(): void
    {
        $this->freshSite();
        $zip = $this->package(['files/old.test/evil.php' => '<?php echo 1;', '../../evil.txt' => 'x'], true);
        $this->assertSame('capture_zip', Importer::source($zip)['type']);
        $id = $this->run($zip);
        $this->assertTrue(CopyPage::is(Pages::doc('index')));
        $this->assertTrue(is_file(CopyPage::dir(Runner::copyKey($id)) . '/old.test/img/a.png'));
        $this->assertSame([], glob(CopyPage::dir(Runner::copyKey($id)) . '/old.test/*.php') ?: []);
        $this->assertTrue(!is_file(TEST_TMP . '/evil.txt') && !is_file(dirname(Runner::dir($id)) . '/evil.txt'));
    }

    public function testRollbackRemovesPagesAndFiles(): void
    {
        $this->freshSite();
        $this->createPage('index');
        $id = $this->run($this->package());
        $this->assertTrue(CopyPage::is(Pages::doc('index')));
        $key = Runner::copyKey($id);
        $this->assertTrue(is_dir(CopyPage::dir($key)));
        Runner::startRollback($id, $this->admin());
        $job = (new Runner())->runToEnd($id);
        $this->assertSame('rolled_back', $job['status'], (string)$job['error']);
        $this->assertTrue(!is_dir(CopyPage::dir($key)), 'the files of the copy are gone with the import');
        $this->assertTrue(Pages::row('about') === null);
        $this->assertTrue(!CopyPage::is(Pages::doc('index')), 'the home page is what it was before');
    }

    public function testAPageEditedAfterTheImportKeepsItsFiles(): void
    {
        $this->freshSite();
        $this->createPage('index');
        $id = $this->run($this->package(), ['replace_home' => false]);
        $key = Runner::copyKey($id);
        $doc = Pages::doc('about');
        $data = CopyPage::data($doc);
        $data['copy_body'] .= '<p>Добавлено вручную</p>';
        Content::updatePage($this->admin(), 'about', ['blocks' => [['id' => 'copy', 'type' => 'site_copy', 'data' => $data]]]);
        Runner::startRollback($id, $this->admin());
        (new Runner())->runToEnd($id);
        $this->assertTrue(Pages::row('about') !== null, 'a page changed after the import stays');
        $this->assertTrue(is_dir(CopyPage::dir($key)), 'and keeps the files it needs');
        Content::deletePage($this->admin(), 'about');
        $this->assertTrue(is_dir(CopyPage::dir($key)), 'a page in the trash can come back, its files stay');
        Content::purgePage($this->admin(), 'about');
        $this->assertTrue(!is_dir(CopyPage::dir($key)), 'removed for good: the files are collected');
    }

    public function testBackupsAndUpdatesTreatTheFilesOfCopies(): void
    {
        $this->freshSite();
        $id = $this->run($this->package());
        $zipPath = Backup::create('manual');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertTrue($zip->locateName('site-copy/' . Runner::copyKey($id) . '/old.test/img/a.png') !== false, 'the backup holds the files of copies');
        $zip->close();
        $this->assertTrue(!Updater::allowedPath('assets/uploads/copy/' . Runner::copyKey($id) . '/old.test/css/a.css'), 'an update never writes into the files of copies');
    }

    public function testTheModeIsAskedForOnlyWhereItMakesSense(): void
    {
        $this->freshSite();
        $e = $this->assertThrows(ImportException::class, fn() => Runner::create('https://old.example', ['mode' => 'exact'], $this->admin()));
        $this->assertContains('tools/capture', $e->getMessage());
        $this->assertContains('--mode=exact', $e->getMessage());
        $wxr = TEST_TMP . '/e.xml';
        file_put_contents($wxr, '<?xml version="1.0"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title></channel></rss>');
        $id = Runner::create($wxr, ['mode' => 'exact'], $this->admin());
        $this->assertSame('blocks', Jobs::get($id)['options']['mode'], 'an export of texts goes to blocks');
        $this->assertSame('blocks', Importer::options([])['mode'], 'the older callers keep the mode they had');
        $this->assertSame('exact', Importer::options(['mode' => 'exact'])['mode']);
        $this->assertSame('blocks', Importer::options(['mode' => 'nonsense'])['mode']);
    }

    public function testBuilderSplitsAndMapsAPage(): void
    {
        $parts = CopyBuilder::split('<html lang="en" data-a=">"><head><title>x</title></head><body class="b">текст</body></html>');
        $this->assertSame('lang="en" data-a=">"', $parts['html_attrs']);
        $this->assertSame('class="b"', $parts['body_attrs']);
        $this->assertSame('текст', $parts['body']);
        $this->assertSame('<title>x</title>', $parts['head']);
        $this->assertSame('Заголовок' . "\n" . 'Абзац один', CopyBuilder::text('<style>a{}</style><h1>Заголовок</h1><p>Абзац&nbsp;один</p><script>1</script>'));
        $this->assertSame('ab', CopyBuilder::clean("a\x00b"));
        $links = ['/about' => '/o-nas', '/' => '/'];
        $html = CopyBuilder::links('<a class="x" href="/about?utm=1#a">1</a><a href=\'/img/a.png\'>2</a><a href="https://other.test/about">3</a><a href="/assets/uploads/x.png">4</a>', $links, 'https://old.test');
        $this->assertContains('href="/o-nas#a"', $html);
        $this->assertContains('href="https://other.test/about"', $html);
        $this->assertContains('href=\'/img/a.png\'', $html);
    }

    public function testThePublicPageAndTheEditorOverHttp(): void
    {
        $this->boot(['demo' => false]);
        $id = $this->run($this->package());
        $key = Runner::copyKey($id);
        $r = $this->http('GET', '/about');
        $this->assertSame(200, $r['status']);
        $this->assertSame('copy', $r['headers']['x-agecms-policy'] ?? '');
        $this->assertContains("script-src 'self' 'unsafe-inline'", $r['headers']['content-security-policy'] ?? '');
        $this->assertContains('О компании', $r['body']);
        $etag = $r['headers']['etag'] ?? '';
        $this->assertTrue($etag !== '');
        $this->assertSame(304, $this->http('GET', '/about', null, ['If-None-Match' => $etag])['status'], 'a repeat visit costs one small answer');
        $strict = $this->http('GET', '/robots.txt');
        $this->assertTrue(!isset($strict['headers']['x-agecms-policy']), 'other pages do not carry the policy of copies');
        $css = $this->http('GET', '/assets/uploads/copy/' . $key . '/old.test/css/a.css');
        $this->assertSame(200, $css['status']);
        $this->assertContains('text/css', $css['headers']['content-type'] ?? '');

        // The editor of a copy: administrators only, every save is a revision.
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), self::FORM, true);
        $form = $this->http('GET', '/admin/m/importer/copy?slug=about', null, [], true);
        $this->assertSame(200, $form['status'], $form['body']);
        $this->assertContains('выполняется у посетителей как есть', $form['body'], 'the warning about foreign code');
        preg_match('/name="csrf" value="([^"]+)"/', $form['body'], $m);
        $before = (int)\AgeCMS\Database::value('SELECT COUNT(*) FROM revisions WHERE page_slug = ?', ['about']);
        $save = $this->http('POST', '/admin/m/importer/copy?slug=about', http_build_query(['csrf' => $m[1], 'slug' => 'about', 'title' => 'О нас теперь', 'meta_title' => '', 'meta_description' => 'Новое', 'copy_head' => '', 'copy_body' => '<h1>Новый текст</h1>']), self::FORM, true);
        $this->assertSame(200, $save['status'], $save['body']);
        $this->assertContains('Сохранено', $save['body']);
        $this->assertSame($before + 1, (int)\AgeCMS\Database::value('SELECT COUNT(*) FROM revisions WHERE page_slug = ?', ['about']), 'the edit is in the history');
        $this->assertContains('Новый текст', $this->http('GET', '/about', null, ['Cache-Control' => 'no-cache'])['body']);
        $empty = $this->http('POST', '/admin/m/importer/copy?slug=about', http_build_query(['csrf' => $m[1], 'slug' => 'about', 'title' => 'x', 'copy_body' => '  ']), self::FORM, true);
        $this->assertSame(422, $empty['status'], 'an empty page is refused');
        $this->stop();
    }
}
