<?php
declare(strict_types=1);

use AgeCMS\{Actor, AgeCMSException, Api, ForbiddenException, ModuleInstaller, SkillRegistry};

final class ModuleInstallerTest extends TestCase
{
    /** A user module with one skill, as someone would write it by hand. Names are unique per test: PHP keeps loaded classes. */
    private function files(string $name, string $reply = 'pong'): array
    {
        $class = 'User' . str_replace('_', '', ucwords($name, '_'));
        return [
            'manifest.json' => json_encode(['name' => $name, 'title' => 'Мой модуль', 'entry' => 'Module.php', 'class' => "AgeCMS\\Modules\\{$class}\\Module"]),
            'Module.php' => "<?php\nnamespace AgeCMS\\Modules\\{$class};\nuse AgeCMS\\{Actor, ModuleInterface};\nfinal class Module implements ModuleInterface\n{\n    public function register(): void {}\n    public function callSkill(string \$skill, array \$args, Actor \$actor): array { return ['reply' => '{$reply}']; }\n}\n",
            'skills/ping.json' => json_encode(['name' => 'ping', 'description' => 'Ping', 'permission' => 'content.read']),
        ];
    }

    private function zip(array $files, string $prefix = ''): string
    {
        $file = TEST_TMP . '/module-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE);
        foreach ($files as $path => $bytes) {
            $zip->addFromString($prefix . $path, $bytes);
        }
        $zip->addFromString('__MACOSX/._junk', 'x');
        $zip->close();
        return $file;
    }

    public function testUserModuleRunsUnsignedAndCanBeSigned(): void
    {
        $this->freshSite();
        $done = ModuleInstaller::install($this->files('mi_user'), false, 'test');
        $this->assertSame(['name' => 'mi_user', 'status' => 'ok', 'signed' => null, 'reason' => null, 'replaced' => false], $done);
        $this->assertSame(['reply' => 'pong'], Api::call(Actor::agent('a', 'viewer'), 'mi_user__ping', []), 'the skill of an unsigned module works');
        $this->assertSame(['mi_user'], SkillRegistry::unsigned());

        ModuleInstaller::sign('mi_user', 'test');
        $this->assertSame('site', SkillRegistry::modules()['mi_user']['signed']);
        $this->assertSame([], SkillRegistry::unsigned());
        file_put_contents(AGECMS_MODULES . '/mi_user/Module.php', "<?php // changed\n");
        SkillRegistry::reset();
        $this->assertSame('blocked', SkillRegistry::modules()['mi_user']['status'], 'once signed, a changed file blocks it');
    }

    public function testZipWithATopFolderInstalls(): void
    {
        $this->freshSite();
        $files = ModuleInstaller::filesFromZip($this->zip($this->files('mi_zip'), 'mi_zip-main/'));
        $this->assertSame(['manifest.json', 'Module.php', 'skills/ping.json'], array_keys($files), 'top folder and macOS junk dropped');
        $this->assertSame('ok', ModuleInstaller::install($files, false, 'test')['status']);
    }

    public function testUnsafeFilesAreRefused(): void
    {
        $this->freshSite();
        foreach (['../evil.php', 'a/../../evil.php', '/etc/x', '.htaccess', 'skills/.hidden'] as $bad) {
            $this->assertThrows(AgeCMSException::class, fn() => ModuleInstaller::install($this->files('mi_bad') + [$bad => 'x'], false, 'test'), 'Недопустимое имя');
        }
        $this->assertThrows(AgeCMSException::class, fn() => ModuleInstaller::install(['Module.php' => '<?php'], false, 'test'), 'нет manifest.json');
        $this->assertThrows(AgeCMSException::class, fn() => ModuleInstaller::install(['manifest.json' => '{"name":"../x","class":"A"}', 'Module.php' => ''], false, 'test'), 'поле name');
        $this->assertSame(false, is_dir(AGECMS_MODULES . '/mi_bad'));
        $this->assertSame([], glob(AGECMS_MODULES . '/.upload-*') ?: [], 'no half-written folders');
    }

    public function testReplaceDisableDelete(): void
    {
        $this->freshSite();
        ModuleInstaller::install($this->files('mi_life'), false, 'test');
        $this->assertThrows(AgeCMSException::class, fn() => ModuleInstaller::install($this->files('mi_life'), false, 'test'), 'уже установлен');
        $this->assertSame(true, ModuleInstaller::install($this->files('mi_life', 'v2'), true, 'test')['replaced']);
        $this->assertContains("'v2'", (string)file_get_contents(AGECMS_MODULES . '/mi_life/Module.php'));
        $this->assertSame(1, count(glob(AGECMS_DATA . '/module-backups/mi_life-*') ?: []), 'the replaced version is kept');

        ModuleInstaller::setEnabled('mi_life', false, 'test');
        $this->assertSame('disabled', SkillRegistry::modules()['mi_life']['status']);
        $this->assertSame([], array_values(array_filter(array_column(Api::tools(Actor::agent('a', 'admin')), 'name'), static fn(string $n): bool => str_starts_with($n, 'mi_life__'))), 'a switched off module has no tools');
        ModuleInstaller::setEnabled('mi_life', true, 'test');
        $this->assertSame('ok', SkillRegistry::modules()['mi_life']['status']);

        ModuleInstaller::delete('mi_life', 'test');
        $this->assertSame(false, isset(SkillRegistry::modules()['mi_life']));
        $this->assertSame(2, count(glob(AGECMS_DATA . '/module-backups/mi_life-*') ?: []));
    }

    public function testAgentInstallsAndSwitchesModules(): void
    {
        $this->freshSite();
        $agent = Actor::agent('a', 'admin');
        $names = static fn(string $role): array => array_column(Api::tools(Actor::agent('x', $role)), 'name');
        foreach (['list_modules', 'install_module', 'enable_module', 'disable_module', 'delete_module'] as $tool) {
            $this->assertTrue(in_array($tool, $names('admin'), true), $tool);
            $this->assertSame(false, in_array($tool, $names('editor'), true), $tool . ' hidden from editor');
        }
        $this->assertThrows(ForbiddenException::class, fn() => Api::call(Actor::agent('e', 'editor'), 'install_module', ['files' => $this->files('mi_agent')]));

        $done = Api::call($agent, 'install_module', ['files' => $this->files('mi_agent')]);
        $this->assertSame('ok', $done['status']);
        $this->assertContains('red', $done['note']);
        $this->assertSame(['status' => 'ok', 'signed' => null, 'reason' => null], Api::call($agent, 'list_modules', [])['modules']['mi_agent']);
        $this->assertSame(null, Api::call($agent, 'get_site_structure', [])['modules']['mi_agent']['signed']);

        $zip = base64_encode((string)file_get_contents($this->zip($this->files('mi_agent_zip'))));
        $this->assertSame('ok', Api::call($agent, 'install_module', ['zip_base64' => $zip])['status']);

        $this->assertSame('disabled', Api::call($agent, 'disable_module', ['name' => 'mi_agent'])['modules']['mi_agent']['status']);
        $this->assertSame('ok', Api::call($agent, 'enable_module', ['name' => 'mi_agent'])['modules']['mi_agent']['status']);
        $this->assertSame(false, isset(Api::call($agent, 'delete_module', ['name' => 'mi_agent'])['modules']['mi_agent']));
    }
}
