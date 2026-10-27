<?php
/** @var string $csrf */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Config, Mailer, Seo};

$settings = Seo::settings();
?>
<div class="pagehead"><div class="grow"><h1>Настройки сайта</h1><p>Здесь то, что про сайт видят поисковики и ИИ-модели: заголовок, описание и отдельный текст для <code>llms.txt</code>.</p></div></div>
<section class="panel">
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'settings_save') ?>
        <label class="f"><span>Название сайта <small>(title)</small></span><input class="in" type="text" name="site_name" value="<?= h($settings['site_name'] ?? '') ?>" required maxlength="100"><small class="hint">Идёт в заголовок каждой страницы (<code>&lt;title&gt;</code>), в <code>og:site_name</code> и в разметку организации. Так сайт называют поисковики и ИИ-модели.</small></label>
        <label class="f"><span>Описание сайта <small>(description)</small></span><textarea class="in" name="site_description" rows="3" maxlength="300"><?= h($settings['site_description'] ?? '') ?></textarea><small class="hint">Описание по умолчанию (<code>meta description</code>) для страниц, у которых своего нет. Попадает и в <code>llms.txt</code>. До 300 символов, одной строкой.</small></label>
        <label class="f"><span>Текст для ИИ-моделей <small>(llms.txt)</small></span><textarea class="in" name="llms_text" rows="9" maxlength="4000" placeholder="Чем занимается компания, для кого, чем отличается, как правильно называть, что не предлагаем, как связаться. Можно в формате Markdown."><?= h($settings['llms_text'] ?? '') ?></textarea><small class="hint">Свободный текст для языковых моделей: отдельно от описания, подробнее и без ограничения в одну строку. Выводится в файле <a href="/llms.txt" target="_blank" rel="noopener">/llms.txt</a> после описания, посетители сайта его не видят. До 4000 символов.</small></label>
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
<?php $smtp = (array)Config::get('mail.smtp', []); $tr = Mailer::transport(); $sec = (string)($smtp['secure'] ?? 'ssl'); ?>
<section class="panel">
    <div class="panel-h"><h2>Почта</h2><span class="tag <?= Mailer::configured() ? 'ok' : 'warn' ?>"><?= Mailer::configured() ? 'настроена' : 'не настроена' ?></span></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'mail_save') ?>
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
        <?= admin_post_fields($csrf, 'mail_test') ?>
        <p class="muted">Тестовое письмо уйдёт на вашу почту из раздела «Пользователи».</p>
        <button class="btn" type="submit"><?= icon('paper-plane') ?>Отправить тестовое письмо</button>
    </form>
</section>
