<?php
declare(strict_types=1);

use AgeCMS\{Media, ModuleUpload, Security, SkillRegistry};

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

/**
 * Pictures on the addresses of a module (E3): a route with "upload" takes images from a form post, the core checks them
 * before the module is called, keeps nothing unless the module saves it, and the module removes its own pictures.
 */
final class ModuleRouteUploadTest extends HttpCase
{
    private function aged(): string
    {
        $time = (string)(time() - 10);
        return $time . '.' . Security::sign('form', $time);
    }

    private function status(string $module): array
    {
        SkillRegistry::reset();
        return SkillRegistry::modules()[$module];
    }

    private function log(): string
    {
        return (string)@file_get_contents(AGECMS_DATA . '/logs/mcp_agent.json');
    }

    private static function png(int $side = 20): string
    {
        $img = imagecreatetruecolor($side, $side);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 40, 40));
        if ($side > 100) {
            for ($i = 0; $i < 3000; $i++) {
                imagesetpixel($img, random_int(0, $side - 1), random_int(0, $side - 1), imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
        ob_start();
        imagepng($img);
        return (string)ob_get_clean();
    }

    /**
     * @param array<string,string> $fields
     * @param list<array{0:string,1:string,2?:string}> $files [field name, file name, content]
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function post(string $path, array $fields, array $files, array $headers = []): array
    {
        $post = $fields;
        foreach ($files as $i => [$field, $name, $content]) {
            $tmp = TEST_TMP . '/up-' . $i . '-' . bin2hex(random_bytes(3));
            file_put_contents($tmp, $content);
            $post[$field] = new CURLFile($tmp, 'application/octet-stream', $name);
        }
        $out = [];
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$out): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $out[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $body = (string)curl_exec($ch);
        return ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $out, 'body' => $body];
    }

    private function module(string $name, array $upload = ['kinds' => ['image'], 'max_kb' => 8192, 'max_files' => 3], array $extra = []): void
    {
        ModuleFixture::make($name, [
            ['path' => "/{$name}/photo", 'methods' => ['POST'], 'upload' => $upload] + $extra,
            ['path' => "/{$name}/nophoto", 'methods' => ['POST']],
            ['path' => "/{$name}/form", 'methods' => ['GET', 'POST']],
        ]);
    }

    private function calls(): int
    {
        return strlen((string)@file_get_contents(AGECMS_DATA . '/called.txt'));
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        $found = [];
        foreach ([AGECMS_UPLOADS . '/m', AGECMS_DATA . '/module_files'] as $dir) {
            if (is_dir($dir)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                    $found[] = (string)$file;
                }
            }
        }
        return $found;
    }

    /** @return list<string> */
    private function phpTemp(): array
    {
        return glob(sys_get_temp_dir() . '/php*') ?: [];
    }

    // The picture arrives, goes to the folder of the module, is served and is removed by the module.

    public function testAPictureGoesToTheFolderOfTheModuleAndTheModuleRemovesIt(): void
    {
        $this->boot();
        $this->module('ru_ok');

        $r = $this->post('/ru_ok/photo', ['token' => $this->aged(), 'media' => '1', 'keep' => '1'], [['f[]', 'cat.png', self::png()]]);
        $this->assertSame(200, $r['status'], $r['body']);
        $data = json_decode($r['body'], true);
        $this->assertSame(1, count($data['files']));
        $file = $data['files'][0];
        $this->assertSame('cat.png', $file['name']);
        $this->assertSame('image', $file['kind']);
        $this->assertSame('image/png', $file['mime']);
        $this->assertSame(['media', 'keep'], $data['body'], 'the module never sees the token fields');
        $this->assertTrue(preg_match('~^/assets/uploads/m/ru_ok/[a-f0-9]{20}\.webp$~', (string)$file['media']) === 1, (string)$file['media']);
        $this->assertTrue(is_file(Media::dir() . substr($file['media'], strlen('/assets/uploads'))), 'the picture is in the media folder');
        $this->assertTrue(is_file((string)ModuleUpload::stored('ru_ok', (string)$file['stored'])), 'store() keeps a private copy too');
        $this->assertSame([], array_values(array_filter(Media::items(), static fn(array $i): bool => str_contains($i['url'], '/m/'))), 'the editor library does not list pictures of modules');

        $served = $this->http('GET', $file['media']);
        $this->assertSame(200, $served['status']);
        $this->assertSame('image/webp', $served['headers']['content-type']);

        // The same picture twice is two files: one owner removing theirs does not break the other.
        $again = json_decode($this->post('/ru_ok/photo', ['token' => $this->aged(), 'media' => '1'], [['f[]', 'cat.png', self::png()]])['body'], true);
        $this->assertTrue($again['files'][0]['media'] !== $file['media'], 'a random name for every picture');

        $this->assertSame(false, ModuleUpload::deleteMedia('other', $file['media']), 'another module cannot remove it');
        $this->assertSame(false, ModuleUpload::deleteMedia('ru_ok', '/assets/uploads/m/ru_ok/../../' . basename($file['media'])), 'no path tricks');
        $this->assertSame(false, ModuleUpload::deleteMedia('ru_ok', '/assets/uploads/' . basename($file['media'])), 'only the folder of the module');
        $this->assertSame(true, ModuleUpload::deleteMedia('ru_ok', $file['media']));
        $this->assertTrue(!is_file(Media::dir() . substr($file['media'], strlen('/assets/uploads'))), 'the picture is gone');
        $this->assertSame(false, ModuleUpload::deleteMedia('ru_ok', $file['media']), 'a second removal finds nothing');
        $this->assertTrue(is_file(Media::dir() . substr($again['files'][0]['media'], strlen('/assets/uploads'))), 'the other picture stays');
        ModuleUpload::delete('ru_ok', (string)$file['stored']);
        $this->assertSame(null, ModuleUpload::stored('ru_ok', (string)$file['stored']));
    }

    // 2. What is not a picture never reaches the module.

    public function testAScriptNamedAsAPictureIsRefusedBeforeTheModuleRuns(): void
    {
        $this->boot();
        $this->module('ru_bad');
        $before = $this->calls();

        $r = $this->post('/ru_bad/photo', ['token' => $this->aged(), 'media' => '1'], [['f[]', 'a.png', '<?php echo 1;']]);
        $this->assertSame(400, $r['status']);
        $this->assertContains('Данные не приняты', $r['body'], 'the page of the theme');
        $this->assertContains('такой тип файла не принимается', $r['body']);
        $this->assertSame($before, $this->calls(), 'the module was not called');
        $this->assertSame([], $this->leftovers());

        $pdf = $this->post('/ru_bad/photo', ['token' => $this->aged()], [['f[]', 'a.png', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"]]);
        $this->assertSame(400, $pdf['status'], 'a PDF is not a picture on an address');

        // A file that looks like a picture to the type check but cannot be decoded: a refusal, not an error of the module.
        $broken = $this->post('/ru_bad/photo', ['token' => $this->aged(), 'media' => '1'], [['f[]', 'a.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64)]]);
        $this->assertSame(400, $broken['status'], $broken['body']);
        $this->assertContains('Данные не приняты', $broken['body']);
        $this->assertNotContains('"files"', $broken['body']);
    }

    // 3. Limits of the address.

    public function testSizeAndNumberLimitsGiveAClearMessage(): void
    {
        $this->boot();
        $this->module('ru_lim', ['kinds' => ['image'], 'max_kb' => 1, 'max_files' => 2]);
        $big = $this->post('/ru_lim/photo', ['token' => $this->aged()], [['f[]', 'big.png', self::png(200)]]);
        $this->assertSame(400, $big['status']);
        $this->assertContains('big.png: больше 1 КБ', $big['body']);

        $this->module('ru_num', ['kinds' => ['image'], 'max_kb' => 800, 'max_files' => 2]);
        $many = $this->post('/ru_num/photo', ['token' => $this->aged()], [['a', 'a.png', self::png()], ['b', 'b.png', self::png()], ['c', 'c.png', self::png()]]);
        $this->assertSame(400, $many['status']);
        $this->assertContains('Файлов не больше 2', $many['body']);
        $two = $this->post('/ru_num/photo', ['token' => $this->aged()], [['a', 'a.png', self::png()], ['b', 'b.png', self::png()]]);
        $this->assertSame(200, $two['status']);
        $this->assertSame(2, count(json_decode($two['body'], true)['files']));
    }

    // 4 and 5. The manifest decides, wrong declarations block the module.

    public function testWrongUploadDeclarationsBlockTheModule(): void
    {
        $this->freshSite();
        $cases = [
            'ru_pdf' => [['path' => '/ru-pdf', 'methods' => ['POST'], 'upload' => ['kinds' => ['pdf']]], 'takes only images'],
            'ru_mix' => [['path' => '/ru-mix', 'methods' => ['POST'], 'upload' => ['kinds' => ['image', 'csv']]], 'takes only images'],
            'ru_xml' => [['path' => '/ru-xml', 'methods' => ['POST'], 'upload' => ['kinds' => ['xml', 'zip'], 'max_kb' => 50000]], 'takes only images'],
            'ru_nok' => [['path' => '/ru-nok', 'methods' => ['POST'], 'upload' => ['kinds' => []]], 'takes only images'],
            'ru_pub' => [['path' => '/ru-pub', 'methods' => ['POST'], 'cache' => 'public', 'upload' => ['kinds' => ['image']]], 'a public route is GET only'],
            'ru_get' => [['path' => '/ru-get', 'methods' => ['GET'], 'upload' => ['kinds' => ['image']]], 'POST address and cache private'],
            'ru_big' => [['path' => '/ru-big', 'methods' => ['POST'], 'upload' => ['kinds' => ['image'], 'max_kb' => ModuleUpload::SMALL_MAX_KB + 1]], 'takes only images'],
            'ru_many' => [['path' => '/ru-many', 'methods' => ['POST'], 'upload' => ['kinds' => ['image'], 'max_files' => 11]], 'takes only images'],
            'ru_key' => [['path' => '/ru-key', 'methods' => ['POST'], 'upload' => ['kinds' => ['image'], 'dir' => '/etc']], 'takes only images'],
            'ru_str' => [['path' => '/ru-str', 'methods' => ['POST'], 'upload' => 'image'], 'takes only images'],
        ];
        foreach ($cases as $name => [$route, $reason]) {
            ModuleFixture::make($name, [$route]);
            $info = $this->status($name);
            $this->assertSame('blocked', $info['status'], $name);
            $this->assertContains($reason, (string)$info['reason'], $name);
        }
        $this->assertContains('takes only images', $this->log(), 'the reason is logged');

        ModuleFixture::make('ru_fine', [['path' => '/ru-fine', 'methods' => ['GET', 'POST'], 'upload' => ['kinds' => ['image']]]]);
        $this->assertSame('ok', $this->status('ru_fine')['status'], 'GET and POST with a picture, defaults for the rest');
    }

    public function testAnAddressWithoutUploadDropsTheFileAndTheModuleSeesNone(): void
    {
        $this->boot();
        $this->module('ru_no');
        $r = $this->post('/ru_no/nophoto', ['token' => $this->aged()], [['f[]', 'cat.png', self::png()]]);
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame([], json_decode($r['body'], true)['files'], 'files stay empty');
        $this->assertSame([], $this->leftovers(), 'nothing is stored');
    }

    // 6. A module that does not save leaves nothing on the disk.

    public function testNothingStaysWhenTheModuleDoesNotSave(): void
    {
        $this->boot();
        $this->module('ru_keep');
        $temp = $this->phpTemp();
        $r = $this->post('/ru_keep/photo', ['token' => $this->aged()], [['f[]', 'cat.png', self::png()]]);
        $this->assertSame(200, $r['status']);
        $this->assertSame(1, count(json_decode($r['body'], true)['files']), 'the module saw the checked file');
        $this->assertSame([], $this->leftovers(), 'no copy in the media folder and none in data/module_files');
        $this->assertSame($temp, $this->phpTemp(), 'the temporary file of PHP is removed');

        $bad = $this->post('/ru_keep/photo', ['token' => $this->aged()], [['f[]', 'a.png', '<?php']]);
        $this->assertSame(400, $bad['status']);
        $this->assertSame($temp, $this->phpTemp(), 'and after a refusal too');
        $this->assertSame([], $this->leftovers());
    }

    // 7. The form token still protects a page address; only the limits of the address and its rate are new.

    public function testThePageAddressKeepsItsFormTokenSameSiteAndRateLimit(): void
    {
        $this->boot();
        $this->module('ru_tok', ['kinds' => ['image'], 'max_kb' => 800, 'max_files' => 1]);
        $file = [['f[]', 'cat.png', self::png()]];
        $this->assertSame(400, $this->post('/ru_tok/photo', [], $file)['status'], 'no token');
        $this->assertContains('Форма устарела', $this->post('/ru_tok/photo', ['token' => 'garbage'], $file)['body']);
        $this->assertSame(403, $this->post('/ru_tok/photo', ['token' => $this->aged()], $file, ['Origin' => 'https://evil.example'])['status'], 'foreign origin');
        $this->assertSame(0, $this->calls(), 'the module was never called');

        // 3 requests used so far; the default limit of an address with upload is 10 a minute from one address.
        for ($i = 0; $i < 7; $i++) {
            $this->assertSame(400, $this->post('/ru_tok/photo', [], $file)['status']);
        }
        $limited = $this->post('/ru_tok/photo', ['token' => $this->aged()], $file);
        $this->assertSame(429, $limited['status'], 'eleventh request in a minute');
        $this->assertSame('60', $limited['headers']['retry-after']);
        $this->assertContains('Слишком много запросов', $limited['body']);
    }

    public function testTheRateOfAnUploadAddressCanBeChanged(): void
    {
        $this->boot();
        $this->module('ru_rate', ['kinds' => ['image']], ['rate' => ['limit' => 2, 'window' => 60]]);
        $file = [['f[]', 'cat.png', self::png()]];
        $this->assertSame(200, $this->post('/ru_rate/photo', ['token' => $this->aged()], $file)['status']);
        $this->assertSame(200, $this->post('/ru_rate/photo', ['token' => $this->aged()], $file)['status']);
        $this->assertSame(429, $this->post('/ru_rate/photo', ['token' => $this->aged()], $file)['status']);
    }

    // 8. A file the whole request of which is over the limit of PHP is not "a stale form".

    public function testARequestOverTheServerLimitSaysSo(): void
    {
        $this->boot();
        $this->module('ru_php');
        $server = ModuleUpload::serverMaxBytes();
        if ($server <= 0 || $server > 64 * 1048576) {
            return; // the limit of this PHP is too high to cross in a test
        }
        $r = $this->post('/ru_php/photo', ['token' => $this->aged()], [['f[]', 'huge.png', str_repeat('a', $server + 1024)]]);
        $this->assertSame(400, $r['status']);
        $this->assertContains('больше, чем разрешает сервер', $r['body']);
        $this->assertNotContains('Форма устарела', $r['body']);
    }

    public function testTheHintForAFieldKnowsTheLimitOfAnAddress(): void
    {
        $this->freshSite();
        $this->module('ru_hint', ['kinds' => ['image'], 'max_kb' => 300, 'max_files' => 1]);
        SkillRegistry::reset();
        $server = ModuleUpload::serverMaxBytes();
        $this->assertSame($server > 0 ? min(300 * 1024, $server) : 300 * 1024, ModuleUpload::maxBytes('ru_hint', '/ru_hint/photo'));
        $this->assertSame(0, ModuleUpload::maxBytes('ru_hint', '/ru_hint/nophoto'));
    }
}
