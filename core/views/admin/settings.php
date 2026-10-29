<?php
/**
 * @var string $csrf
 * @var bool $isAdmin
 * @var bool $canWrite
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{AdminTabs, BlockEditor, Config, Mailer, Seo, SiteSettings, Theme};

$settings = Seo::settings();
// Everything here is the administrator's except the menus, which an editor changes too.
$all = ['main' => 'Главное', 'menu' => 'Меню', 'header' => 'Шапка', 'footer' => 'Подвал', 'mail' => 'Почта', 'analytics' => 'Аналитика', 'site' => 'Сайт'];
$tabs = $isAdmin ? $all : ['menu' => 'Меню'];
$asked = (string)($_GET['tab'] ?? '');
$tab = AdminTabs::current($tabs, $asked);
$theme = Theme::active();
$themeSettings = Theme::settings($theme);

/** One field of the theme settings as a form control. @param array<string,mixed> $spec */
$themeField = static function (string $name, array $spec, string $value): string {
    $type = (string)($spec['type'] ?? 'string');
    $label = BlockEditor::label($name, $spec);
    $hint = is_string($spec['hint'] ?? null) && $spec['hint'] !== '' ? '<small class="hint">' . h($spec['hint']) . '</small>' : '';
    $input = 't[' . $name . ']';
    $max = isset($spec['max']) ? ' maxlength="' . (int)$spec['max'] . '"' : '';
    switch ($type) {
        case 'bool':
            return '<div class="f"><input type="hidden" name="' . h($input) . '" value="0"><label class="chk"><input type="checkbox" name="' . h($input) . '" value="1"' . ($value === '1' ? ' checked' : '') . '><span>' . h($label) . '</span></label>' . $hint . '</div>';
        case 'text':
            return '<label class="f"><span>' . h($label) . '</span><textarea class="in" name="' . h($input) . '" rows="3"' . $max . '>' . h($value) . '</textarea>' . $hint . '</label>';
        case 'int':
            $range = (isset($spec['min']) ? ' min="' . (int)$spec['min'] . '"' : '') . (isset($spec['max']) ? ' max="' . (int)$spec['max'] . '"' : '');
            return '<label class="f"><span>' . h($label) . '</span><input class="in" type="number" name="' . h($input) . '" value="' . h($value) . '"' . $range . '>' . $hint . '</label>';
        case 'enum':
            $labels = (array)($spec['labels'] ?? []);
            $options = '<option value="">не выбрано</option>';
            foreach ((array)($spec['values'] ?? []) as $option) {
                $options .= '<option value="' . h((string)$option) . '"' . ((string)$option === $value ? ' selected' : '') . '>' . h(is_string($labels[$option] ?? null) ? $labels[$option] : (string)$option) . '</option>';
            }
            return '<label class="f"><span>' . h($label) . '</span><select class="in" name="' . h($input) . '">' . $options . '</select>' . $hint . '</label>';
        default:
            $field = '<input class="in" type="text" name="' . h($input) . '" value="' . h($value) . '"' . ($type === 'url' ? ' maxlength="500" autocapitalize="none" spellcheck="false"' : $max) . '>';
            if ($type === 'url' && !empty($spec['media'])) {
                return admin_media_field($label, $field, 'tf_' . $name, $value, $hint);
            }
            return '<label class="f"><span>' . h($label) . '</span>' . $field . $hint . '</label>';
    }
};
?>
<div class="pagehead"><div class="grow"><h1>Настройки</h1><p>Всё, что видно на каждой странице сайта: название и контакты, меню, шапка и подвал, а ещё почта, аналитика и то, что про сайт читают поисковики.</p></div></div>
<?= AdminTabs::nav($tabs, $tab, admin_url('settings'), 'Разделы настроек') ?>
<?php if ($asked !== '' && !isset($tabs[$asked]) && isset($all[$asked])): ?>
    <div class="notice error"><?= icon('lock-key') ?><div>Раздел «<?= h($all[$asked]) ?>» меняет администратор.</div></div>
<?php endif; ?>

<?php if ($tab === 'main'): ?>
<section class="panel">
    <form method="post" class="panel-b" enctype="multipart/form-data">
        <?= admin_post_fields($csrf, 'settings_save') . AdminTabs::field($tab) ?>
        <label class="f"><span>Название сайта <small>(title)</small></span><input class="in" type="text" name="site_name" value="<?= h($settings['site_name'] ?? '') ?>" required maxlength="100"><small class="hint">Идёт в заголовок каждой страницы (<code>&lt;title&gt;</code>), в <code>og:site_name</code> и в разметку организации. Так сайт называют поисковики и ИИ-модели.</small></label>
        <?= admin_media_field('Логотип', '<input class="in" type="text" name="org_logo" value="' . h($settings['org_logo'] ?? '') . '" maxlength="500" placeholder="/assets/uploads/..." autocapitalize="none" spellcheck="false">', 'org_logo_file', $settings['org_logo'] ?? '', '<small class="hint">Попадает в разметку организации для поисковиков, тема может показать его в шапке. Картинка сжимается в WebP.</small>') ?>
        <div class="row">
            <label class="f"><span>Телефон</span><input class="in" type="tel" name="org_phone" value="<?= h($settings['org_phone'] ?? '') ?>" maxlength="40" placeholder="+7 900 000-00-00"></label>
            <label class="f"><span>Второй телефон</span><input class="in" type="tel" name="org_phone2" value="<?= h($settings['org_phone2'] ?? '') ?>" maxlength="40"></label>
        </div>
        <div class="row">
            <label class="f"><span>Почта</span><input class="in" type="email" name="org_email" value="<?= h($settings['org_email'] ?? '') ?>" maxlength="120" placeholder="info@example.com" autocapitalize="none" spellcheck="false"></label>
            <label class="f"><span>ИНН</span><input class="in" type="text" name="org_inn" value="<?= h($settings['org_inn'] ?? '') ?>" inputmode="numeric" pattern="[0-9]{10}|[0-9]{12}" maxlength="12" title="10 или 12 цифр"></label>
        </div>
        <label class="f"><span>Юридическое название</span><input class="in" type="text" name="org_legal_name" value="<?= h($settings['org_legal_name'] ?? '') ?>" maxlength="200" placeholder="ООО «Пример»"></label>
        <label class="f"><span>Адрес</span><input class="in" type="text" name="org_address" value="<?= h($settings['org_address'] ?? '') ?>" maxlength="300" placeholder="г. Город, ул. Улица, 1"><small class="hint">Телефон, почта, ИНН и адрес попадают в разметку организации (JSON-LD). Где их показать на страницах, решает тема.</small></label>
        <button class="btn primary" type="submit">Сохранить</button>
    </form>
</section>

<?php elseif ($tab === 'menu'): ?>
    <?php admin_view('menus', ['csrf' => $csrf, 'canWrite' => $canWrite, 'embedded' => true]); ?>

<?php elseif ($tab === 'header' || $tab === 'footer'): ?>
    <?php $themeSection = $themeSettings[$tab] ?? null; ?>
    <?php if ($themeSection === null): ?>
    <section class="panel"><div class="empty"><?= icon('squares-four') ?><b>Тема «<?= h($theme) ?>» не даёт настроек <?= $tab === 'header' ? 'шапки' : 'подвала' ?></b><span>Что стоит в <?= $tab === 'header' ? 'шапке' : 'подвале' ?>, задаёт сама тема. Меню меняется во вкладке «<a href="<?= h(admin_url('settings', ['tab' => 'menu'])) ?>">Меню</a>», контакты во вкладке «<a href="<?= h(admin_url('settings', ['tab' => 'main'])) ?>">Главное</a>».</span></div></section>
    <?php else: ?>
    <section class="panel">
        <form method="post" class="panel-b" enctype="multipart/form-data">
            <?= admin_post_fields($csrf, 'theme_settings_save') . AdminTabs::field($tab) ?>
            <?php if ($themeSection['hint'] !== ''): ?><p class="muted"><?= h($themeSection['hint']) ?></p><?php endif; ?>
            <?php foreach ($themeSection['fields'] as $name => $spec): ?>
                <?php
                $stored = $settings["theme.{$tab}.{$name}"] ?? null;
                if ($stored === null && is_scalar($spec['default'] ?? null)) { // never saved: the form shows what the layout shows
                    $stored = is_bool($spec['default']) ? ($spec['default'] ? '1' : '0') : (string)$spec['default'];
                }
                ?>
                <?= $themeField($name, $spec, (string)$stored) ?>
            <?php endforeach; ?>
            <button class="btn primary" type="submit">Сохранить</button>
        </form>
    </section>
    <?php endif; ?>

<?php elseif ($tab === 'mail'): ?>
<?php $smtp = (array)Config::get('mail.smtp', []); $tr = Mailer::transport(); $sec = (string)($smtp['secure'] ?? 'ssl'); ?>
<section class="panel">
    <div class="panel-h"><h2>Почта</h2><span class="tag <?= Mailer::configured() ? 'ok' : 'warn' ?>"><?= Mailer::configured() ? 'настроена' : 'не настроена' ?></span></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'mail_save') . AdminTabs::field($tab) ?>
        <p class="muted">Письма уходят от имени <b><?= h(Mailer::from() ?: 'noreply@ваш-домен') ?></b>: адрес система берёт сама из адреса сайта, менять его не нужно. Нужна для писем со ссылкой на смену пароля и для заявок с сайта. Если на хостинге работает обычная отправка, оставьте «Почта хостинга». Если письма не доходят или попадают в спам, подключите SMTP-сервер: его данные есть в панели почты вашего хостинга.</p>
        <div class="row">
            <label class="f"><span>Способ отправки</span><select class="in" name="mail_transport"><option value="mail"<?= $tr === 'mail' ? ' selected' : '' ?>>Почта хостинга (PHP mail)</option><option value="smtp"<?= $tr === 'smtp' ? ' selected' : '' ?>>SMTP-сервер</option></select></label>
            <label class="f"><span>Адрес отправителя</span><input class="in" type="email" name="mail_from" value="<?= h((string)Config::get('mail.from', '')) ?>" placeholder="<?= h(Mailer::from() ?: 'noreply@example.com') ?>" maxlength="120"></label>
        </div>
        <label class="f"><span>Имя отправителя</span><input class="in" type="text" name="mail_from_name" value="<?= h((string)Config::get('mail.from_name', '')) ?>" placeholder="<?= h((string)($settings['site_name'] ?? 'AgeCMS')) ?>" maxlength="80"></label>
        <div class="row">
            <label class="f"><span>SMTP-сервер</span><input class="in" type="text" name="smtp_host" value="<?= h((string)($smtp['host'] ?? '')) ?>" placeholder="smtp.example.com" autocapitalize="none" spellcheck="false"></label>
            <label class="f"><span>Порт</span><input class="in" type="number" name="smtp_port" value="<?= (int)($smtp['port'] ?? 465) ?>" min="1" max="65535"></label>
            <label class="f"><span>Защита</span><select class="in" name="smtp_secure"><option value="ssl"<?= $sec === 'ssl' ? ' selected' : '' ?>>SSL (порт 465)</option><option value="tls"<?= $sec === 'tls' ? ' selected' : '' ?>>STARTTLS (порт 587)</option><option value=""<?= $sec === '' ? ' selected' : '' ?>>без защиты</option></select></label>
        </div>
        <div class="row">
            <label class="f"><span>Логин SMTP</span><input class="in" type="text" name="smtp_user" value="<?= h((string)($smtp['user'] ?? '')) ?>" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
            <label class="f"><span>Пароль SMTP</span><input class="in" type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= ($smtp['pass'] ?? '') !== '' ? 'сохранён, оставьте пустым' : '' ?>"></label>
        </div>
        <div class="f"><span class="lbl">Заявки с сайта</span>
            <label class="chk"><input type="checkbox" name="leads_notify" value="1"<?= Config::get('mail.leads_notify', true) === true ? ' checked' : '' ?>><span>Присылать новые заявки на почту</span></label>
        </div>
        <label class="f"><span>Куда присылать <small>(через запятую; пусто означает всем администраторам, у кого указана почта)</small></span><input class="in" type="text" name="leads_to" value="<?= h((string)Config::get('mail.leads_to', '')) ?>" placeholder="manager@example.com" maxlength="300" autocapitalize="none" spellcheck="false"></label>
        <div class="actions-row"><button class="btn primary" type="submit">Сохранить</button></div>
    </form>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'mail_test') . AdminTabs::field($tab) ?>
        <p class="muted">Тестовое письмо уйдёт на вашу почту из раздела «Пользователи».</p>
        <button class="btn" type="submit"><?= icon('paper-plane') ?>Отправить тестовое письмо</button>
    </form>
</section>

<?php elseif ($tab === 'analytics'): ?>
<?php
$ymId = $settings['yandex_metrika_id'] ?? '';
$ymOn = array_filter(explode(',', $settings['yandex_metrika_options'] ?? ($ymId === '' ? 'clickmap,trackLinks,accurateTrackBounce' : '')));
?>
<section class="panel">
    <div class="panel-h"><h2>Аналитика и вебмастер</h2><span class="tag <?= $ymId !== '' ? 'ok' : 'warn' ?>"><?= $ymId !== '' ? 'Метрика подключена' : 'Метрика не подключена' ?></span></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'analytics_save') . AdminTabs::field($tab) ?>
        <label class="f"><span>Яндекс Метрика <small>(номер счётчика)</small></span><input class="in" type="text" name="yandex_metrika_id" value="<?= h($ymId) ?>" inputmode="numeric" pattern="[0-9]*" maxlength="12" placeholder="например 113843789" autocomplete="off"><small class="hint">Номер из кабинета Метрики. Код ставить не нужно: сайт сам подключит счётчик на страницах, которые видят поисковики. Личный кабинет, поиск и страницы ошибок счётчика не получают.</small></label>
        <div class="f"><span class="lbl">Что включить в счётчике</span>
            <?php foreach (\AgeCMS\Analytics::METRIKA_OPTIONS as $key => $label): ?>
                <label class="chk"><input type="checkbox" name="yandex_metrika_options[]" value="<?= h($key) ?>"<?= in_array($key, $ymOn, true) ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
            <?php endforeach; ?>
            <small class="hint">Вебвизор записывает действия посетителей: скажите об этом в политике конфиденциальности. Поля форм Метрика скрывает сама.</small>
        </div>
        <label class="f"><span>Яндекс Вебмастер <small>(код подтверждения)</small></span><input class="in" type="text" name="yandex_webmaster" value="<?= h($settings['yandex_webmaster'] ?? '') ?>" maxlength="64" placeholder="например 31d36f48e3135250" autocomplete="off" autocapitalize="none" spellcheck="false"><small class="hint">Только сам код из метатега <code>yandex-verification</code> (то, что в <code>content="..."</code>). Тег появится в <code>&lt;head&gt;</code> каждой страницы.</small></label>
        <label class="f"><span>Другие метатеги подтверждения <small>(по одному в строке: имя=значение)</small></span><textarea class="in" name="verification_tags" rows="3" maxlength="2000" placeholder="google-site-verification=abc123&#10;msvalidate.01=ABC123" spellcheck="false" autocapitalize="none"><?= h($settings['verification_tags'] ?? '') ?></textarea><small class="hint">Для Google Search Console, Bing и всего, что просит подтвердить сайт метатегом. <b>Яндекс Директ</b> отдельного кода на сайте не требует: цели и аудитории он берёт из счётчика Метрики, номер которого выбирают в самой кампании.</small></label>
        <div class="actions-row"><button class="btn primary" type="submit">Сохранить</button></div>
    </form>
</section>

<?php elseif ($tab === 'site'): ?>
<section class="panel">
    <div class="panel-h"><h2>Поисковики и ИИ-модели</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'settings_save') . AdminTabs::field($tab) ?>
        <label class="f"><span>Описание сайта <small>(description)</small></span><textarea class="in" name="site_description" rows="3" maxlength="300"><?= h($settings['site_description'] ?? '') ?></textarea><small class="hint">Описание по умолчанию (<code>meta description</code>) для страниц, у которых своего нет. Попадает и в <code>llms.txt</code>. До 300 символов, одной строкой.</small></label>
        <label class="f"><span>Текст для ИИ-моделей <small>(llms.txt)</small></span><textarea class="in" name="llms_text" rows="9" maxlength="4000" placeholder="Чем занимается компания, для кого, чем отличается, как правильно называть, что не предлагаем, как связаться. Можно в формате Markdown."><?= h($settings['llms_text'] ?? '') ?></textarea><small class="hint">Свободный текст для языковых моделей: отдельно от описания, подробнее и без ограничения в одну строку. Выводится в файле <a href="/llms.txt" target="_blank" rel="noopener">/llms.txt</a> после описания, посетители сайта его не видят. До 4000 символов.</small></label>
        <button class="btn primary" type="submit">Сохранить</button>
    </form>
</section>
<section class="panel">
    <div class="panel-h"><h2>Плашка о cookie</h2><span class="tag <?= SiteSettings::cookieOn($settings) ? 'ok' : 'plain' ?>"><?= SiteSettings::cookieOn($settings) ? 'показывается' : 'выключена' ?></span></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'cookie_save') . AdminTabs::field($tab) ?>
        <p class="muted">Внизу каждой страницы, пока посетитель не нажмёт «Понятно». Нужна, если на сайте стоит Метрика или другая аналитика.</p>
        <label class="chk"><input type="checkbox" name="cookie_banner" value="1"<?= SiteSettings::cookieOn($settings) ? ' checked' : '' ?>><span>Показывать плашку</span></label>
        <label class="f"><span>Текст</span><textarea class="in" name="cookie_text" rows="3" maxlength="500" placeholder="<?= h(SiteSettings::COOKIE_TEXT) ?>"><?= h($settings['cookie_text'] ?? '') ?></textarea><small class="hint">Пусто: стандартный текст (он виден в поле серым).</small></label>
        <label class="f"><span>Ссылка на политику</span><input class="in" type="text" name="cookie_policy_url" value="<?= h($settings['cookie_policy_url'] ?? '') ?>" maxlength="500" placeholder="/politika-konfidencialnosti" autocapitalize="none" spellcheck="false"><small class="hint">Страница сайта или полный адрес. Пусто: ссылки «Подробнее» нет.</small></label>
        <button class="btn primary" type="submit">Сохранить</button>
    </form>
</section>
<section class="panel">
    <div class="panel-h"><h2>Адрес сайта</h2></div>
    <div class="panel-b">
        <p><code><?= h(Seo::siteUrl()) ?></code></p>
        <p class="muted">От адреса зависят canonical, sitemap и cookie, поэтому он меняется только из консоли:</p>
        <code class="cmd">php bin/config.php set site_url https://example.com</code>
    </div>
</section>
<?php endif; ?>
