<?php
declare(strict_types=1);

use AgeCMS\{Auth, Config, ModuleSettings, SkillRegistry};

require_once __DIR__ . '/support/HttpCase.php';
require_once __DIR__ . '/support/ModuleFixture.php';

/** Settings of a module that the owner edits in the admin (E7). */
final class ModuleSettingsTest extends HttpCase
{
    private const FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];

    /** @return list<array<string,mixed>> */
    private function fields(): array
    {
        return [
            ['key' => 'from_name', 'label' => 'Имя отправителя', 'type' => 'text', 'default' => 'Карат', 'length' => 20, 'help' => 'Видно в письме'],
            ['key' => 'batch', 'label' => 'Писем за запуск', 'type' => 'number', 'min' => 1, 'max' => 200, 'default' => 20],
            ['key' => 'enabled', 'label' => 'Включено', 'type' => 'bool'],
            ['key' => 'mode', 'label' => 'Режим', 'type' => 'select', 'options' => ['тест', 'боевой']],
            ['key' => 'footer', 'label' => 'Подвал', 'type' => 'textarea'],
            ['key' => 'api_key', 'label' => 'Ключ', 'type' => 'secret'],
        ];
    }

    private function login(string $user = 'admin', string $password = 'Passw0rd-test-1'): string
    {
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => $user, 'password' => $password]), self::FORM, true);
        $page = $this->http('GET', '/admin/', null, [], true);
        preg_match('/name="csrf" value="([^"]+)"/', $page['body'], $m);
        return $m[1] ?? '';
    }

    private function save(string $module, array $values, string $csrf): array
    {
        $data = ['action' => 'module_settings_save', 'module' => $module, 'csrf' => $csrf];
        foreach ($values as $k => $v) {
            $data[str_starts_with($k, 'clear_') ? 'clear_f_' . substr($k, 6) : 'f_' . $k] = $v;
        }
        return $this->http('POST', '/admin/index.php', http_build_query($data), self::FORM, true);
    }

    private function config(string $module): array
    {
        Config::reset();
        return (array)Config::get("module_settings.{$module}", []);
    }

    public function testTheOwnerSeesTheFormAndSavesValues(): void
    {
        $this->boot();
        ModuleFixture::make('st_one', [], [], ['title' => 'Рассылки', 'settings' => $this->fields()]);
        $csrf = $this->login();
        $system = $this->http('GET', '/admin/?s=system', null, [], true)['body'];
        $this->assertContains('/admin/?s=module_settings&amp;m=st_one', $system, 'a link next to the module');

        $page = $this->http('GET', '/admin/?s=module_settings&m=st_one', null, [], true)['body'];
        $this->assertContains('Настройки модуля «st_one»', $page);
        $this->assertContains('name="f_from_name"', $page);
        $this->assertContains('value="Карат"', $page, 'the default is shown');
        $this->assertContains('Видно в письме', $page);
        $this->assertContains('type="password" name="f_api_key"', $page);
        $this->assertContains('<option value="боевой">', $page);

        $done = $this->save('st_one', ['from_name' => 'Ломбард', 'batch' => '50', 'enabled' => '1', 'mode' => 'боевой', 'footer' => "Строка 1\r\nСтрока 2", 'api_key' => 'sk-test-12345'], $csrf)['body'];
        $this->assertContains('Настройки сохранены', $done);
        $saved = $this->config('st_one');
        $this->assertSame('Ломбард', $saved['from_name']);
        $this->assertSame(50, $saved['batch']);
        $this->assertSame(true, $saved['enabled']);
        $this->assertSame('боевой', $saved['mode']);
        $this->assertSame("Строка 1\nСтрока 2", $saved['footer']);
        $this->assertSame('sk-test-12345', $saved['api_key']);
        $this->assertSame(0, (int)AgeCMS\Database::value("SELECT COUNT(*) FROM settings WHERE value LIKE '%sk-test%'"), 'a secret is not in the database');
        $this->assertContains('0600', substr(sprintf('%o', fileperms(Config::file())), -4) === '0600' ? '0600' : 'wrong', 'the file is private');
    }

    public function testASecretIsNeverShownAndBlankKeepsIt(): void
    {
        $this->boot();
        ModuleFixture::make('st_secret', [], [], ['settings' => $this->fields()]);
        $csrf = $this->login();
        $this->save('st_secret', ['from_name' => 'A', 'batch' => '5', 'mode' => 'тест', 'api_key' => 'sk-hidden-value'], $csrf);
        $page = $this->http('GET', '/admin/?s=module_settings&m=st_secret', null, [], true)['body'];
        $this->assertNotContains('sk-hidden-value', $page);
        $this->assertContains('задано, оставьте пустым', $page);
        $this->save('st_secret', ['from_name' => 'B', 'batch' => '5', 'mode' => 'тест', 'api_key' => ''], $csrf);
        $this->assertSame('sk-hidden-value', $this->config('st_secret')['api_key'], 'blank keeps the secret');
        $this->save('st_secret', ['from_name' => 'B', 'batch' => '5', 'mode' => 'тест', 'api_key' => '', 'clear_api_key' => '1'], $csrf);
        $this->assertSame('', $this->config('st_secret')['api_key'], 'the box erases it');
    }

    public function testBadValuesAreRefusedAndNothingChanges(): void
    {
        $this->boot();
        ModuleFixture::make('st_bad', [], [], ['settings' => $this->fields()]);
        $csrf = $this->login();
        $good = ['from_name' => 'Ок', 'batch' => '10', 'mode' => 'тест'];
        $this->save('st_bad', $good, $csrf);
        foreach ([
            ['batch' => '0'], ['batch' => '201'], ['batch' => 'abc'], ['batch' => '5.5'], ['batch' => ''],
            ['mode' => 'чужой'], ['from_name' => str_repeat('я', 21)], ['from_name' => "a\x07b"], ['api_key' => str_repeat('k', 256)],
        ] as $bad) {
            $r = $this->save('st_bad', $bad + $good, $csrf)['body'];
            $this->assertContains('role="alert"', $r, json_encode($bad, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame('Ок', $this->config('st_bad')['from_name']);
        $this->assertSame(10, $this->config('st_bad')['batch']);
        // undeclared keys are ignored
        $this->save('st_bad', $good + ['evil' => 'x'], $csrf);
        $this->assertSame(false, array_key_exists('evil', $this->config('st_bad')));
        $this->assertContains('role="alert"', $this->save('st_none', $good, $csrf)['body'], 'a module without settings');
    }

    public function testOnlyTheAdministratorWithATokenCanChangeThem(): void
    {
        $this->boot();
        ModuleFixture::make('st_perm', [], [], ['settings' => $this->fields()]);
        $none = $this->http('POST', '/admin/index.php', http_build_query(['action' => 'module_settings_save', 'module' => 'st_perm', 'f_from_name' => 'X', 'f_batch' => '1', 'f_mode' => 'тест']), self::FORM);
        $this->assertContains('name="username"', $none['body'], 'without a session: the sign-in form');
        $csrf = $this->login();
        $this->assertContains('Сессия устарела', $this->save('st_perm', ['from_name' => 'X', 'batch' => '1', 'mode' => 'тест'], 'wrong-token')['body']);
        $this->assertSame([], $this->config('st_perm'), 'nothing saved without the token');

        Auth::createUser('editor2', 'long-enough-pass-1', 'editor');
        $editorCsrf = $this->login('editor2', 'long-enough-pass-1');
        $page = $this->http('GET', '/admin/?s=module_settings&m=st_perm', null, [], true)['body'];
        $this->assertContains('Нужна роль администратора', $page);
        $this->assertNotContains('name="f_from_name"', $page);
        $r = $this->save('st_perm', ['from_name' => 'X', 'batch' => '1', 'mode' => 'тест'], $editorCsrf)['body'];
        $this->assertContains('role="alert"', $r);
        $this->assertSame([], $this->config('st_perm'), 'an editor changed nothing');
    }

    public function testBadDeclarationsAndTamperingBlockTheModule(): void
    {
        $this->boot();
        $one = static fn(array $f): array => [$f + ['key' => 'k', 'label' => 'L', 'type' => 'text']];
        $bad = [
            'st_b1' => $one(['type' => 'html']),
            'st_b2' => $one(['key' => 'Bad Key']),
            'st_b3' => $one(['label' => '']),
            'st_b4' => $one(['type' => 'number', 'min' => 10, 'max' => 5]),
            'st_b5' => $one(['type' => 'select']),
            'st_b6' => $one(['type' => 'text', 'options' => ['a']]),
            'st_b7' => $one(['type' => 'number', 'default' => 'x']),
            'st_b8' => $one(['type' => 'secret', 'default' => 'leaked']),
            'st_b9' => $one(['extra' => 1]),
            'st_b10' => [['key' => 'k', 'label' => 'L', 'type' => 'text'], ['key' => 'k', 'label' => 'M', 'type' => 'text']],
        ];
        foreach ($bad as $name => $settings) {
            ModuleFixture::make($name, [], [], ['settings' => $settings]);
            SkillRegistry::reset();
            $this->assertSame('blocked', SkillRegistry::modules()[$name]['status'], $name . ' should be blocked');
        }
        $dir = ModuleFixture::make('st_tamper', [], [], ['settings' => $this->fields()]);
        file_put_contents($dir . '/manifest.json', str_replace('"Имя отправителя"', '"Другое имя"', (string)file_get_contents($dir . '/manifest.json')));
        SkillRegistry::reset();
        $this->assertSame('blocked', SkillRegistry::modules()['st_tamper']['status'], 'changed settings break the signature');
        $csrf = $this->login();
        $this->assertContains('role="alert"', $this->save('st_tamper', ['from_name' => 'x', 'batch' => '1', 'mode' => 'тест'], $csrf)['body']);
    }

    public function testAModuleReadsItsOwnValuesWithDefaults(): void
    {
        $this->freshSite();
        ModuleFixture::make('st_read', [], [], ['settings' => $this->fields()]);
        SkillRegistry::reset();
        $this->assertSame('Карат', ModuleSettings::get('st_read', 'from_name'));
        $this->assertSame(20, ModuleSettings::get('st_read', 'batch'));
        $this->assertSame(false, ModuleSettings::get('st_read', 'enabled'));
        $this->assertSame('тест', ModuleSettings::get('st_read', 'mode'));
        $this->assertSame('fallback', ModuleSettings::get('st_read', 'unknown', 'fallback'));
        $this->assertSame('fallback', ModuleSettings::get('st_missing', 'x', 'fallback'));
        $changed = ModuleSettings::save('st_read', ['from_name' => 'Новое', 'batch' => '7', 'mode' => 'боевой']);
        $this->assertSame(['from_name', 'batch', 'mode'], $changed);
        $this->assertSame('Новое', ModuleSettings::get('st_read', 'from_name'));
        $this->assertSame(['from_name' => 'Новое', 'batch' => 7, 'enabled' => false, 'mode' => 'боевой', 'footer' => '', 'api_key' => ''], ModuleSettings::all('st_read'));
    }
}
