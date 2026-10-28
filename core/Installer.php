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
        Auth::createUser($user, $password, 'admin', (string)($options['admin_email'] ?? ''));

        if ($options['demo'] ?? true) {
            self::seed();
        }

        file_put_contents(self::lockFile(), json_encode(['installed_at' => ag_now(), 'install_id' => bin2hex(random_bytes(16))]));
        @chmod(self::lockFile(), 0600);
        @unlink(AGECMS_DATA . '/install.key');

        return ['site_url' => $siteUrl, 'admin_user' => $user, 'admin_password' => $password, 'generated' => $generated];
    }

    /**
     * Environment checks shown by the web installer, printed by bin/requirements.php and enforced by install().
     * level: "required" stops the installation, "recommended" means a feature is off without it (the installer goes on and
     * says so), fix is the command or panel step that installs it.
     *
     * @return list<array{label:string,ok:bool,required:bool,level:string,note:string,fix:string}>
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
        $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $row = static fn(string $label, bool $ok, string $level, string $note, string $fix = ''): array => ['label' => $label, 'ok' => $ok, 'required' => $level === 'required', 'level' => $level, 'note' => $note, 'fix' => $fix];
        return [
            $row('PHP 8.1 или новее', PHP_VERSION_ID >= 80100, 'required', 'найден ' . PHP_VERSION, 'выбрать PHP 8.1+ для сайта (панель хостинга) или поставить пакет php' . $v . ' на сервер'),
            $row('pdo_sqlite', extension_loaded('pdo_sqlite'), 'required', 'драйвер базы данных', 'Debian/Ubuntu: apt install php' . $v . '-sqlite3; в панели хостинга включить pdo_sqlite и sqlite3'),
            $row('SQLite с полнотекстовым поиском (FTS5)', $fts, 'required', 'нужен для поиска по сайту', 'обновить libsqlite3 или сменить сборку PHP; на хостинге написать в поддержку'),
            $row('mbstring', extension_loaded('mbstring'), 'required', 'работа с русским текстом', 'Debian/Ubuntu: apt install php' . $v . '-mbstring'),
            $row('json', extension_loaded('json'), 'required', ''),
            $row('sodium', extension_loaded('sodium'), 'recommended', 'подписи Ed25519: без него не работает кнопка «Обновить» (ядро) и проверка подписанных модулей', 'Debian/Ubuntu: apt install php' . $v . '-common (модуль sodium входит в него), RHEL/Remi: php-sodium, Alpine: php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '-sodium, образ php: docker-php-ext-install sodium; на хостинге включить sodium в «Расширения PHP» или попросить поддержку'),
            $row('zlib', function_exists('gzdecode'), 'recommended', 'сжатые пакеты обновлений и резервные копии', 'Debian/Ubuntu: apt install php' . $v . '-common; на хостинге включить zlib'),
            $row('PHP может писать в файлы ядра', !array_filter(Updater::preflight(), static fn(string $p): bool => str_starts_with($p, 'PHP не может писать')), 'recommended', 'нужно кнопке «Обновить»: пользователь PHP должен быть владельцем core/, admin/, bin/, assets/ (кроме cache и uploads), themes/default, index.php, .htaccess', 'chown -R пользователь-php:группа каталог-сайта (после git pull от root это нужно повторять), не 0777'),
            $row('openssl', extension_loaded('openssl'), 'recommended', 'двухфакторный вход, письма по SMTP с шифрованием, обращения по https', 'Debian/Ubuntu: apt install php' . $v . '-common; на хостинге включить openssl'),
            $row('gd с WebP', $webp, 'recommended', 'без неё картинки не сжимаются в WebP', 'Debian/Ubuntu: apt install php' . $v . '-gd; на хостинге включить gd (с поддержкой WebP)'),
            $row('curl', extension_loaded('curl'), 'recommended', 'вебхуки, Telegram, IndexNow, проверка новых версий', 'Debian/Ubuntu: apt install php' . $v . '-curl; на хостинге включить curl'),
            $row('zip', extension_loaded('zip'), 'recommended', 'резервные копии и установка модулей из архива', 'Debian/Ubuntu: apt install php' . $v . '-zip; на хостинге включить zip'),
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
