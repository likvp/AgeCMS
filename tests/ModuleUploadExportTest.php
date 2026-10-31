<?php
declare(strict_types=1);

use AgeCMS\{AgeCMSException, ModuleAdmin, ModuleUpload, SkillRegistry};

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

/**
 * Export files that an admin page of a module takes (kinds xml and zip): judged by the signature, not by the name; a page may
 * allow up to 512 MB and takes 50 MB unless it says otherwise; the server limit lowers that; the file stays under data/.
 */
final class ModuleUploadExportTest extends HttpCase
{
    private const FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];

    private function login(): string
    {
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), self::FORM, true);
        preg_match('/name="csrf" value="([^"]+)"/', $this->http('GET', '/admin/', null, [], true)['body'], $m);
        return $m[1] ?? '';
    }

    /** @param array<string,array{0:string,1:string}> $files */
    private function multipart(string $path, array $fields, string $csrf, array $files): array
    {
        $post = ['csrf' => $csrf] + $fields;
        foreach ($files as $key => [$name, $content]) {
            $tmp = TEST_TMP . '/up-' . bin2hex(random_bytes(3));
            file_put_contents($tmp, $content);
            $post[$key] = new CURLFile($tmp, 'application/octet-stream', $name);
        }
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 15]);
        $body = (string)curl_exec($ch);
        return ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body];
    }

    /** @return list<array<string,mixed>> */
    private function pages(?int $maxKb = null): array
    {
        $upload = ['kinds' => ['xml', 'zip'], 'max_files' => 1] + ($maxKb !== null ? ['max_kb' => $maxKb] : []);
        return [
            ['path' => '/', 'title' => 'Импорт', 'permission' => 'system.manage'],
            ['path' => '/upload', 'title' => 'Принять', 'permission' => 'system.manage', 'methods' => ['POST'], 'menu' => false, 'upload' => $upload],
        ];
    }

    private static function zip(): string
    {
        $file = TEST_TMP . '/a-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE);
        $zip->addFromString('page1.html', '<html><body><h1>Tilda</h1></body></html>');
        $zip->close();
        return (string)file_get_contents($file);
    }

    private const WXR = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Тест</title></channel></rss>';

    public function testXmlAndZipAreTakenByTheirSignatureAndKeptPrivately(): void
    {
        $this->boot();
        ModuleFixture::make('ad_exp', [], [], [], true, null, $this->pages());
        $csrf = $this->login();
        $post = fn(string $name, string $content, array $fields = ['keep' => '1']) => $this->multipart('/admin/m/ad_exp/upload', $fields, $csrf, ['f' => [$name, $content]]);

        $ok = $post('export.xml', self::WXR);
        $this->assertSame(200, $ok['status'], $ok['body']);
        $file = json_decode($ok['body'], true)['files'][0];
        $this->assertSame(['export.xml', 'xml', 'application/xml'], [$file['name'], $file['kind'], $file['mime']]);
        $this->assertTrue(str_ends_with((string)$file['stored'], '.xml'));
        $path = (string)ModuleUpload::stored('ad_exp', (string)$file['stored']);
        $this->assertContains('/data/module_files/ad_exp/', $path);
        $this->assertSame(self::WXR, (string)file_get_contents($path));

        $bom = $post('wp.xml', "\xEF\xBB\xBF\n  <rss version=\"2.0\"><channel></channel></rss>");
        $this->assertSame('xml', json_decode($bom['body'], true)['files'][0]['kind'] ?? null, 'a byte order mark and white space before <rss: ' . $bom['body']);

        if (class_exists(ZipArchive::class)) {
            $zip = $post('project.zip', self::zip());
            $this->assertSame(200, $zip['status'], $zip['body']);
            $z = json_decode($zip['body'], true)['files'][0];
            $this->assertSame(['zip', 'application/zip'], [$z['kind'], $z['mime']]);
            $this->assertTrue(str_ends_with((string)$z['stored'], '.zip'));
            $named = $post('export.xml', self::zip());
            $this->assertSame('zip', json_decode($named['body'], true)['files'][0]['kind'] ?? null, 'the name does not matter');
        }

        $cases = [
            'html named xml' => ['site.xml', '<html><body>x</body></html>', 'не принимается'],
            'a script named zip' => ['a.zip', "<?php system('id');", 'не принимается'],
            'a script after xml text' => ['a.xml', "<?php echo 1; ?>\n<?xml version=\"1.0\"?>", 'не принимается'],
            'utf-16 xml' => ['a.xml', "\xFF\xFE<\0?\0x\0m\0l\0", 'не принимается'],
            'csv is not allowed here' => ['a.csv', "a;b\n1;2\n", 'не принимается'],
        ];
        if (class_exists(ZipArchive::class)) {
            $cases['broken zip'] = ['b.zip', "PK\x03\x04" . str_repeat('x', 200), 'архив повреждён'];
        }
        foreach ($cases as $label => [$name, $content, $message]) {
            $r = $post($name, $content);
            $this->assertSame(400, $r['status'], $label . ': ' . substr($r['body'], 0, 200));
            $this->assertContains($message, $r['body'], $label);
        }
        $this->assertSame(2 + (class_exists(ZipArchive::class) ? 2 : 0), count(glob(AGECMS_DATA . '/module_files/ad_exp/*') ?: []), 'refused files are not stored');
        foreach (glob(AGECMS_UPLOADS . '/*') ?: [] as $public) {
            $this->assertTrue(preg_match('~\.(xml|zip)$~', $public) !== 1, 'nothing in the public uploads: ' . $public);
        }
    }

    public function testSizeLimitsOfExportPages(): void
    {
        $this->boot();
        ModuleFixture::make('ad_exs', [], [], [], true, null, $this->pages(1));
        ModuleFixture::make('ad_exd', [], [], [], true, null, $this->pages());
        $csrf = $this->login();
        $big = $this->multipart('/admin/m/ad_exs/upload', [], $csrf, ['f' => ['big.xml', '<?xml version="1.0"?><rss>' . str_repeat('x', 2000) . '</rss>']]);
        $this->assertSame(400, $big['status']);
        $this->assertContains('больше 1 КБ', $big['body']);

        SkillRegistry::reset();
        $default = array_values(array_filter(ModuleAdmin::all(), static fn(array $p): bool => $p['module'] === 'ad_exd' && $p['upload'] !== null))[0];
        $this->assertSame(ModuleUpload::EXPORT_DEFAULT_KB, $default['upload']['max_kb'], 'an export page takes 50 MB unless it says otherwise');
        $server = ModuleUpload::serverMaxBytes();
        $this->assertSame($server > 0 ? min(51200 * 1024, $server) : 51200 * 1024, ModuleUpload::maxBytes('ad_exd', '/upload'), 'the server limit lowers the page limit');
        $this->assertSame(0, ModuleUpload::maxBytes('ad_exd', '/'), 'a page without files');
        $this->assertSame(0, ModuleUpload::maxBytes('nobody', '/upload'));

        $this->assertSame([8388608, 524288, 1073741824, 0, 0, 100], [ModuleUpload::iniBytes('8M'), ModuleUpload::iniBytes('512k'), ModuleUpload::iniBytes('1G'), ModuleUpload::iniBytes('-1'), ModuleUpload::iniBytes(''), ModuleUpload::iniBytes('100')]);
        $this->assertSame(['50 МБ', '1,5 МБ', '300 КБ'], [ModuleUpload::sizeText(52428800), ModuleUpload::sizeText(1572864), ModuleUpload::sizeText(307200)]);

        // Bounds of the declaration: export kinds up to 512 MB, the others keep 10 MB, also next to an export kind.
        $page = static fn(array $u): array => [['path' => '/u', 'title' => 'U', 'permission' => 'system.manage', 'methods' => ['POST'], 'upload' => $u]];
        $this->assertSame(204800, ModuleAdmin::parse('x', ['admin' => $page(['kinds' => ['zip'], 'max_kb' => 204800])])[0]['upload']['max_kb']);
        $this->assertSame(20000, ModuleAdmin::parse('x', ['admin' => $page(['kinds' => ['image', 'xml'], 'max_kb' => 20000])])[0]['upload']['max_kb']);
        $this->assertSame(2048, ModuleAdmin::parse('x', ['admin' => $page(['kinds' => ['csv']])])[0]['upload']['max_kb'], 'other pages keep their default');
        $this->assertThrows(AgeCMSException::class, fn() => ModuleAdmin::parse('x', ['admin' => $page(['kinds' => ['xml'], 'max_kb' => ModuleUpload::EXPORT_MAX_KB + 1])]));
        $this->assertThrows(AgeCMSException::class, fn() => ModuleAdmin::parse('x', ['admin' => $page(['kinds' => ['pdf'], 'max_kb' => 20000])]));
    }

    public function testAnExportFileNeverGoesToTheMediaLibrary(): void
    {
        $this->freshSite();
        $tmp = TEST_TMP . '/export.xml';
        file_put_contents($tmp, self::WXR);
        $upload = new ModuleUpload('ad_x', 'export.xml', 'xml', 'application/xml', strlen(self::WXR), $tmp);
        $this->assertThrows(AgeCMSException::class, fn() => $upload->toMedia(), 'Only an image');
        $this->assertSame(null, ModuleUpload::stored('ad_x', str_repeat('a', 24) . '.php'));
        $name = $upload->store();
        $this->assertTrue(preg_match('/^[a-f0-9]{24}\.xml$/', $name) === 1, $name);
        $this->assertSame(AGECMS_DATA . '/module_files/ad_x/' . $name, ModuleUpload::stored('ad_x', $name));
        $this->assertSame('600', substr(sprintf('%o', fileperms((string)ModuleUpload::stored('ad_x', $name))), -3));
    }
}
