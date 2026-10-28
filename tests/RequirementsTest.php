<?php
declare(strict_types=1);

use AgeCMS\Installer;

final class RequirementsTest extends TestCase
{
    public function testEveryCheckSaysHowSeriousItIsAndHowToFixIt(): void
    {
        $labels = [];
        foreach (Installer::checks() as $c) {
            $labels[] = $c['label'];
            $this->assertTrue(in_array($c['level'], ['required', 'recommended'], true), $c['label']);
            $this->assertSame($c['level'] === 'required', $c['required'], 'the old flag stays in step');
            $this->assertTrue($c['fix'] !== '' || $c['label'] === 'json', 'how to install: ' . $c['label']);
        }
        foreach (['sodium', 'gd с WebP', 'zip', 'zlib', 'curl', 'openssl', 'pdo_sqlite', 'mbstring', 'PHP может писать в файлы ядра'] as $must) {
            $this->assertTrue(in_array($must, $labels, true), "checked: {$must}");
        }
    }

    public function testCommandLineReportMatchesTheChecksAndTheExitCode(): void
    {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/requirements.php') . ' --json 2>&1', $out, $code);
        $report = json_decode(implode("\n", $out), true);
        $this->assertTrue(is_array($report), 'json report');
        $this->assertSame(array_column(array_filter(Installer::checks(), static fn(array $c): bool => !$c['ok'] && $c['level'] === 'required'), 'label'), $report['missing_required']);
        $expected = $report['missing_required'] !== [] ? 1 : ($report['missing_recommended'] !== [] ? 2 : 0);
        $this->assertSame($expected, $code, 'exit code: 0 all there, 1 required missing, 2 only recommended missing');
        $this->assertSame(count(Installer::checks()), count($report['checks']));
    }

    public function testAgentsKnowTheOrderAndTheWebSideCheck(): void
    {
        $agents = (string)file_get_contents(__DIR__ . '/../AGENTS.md');
        foreach (['php bin/requirements.php', 'sodium', 'php bin/install.php', 'get_site_structure', 'environment', 'Требования сервера'] as $needle) {
            $this->assertContains($needle, $agents, $needle);
        }
        $this->assertContains('php bin/requirements.php', (string)file_get_contents(__DIR__ . '/../README.md'));
    }
}
