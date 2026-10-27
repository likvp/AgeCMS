<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * One-time installation. There is no installation on the first web request: either the CLI runs it
 * (bin/install.php) or the web installer is unlocked by a key the owner placed in data/install.key.
 * data/install.lock closes both paths for good.
 */
final class Installer
{
    public static function lockFile(): string
    {
        return AGECMS_DATA . '/install.lock';
    }

    public static function installed(): bool
    {
        return is_file(self::lockFile());
    }

    /** Key for the web installer, or null when the owner did not create data/install.key (min. 32 characters). */
    public static function webKey(): ?string
    {
        $file = AGECMS_DATA . '/install.key';
        if (!is_file($file)) {
            return null;
        }
        $key = trim((string)file_get_contents($file));
        return strlen($key) >= 32 ? $key : null;
    }

    /**
     * @param array{site_url:string,site_name?:string,site_description?:string,admin_user?:string,admin_password?:?string,demo?:bool} $options
     * @return array{site_url:string,admin_user:string,admin_password:string,generated:bool}
     * @throws AgeCMSException
     */
    public static function install(array $options): array
    {
        if (self::installed()) {
            throw new AgeCMSException('Already installed (' . self::lockFile() . ' exists)');
        }
        self::requirements();

        $siteUrl = rtrim((string)($options['site_url'] ?? ''), '/');
        if (preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $siteUrl) !== 1) {
            throw new ValidationException(['site_url must look like https://example.com (no path)']);
        }
        $user = (string)($options['admin_user'] ?? 'admin');
        $password = $options['admin_password'] ?? null;
        $generated = $password === null || $password === '';
        if ($generated) {
            $password = self::generatePassword();
        }
        // Checked before anything is written, so a typo cannot leave a half-installed site behind.
        if (preg_match('/^[A-Za-z0-9_.@-]{3,40}$/', $user) !== 1) {
            throw new ValidationException(['Username must be 3-40 characters: letters, digits, _ . @ -']);
        }
        if (strlen($password) < 10) {
            throw new ValidationException(['Password must be at least 10 characters']);
        }

        foreach ([AGECMS_DATA, AGECMS_DATA . '/logs', AGECMS_DATA . '/queue', AGECMS_DATA . '/backups', AGECMS_DATA . '/sessions', AGECMS_CACHE, Media::dir()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, str_starts_with($dir, AGECMS_DATA) ? 0700 : 0755, true) && !is_dir($dir)) {
                throw new AgeCMSException("Cannot create {$dir}");
            }
        }
        file_put_contents(AGECMS_DATA . '/.htaccess', "Require all denied\n");
        file_put_contents(AGECMS_DATA . '/index.html', '');

        Config::save([
            'site_url' => $siteUrl,
            'secret' => bin2hex(random_bytes(32)),
            'indexnow_key' => bin2hex(random_bytes(16)),
        ]);
        Config::reset();

        // Modules shipped in this folder are trusted at install time and signed with the new site key.
        foreach (glob(SkillRegistry::modulesDir() . '/*/manifest.json') ?: [] as $manifest) {
            ModuleSigner::sign(basename(dirname($manifest)));
        }
        SkillRegistry::reset();

        Database::create();
        Database::write(static function (\PDO $pdo) use ($options): void {
            $set = $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
            $set->execute(['site_name', (string)($options['site_name'] ?? 'AgeCMS')]);
            $set->execute(['site_description', (string)($options['site_description'] ?? '')]);
            $set->execute(['active_theme', 'default']);
        });
        Auth::createUser($user, $password, 'admin');

        if ($options['demo'] ?? true) {
            self::seed();
        }

        file_put_contents(self::lockFile(), json_encode(['installed_at' => ag_now(), 'install_id' => bin2hex(random_bytes(16))]));
        @chmod(self::lockFile(), 0600);
        @unlink(AGECMS_DATA . '/install.key');

        return ['site_url' => $siteUrl, 'admin_user' => $user, 'admin_password' => $password, 'generated' => $generated];
    }

    /**
     * Environment checks shown by the web installer and enforced by install().
     *
     * @return list<array{label:string,ok:bool,required:bool,note:string}>
     */
    public static function checks(): array
    {
        $fts = false;
        if (extension_loaded('pdo_sqlite')) {
            try {
                (new \PDO('sqlite::memory:'))->exec('CREATE VIRTUAL TABLE t USING fts5(a)');
                $fts = true;
            } catch (\Throwable) {
            }
        }
        $webp = extension_loaded('gd') && function_exists('imagewebp');
        return [
            ['label' => 'PHP 8.1 или новее', 'ok' => PHP_VERSION_ID >= 80100, 'required' => true, 'note' => 'найден ' . PHP_VERSION],
            ['label' => 'pdo_sqlite', 'ok' => extension_loaded('pdo_sqlite'), 'required' => true, 'note' => 'драйвер базы данных'],
            ['label' => 'SQLite с полнотекстовым поиском (FTS5)', 'ok' => $fts, 'required' => true, 'note' => 'нужен для поиска по сайту'],
            ['label' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'required' => true, 'note' => 'работа с русским текстом'],
            ['label' => 'json', 'ok' => extension_loaded('json'), 'required' => true, 'note' => ''],
            ['label' => 'gd с WebP', 'ok' => $webp, 'required' => false, 'note' => 'без неё картинки не сжимаются в WebP'],
            ['label' => 'curl', 'ok' => extension_loaded('curl'), 'required' => false, 'note' => 'вебхуки, Telegram, IndexNow'],
            ['label' => 'sodium', 'ok' => extension_loaded('sodium'), 'required' => false, 'note' => 'подписи модулей Ed25519'],
            ['label' => 'zip', 'ok' => extension_loaded('zip'), 'required' => false, 'note' => 'резервные копии'],
        ];
    }

    private static function requirements(): void
    {
        $missing = [];
        foreach (self::checks() as $check) {
            if ($check['required'] && !$check['ok']) {
                $missing[] = $check['label'];
            }
        }
        if ($missing !== []) {
            throw new AgeCMSException('Missing requirements: ' . implode(', ', $missing));
        }
    }

    private static function generatePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /** Starter pages and menus so a fresh site is not empty. */
    private static function seed(): void
    {
        $actor = Actor::system('installer');
        $name = (string)Database::value("SELECT value FROM settings WHERE key = 'site_name'");

        Content::createPage($actor, 'index', [
            'title' => 'Главная',
            'meta_title' => $name,
            'meta_description' => 'Быстрый сайт на AgeCMS',
            'sort_order' => 0,
            'blocks' => [
                ['id' => 'hero_1', 'type' => 'hero', 'data' => ['h1' => 'Добро пожаловать', 'subtitle' => 'Эта страница собрана из блоков. Нажмите на текст после входа в админку, чтобы изменить его на месте.', 'cta_text' => 'Оставить заявку', 'cta_url' => '#lead']],
                ['id' => 'features_1', 'type' => 'features', 'data' => ['heading' => 'Почему AgeCMS', 'items' => [
                    ['title' => '1 мс на страницу', 'text' => 'Публичные страницы отдаются готовыми файлами без PHP и базы данных.', 'size' => 'wide'],
                    ['title' => 'Правка кликом', 'text' => 'Меняйте текст прямо на сайте, каждая правка попадает в историю.'],
                    ['title' => 'Для ИИ-агентов', 'text' => 'MCP-шлюз с ролями, откатом и защитой от массовых правок.'],
                ]]],
                ['id' => 'faq_1', 'type' => 'faq', 'data' => ['heading' => 'Вопросы', 'items' => [
                    ['q' => 'Где хранятся данные?', 'a' => 'В одном файле data/data.sqlite. Скопировали файл, получили копию сайта.'],
                    ['q' => 'Можно ли откатить правку?', 'a' => 'Да. Каждое изменение сохраняется в журнале ревизий, откат это новая запись.'],
                ]]],
                ['id' => 'lead', 'type' => 'lead_form', 'data' => ['heading' => 'Связаться', 'subtitle' => 'Оставьте телефон, мы перезвоним.', 'button_text' => 'Отправить', 'success_text' => 'Спасибо, заявка отправлена.']],
            ],
        ]);
        Content::createPage($actor, 'about', [
            'title' => 'О нас',
            'schema_type' => 'AboutPage',
            'sort_order' => 10,
            'blocks' => [
                ['id' => 'text_1', 'type' => 'text', 'data' => ['heading' => 'О нас', 'body' => "Расскажите здесь о компании.\n\nАбзацы разделяются пустой строкой."]],
            ],
        ]);
        Content::createPage($actor, 'contacts', [
            'title' => 'Контакты',
            'schema_type' => 'ContactPage',
            'sort_order' => 20,
            'blocks' => [
                ['id' => 'lead', 'type' => 'lead_form', 'data' => ['heading' => 'Напишите нам', 'button_text' => 'Отправить', 'success_text' => 'Спасибо, заявка отправлена.']],
            ],
        ]);
        $menu = [['title' => 'Главная', 'url' => '/'], ['title' => 'О нас', 'url' => '/about'], ['title' => 'Контакты', 'url' => '/contacts']];
        Content::setMenu($actor, 'header', $menu);
        Content::setMenu($actor, 'footer', $menu);
    }
}
