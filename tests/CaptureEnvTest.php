<?php
declare(strict_types=1);

use AgeCMS\{CaptureEnv, Installer, ServerSetup};

/** Node.js and Chrome checks for the importer's "exact copy" mode, and the pieces that install them. The machine is replaced by fake folders. */
final class CaptureEnvTest extends TestCase
{
    private string $dir = '';

    private function sandbox(): string
    {
        if ($this->dir === '') {
            $this->dir = sys_get_temp_dir() . '/agecms-capenv-' . bin2hex(random_bytes(4));
            mkdir($this->dir . '/bin', 0755, true);
        }
        return $this->dir;
    }

    private function fake(string $name, string $body = "#!/bin/sh\necho v22.1.0\n", int $mode = 0755): string
    {
        $path = $this->sandbox() . '/bin/' . $name;
        file_put_contents($path, $body);
        chmod($path, $mode);
        return $path;
    }

    public function __destruct()
    {
        if ($this->dir !== '') {
            TestEnv::remove($this->dir);
        }
    }

    /** @return array<string,mixed> a machine without Node.js and Chrome */
    private function bare(array $extra = []): array
    {
        return array_replace(['config' => ['node' => '', 'chrome' => ''], 'env' => [], 'path' => $this->sandbox() . '/empty', 'node_dirs' => [], 'browser_paths' => [], 'browser_globs' => [], 'fpm_files' => [], 'exec' => true, 'euid' => 1000], $extra);
    }

    public function testNodeIsFoundInTheConfigThenTheEnvironmentThenPath(): void
    {
        $node = $this->fake('node');
        $v = static fn(string $p): string => 'v22.1.0';
        $this->assertSame(null, CaptureEnv::node($this->bare())['path']);
        $this->assertSame($node, CaptureEnv::node($this->bare(['config' => ['node' => $node, 'chrome' => ''], 'version' => $v]))['path']);
        $this->assertSame($node, CaptureEnv::node($this->bare(['env' => ['AGECMS_NODE' => $node], 'version' => $v]))['path']);
        $found = CaptureEnv::node($this->bare(['path' => '/nonexistent:' . dirname($node), 'version' => $v]));
        $this->assertSame($node, $found['path']);
        $this->assertSame('22.1.0', $found['version']);
        $this->assertSame('PATH', $found['source']);
        $this->assertSame($node, CaptureEnv::node($this->bare(['node_dirs' => [dirname($node)], 'version' => $v]))['path']);
    }

    public function testNodeOlderThanTwentyIsRefusedAndTheNextOneIsTried(): void
    {
        $old = $this->fake('node', "#!/bin/sh\necho v18.19.0\n");
        $news = $this->sandbox() . '/new';
        mkdir($news);
        file_put_contents($news . '/node', "#!/bin/sh\necho v24.0.0\n");
        chmod($news . '/node', 0755);
        $version = static fn(string $p): string => str_contains($p, '/new/') ? 'v24.0.0' : 'v18.19.0';
        $this->assertSame(null, CaptureEnv::node($this->bare(['path' => dirname($old), 'version' => $version]))['path']);
        $this->assertContains('18.19.0', CaptureEnv::node($this->bare(['path' => dirname($old), 'version' => $version]))['problem']);
        $this->assertSame($news . '/node', CaptureEnv::node($this->bare(['path' => dirname($old) . ':' . $news, 'version' => $version]))['path']);
    }

    public function testAWrongPathInTheConfigIsNotHiddenByANodeOnPath(): void
    {
        $node = $this->fake('node');
        $answer = CaptureEnv::node($this->bare(['config' => ['node' => '/no/such/node', 'chrome' => ''], 'path' => dirname($node), 'version' => static fn(string $p): string => 'v22.1.0']));
        $this->assertSame(null, $answer['path']);
        $this->assertContains('capture.node', $answer['problem']);
        $answer = CaptureEnv::browser($this->bare(['config' => ['node' => '', 'chrome' => '/no/such/chrome'], 'path' => dirname($node)]));
        $this->assertSame(null, $answer['path']);
        $this->assertContains('capture.chrome', $answer['problem']);
    }

    public function testTheVersionIsReadFromTheProgramItselfWhenProgramsMayStart(): void
    {
        $node = $this->fake('node', "#!/bin/sh\necho v23.4.5\n");
        $this->assertSame('23.4.5', CaptureEnv::node($this->bare(['config' => ['node' => $node, 'chrome' => '']]))['version']);
        $broken = $this->fake('node-broken', "#!/bin/sh\necho nonsense\n");
        $this->assertSame(null, CaptureEnv::node($this->bare(['config' => ['node' => $broken, 'chrome' => '']]))['version']);
        // Programs may not start: the file is there, the version is unknown, the check still passes.
        $this->assertSame($node, CaptureEnv::node($this->bare(['config' => ['node' => $node, 'chrome' => ''], 'exec' => false]))['path']);
    }

    public function testBrowserIsFoundInTheUsualPlacesAndASnapStubIsRefused(): void
    {
        $chrome = $this->fake('google-chrome-stable', "#!/bin/sh\nexit 0\n");
        $this->assertSame(null, CaptureEnv::browser($this->bare())['path']);
        $this->assertSame($chrome, CaptureEnv::browser($this->bare(['browser_paths' => ['/nope', $chrome]]))['path']);
        $this->assertSame($chrome, CaptureEnv::browser($this->bare(['env' => ['AGECMS_CHROME' => $chrome]]))['path']);
        $this->assertSame($chrome, CaptureEnv::browser($this->bare(['env' => ['CHROME_PATH' => $chrome]]))['path']);
        $this->assertSame($chrome, CaptureEnv::browser($this->bare(['path' => dirname($chrome)]))['path']);

        mkdir($this->sandbox() . '/browsers/chromium-1234/chrome-linux64', 0755, true);
        $pw = $this->sandbox() . '/browsers/chromium-1234/chrome-linux64/chrome';
        file_put_contents($pw, "#!/bin/sh\nexit 0\n");
        chmod($pw, 0755);
        $this->assertSame($pw, CaptureEnv::browser($this->bare(['browser_globs' => [$this->sandbox() . '/browsers/chromium-*/chrome-linux*/chrome']]))['path']);

        $snap = $this->fake('chromium-browser', "#!/bin/sh\n# This is a stub: exec /snap/bin/chromium \"\$@\"\n");
        $answer = CaptureEnv::browser($this->bare(['browser_paths' => [$snap]]));
        $this->assertSame(null, $answer['path']);
        $this->assertContains('snap', $answer['problem']);
    }

    public function testRowsAreRecommendedChecksWithAFixAndSayWhatIsLost(): void
    {
        $rows = CaptureEnv::rows($this->bare());
        $this->assertSame([CaptureEnv::LABEL_EXEC, CaptureEnv::LABEL_NODE, CaptureEnv::LABEL_BROWSER], array_column($rows, 'label'));
        foreach ($rows as $row) {
            $this->assertSame('recommended', $row['level']);
            $this->assertSame(false, $row['required']);
            $this->assertTrue($row['fix'] !== '', $row['label']);
        }
        $this->assertSame(true, $rows[0]['ok']);
        $this->assertSame(false, $rows[1]['ok']);
        $this->assertSame(false, $rows[2]['ok']);
        foreach ([$rows[1], $rows[2]] as $row) {
            $this->assertContains('Точная копия', $row['note']);
            $this->assertContains('загрузка готового пакета', $row['note']);
            $this->assertContains('install-capture.sh', $row['fix']);
        }
    }

    public function testRowsPassWhenBothProgramsAreThere(): void
    {
        $node = $this->fake('node');
        $chrome = $this->fake('chrome', "#!/bin/sh\nexit 0\n");
        $rows = CaptureEnv::rows($this->bare(['config' => ['node' => $node, 'chrome' => $chrome], 'version' => static fn(string $p): string => 'v22.1.0']));
        $this->assertSame([true, true, true], array_column($rows, 'ok'));
        $this->assertContains('22.1.0', $rows[1]['note']);
        $this->assertContains($chrome, $rows[2]['note']);
    }

    public function testProcOpenOffInThePhpOfTheWebServerIsReported(): void
    {
        $this->assertSame(false, CaptureEnv::rows($this->bare(['exec' => false]))[0]['ok']);

        $ini = $this->sandbox() . '/fpm-php.ini';
        $pool = $this->sandbox() . '/www.conf';
        $commented = $this->sandbox() . '/commented.ini';
        file_put_contents($ini, "memory_limit = 128M\ndisable_functions = pcntl_alarm,proc_open,exec\n");
        file_put_contents($pool, "[www]\nphp_admin_value[disable_functions] = \"shell_exec, proc_open\"\n");
        file_put_contents($commented, ";disable_functions = proc_open\ndisable_functions = pcntl_fork,proc_open_not_really\n");
        $this->assertSame([$ini], CaptureEnv::fpmDisables(['fpm_files' => [$ini, $commented]]));
        $this->assertSame([$pool], CaptureEnv::fpmDisables(['fpm_files' => [$pool, $commented]]));
        $this->assertSame([], CaptureEnv::fpmDisables(['fpm_files' => [$commented, '/no/such/file']]));

        $row = CaptureEnv::rows($this->bare(['fpm_files' => [$ini]]))[0];
        $this->assertSame(false, $row['ok']);
        $this->assertContains('PHP-FPM', $row['note']);
        $this->assertContains($ini, $row['note']);
        $this->assertSame(true, CaptureEnv::rows($this->bare(['fpm_files' => [$commented]]))[0]['ok']);
    }

    public function testAProgramThePhpUserCannotReachIsNotCountedAsThere(): void
    {
        mkdir($this->sandbox() . '/private/bin', 0755, true);
        $node = $this->sandbox() . '/private/bin/node';
        file_put_contents($node, "#!/bin/sh\necho v22.1.0\n");
        chmod($node, 0755);
        chmod($this->sandbox() . '/private', 0700);
        $options = $this->bare(['config' => ['node' => $node, 'chrome' => ''], 'version' => static fn(string $p): string => 'v22.1.0']);
        // As an ordinary user the console sees what PHP sees; as root it sees more, so the folder rights are read.
        $this->assertSame(true, CaptureEnv::rows($options)[1]['ok']);
        $rootRow = CaptureEnv::rows(array_replace($options, ['euid' => 0]))[1];
        $this->assertSame(false, $rootRow['ok']);
        $this->assertContains('PHP-FPM', $rootRow['note']);
    }

    public function testTheInstallerChecksCarryTheNewRowsAndTheAdminCanInstallThem(): void
    {
        $labels = array_column(Installer::checks(), 'label');
        foreach ([CaptureEnv::LABEL_EXEC, CaptureEnv::LABEL_NODE, CaptureEnv::LABEL_BROWSER] as $label) {
            $this->assertTrue(in_array($label, $labels, true), $label);
        }
        $this->assertSame(true, ServerSetup::installable(CaptureEnv::LABEL_NODE));
        $this->assertSame(true, ServerSetup::installable(CaptureEnv::LABEL_BROWSER));
        $this->assertSame(false, ServerSetup::installable(CaptureEnv::LABEL_EXEC), 'disable_functions is a php.ini matter');
        // Node.js and Chrome never make the installation fail.
        foreach (Installer::checks() as $c) {
            if (in_array($c['label'], [CaptureEnv::LABEL_EXEC, CaptureEnv::LABEL_NODE, CaptureEnv::LABEL_BROWSER], true)) {
                $this->assertSame('recommended', $c['level']);
            }
        }
    }

    public function testTheBackgroundInstallationIsAnArgumentListWithTheStatusFileNotAShellLine(): void
    {
        $this->assertSame(['bash', '/var/www/x/bin/install-capture.sh', '--dir=/var/www/x', '--status-file=/var/www/x/data/logs/s'], ServerSetup::captureCommand(false, '/var/www/x', '/var/www/x/data/logs/s'));
        $this->assertSame(['sudo', '-n', 'bash'], array_slice(ServerSetup::captureCommand(true, '/var/www/x', '/s'), 0, 3));
    }

    public function testStatusFileTellsTheAdminHowTheInstallationWent(): void
    {
        $file = $this->sandbox() . '/status';
        $this->assertSame(null, ServerSetup::captureStatus($file));
        file_put_contents($file, "ready\t" . time() . "\tNode.js v24, Chrome\n");
        $this->assertSame('ready', ServerSetup::captureStatus($file)['state']);
        $this->assertSame('Node.js v24, Chrome', ServerSetup::captureStatus($file)['message']);
        file_put_contents($file, "running\t" . time() . "\tидёт установка\n");
        $this->assertSame('running', ServerSetup::captureStatus($file)['state']);
        file_put_contents($file, "running\t" . (time() - 7200) . "\tидёт установка\n");
        $this->assertSame('failed', ServerSetup::captureStatus($file)['state'], 'a killed installer does not stay "running" for ever');
        file_put_contents($file, "<?php evil\n");
        $this->assertSame(null, ServerSetup::captureStatus($file));
    }

    public function testManualTextForSupportNamesNodeChromeAndTheScript(): void
    {
        $missing = [
            ['label' => CaptureEnv::LABEL_NODE, 'ok' => false, 'level' => 'recommended', 'note' => '', 'fix' => ''],
            ['label' => CaptureEnv::LABEL_BROWSER, 'ok' => false, 'level' => 'recommended', 'note' => '', 'fix' => ''],
        ];
        $text = ServerSetup::manualText($missing, 'https://example.test');
        foreach (['install-capture.sh', 'Node.js 20+', 'Chrome', 'proc_open', 'capture.node', 'https://example.test', 'загрузить файлом'] as $needle) {
            $this->assertContains($needle, $text, $needle);
        }
        $this->assertNotContains('docker-php-ext-install', $text, 'no PHP extension is missing');
        $this->assertNotContains('apt-get install -y -q', $text);
    }

    public function testTheInstallScriptsAreValidShellAndKnowTheirOptions(): void
    {
        $root = dirname(__DIR__);
        $bash = trim((string)shell_exec('command -v bash 2>/dev/null'));
        $this->assertTrue($bash !== '', 'bash');
        foreach (['install.sh', 'bin/install-capture.sh'] as $script) {
            exec(escapeshellarg($bash) . ' -n ' . escapeshellarg($root . '/' . $script) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, $script . ' ' . implode(' ', $out));
        }
        $installer = (string)file_get_contents($root . '/install.sh');
        foreach (['--no-capture', 'install-capture.sh', '--configure-only'] as $needle) {
            $this->assertContains($needle, $installer, $needle);
        }
        $capture = (string)file_get_contents($root . '/bin/install-capture.sh');
        foreach (['/etc/apt/keyrings', 'deb.nodesource.com', 'dl.google.com/linux/chrome/deb', 'fonts-noto-color-emoji', 'fonts-liberation', 'fonts-dejavu-core', 'npm', 'CAPTURE_RESULT=', 'self-test', 'capture.node', 'capture.chrome'] as $needle) {
            $this->assertContains($needle, $capture, $needle);
        }
        $this->assertNotContains('| bash', $capture, 'no curl | bash inside the installer');
        $this->assertNotContains('--no-sandbox', $capture);
        $this->assertNotContains(" \u{2014} ",$installer . $capture, 'no long dashes');
    }

    public function testTheCaptureScriptRefusesToRunWithoutRootAndSaysSo(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return;
        }
        $out = [];
        $status = $this->sandbox() . '/script-status';
        exec('bash ' . escapeshellarg(dirname(__DIR__) . '/bin/install-capture.sh') . ' --status-file=' . escapeshellarg($status) . ' 2>&1', $out, $code);
        $this->assertSame(1, $code);
        $this->assertContains('CAPTURE_RESULT=failed', implode("\n", $out));
        $this->assertContains('root', implode("\n", $out));
        $this->assertContains("failed\t", (string)file_get_contents($status));
        exec('bash ' . escapeshellarg(dirname(__DIR__) . '/bin/install-capture.sh') . ' --bogus 2>&1', $out2, $code2);
        $this->assertSame(1, $code2);
    }

    public function testDocumentationTellsAboutNodeChromeAndTheSwitch(): void
    {
        $root = dirname(__DIR__);
        $agents = (string)file_get_contents($root . '/AGENTS.md');
        $readme = (string)file_get_contents($root . '/README.md');
        foreach (['Node.js', 'Chrome', 'install-capture.sh', '--no-capture'] as $needle) {
            $this->assertContains($needle, $agents, 'AGENTS.md ' . $needle);
        }
        foreach (['Node.js', 'Chrome', '--no-capture', 'Точная копия', 'install-capture.sh'] as $needle) {
            $this->assertContains($needle, $readme, 'README.md ' . $needle);
        }
    }
}
