<?php
declare(strict_types=1);

// Web installer for hosting without shell access, three steps: environment check, site data, result.
// It is closed unless the owner uploaded data/install.key (any random string of 32+ characters) and opens it as
// /admin/install.php?key=<that string>. Nothing happens on a plain first visit.
// After installation data/install.lock closes it for good and the key file is removed.
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{AgeCMSException, Installer};

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
foreach (require __DIR__ . '/../core/security-headers.php' as $name => $value) {
    header($name . ': ' . $value);
}

if (Installer::installed()) {
    http_response_code(404);
    exit('Not found');
}
$expected = Installer::webKey();
$given = (string)($_REQUEST['key'] ?? '');
if ($expected === null || !hash_equals($expected, $given)) {
    http_response_code(404);
    exit('Not found');
}

$checks = Installer::checks();
$blockers = array_filter($checks, static fn(array $c): bool => $c['required'] && !$c['ok']);
$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$guess = $scheme . '://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

$result = null;
$error = '';
$step = ($_GET['step'] ?? '') === '2' && $blockers === [] ? 2 : 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = 2;
    try {
        if ($blockers !== []) {
            throw new AgeCMSException('Среда не подходит, см. шаг 1');
        }
        if (!isset($_POST['confirm_url'])) {
            throw new AgeCMSException('Подтвердите адрес сайта');
        }
        $own = ($_POST['password_mode'] ?? 'generate') === 'own';
        $result = Installer::install([
            'site_url' => (string)($_POST['site_url'] ?? ''),
            'site_name' => (string)($_POST['site_name'] ?? 'AgeCMS'),
            'admin_user' => (string)($_POST['admin_user'] ?? 'admin'),
            'admin_email' => (string)($_POST['admin_email'] ?? ''),
            'admin_password' => $own ? (string)($_POST['admin_password'] ?? '') : null,
            'demo' => isset($_POST['demo']),
        ]);
    } catch (AgeCMSException $e) {
        $error = $e->getMessage();
    }
}
$root = dirname(__DIR__);
$warnUrl = !str_starts_with($guess, 'https://') && !preg_match('~^http://(localhost|127\.0\.0\.1)(:\d+)?$~', $guess);
$h = static fn($v): string => ag_e($v);
$asset = static fn(string $f): string => '/assets/admin/' . $f . '?v=' . (is_file(AGECMS_ROOT . '/assets/admin/' . $f) ? filemtime(AGECMS_ROOT . '/assets/admin/' . $f) : 1);
$icon = static fn(string $n): string => '<svg class="i" aria-hidden="true"><use href="' . $h($asset('icons.svg')) . '#' . $n . '"/></svg>';
$self = '/admin/install.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Установка AgeCMS</title>
    <link rel="stylesheet" href="<?= $h($asset('admin.css')) ?>">
    <script src="<?= $h($asset('theme.js')) ?>"></script>
</head>
<body>
<?php $now = $result !== null ? 3 : $step; ?>
<main class="wizard enter">
    <a class="brand" href="/"><span class="mark">A</span><span>AgeCMS</span><small>установка</small></a>
    <ol class="steps" aria-label="Шаги установки">
        <?php foreach ([1 => 'Проверка среды', 2 => 'Данные сайта', 3 => 'Готово'] as $n => $label): ?>
            <li class="<?= $n < $now || $result !== null ? 'done' : ($n === $now ? 'on' : '') ?>"<?= $n === $now ? ' aria-current="step"' : '' ?>><?= $h($label) ?></li>
        <?php endforeach; ?>
    </ol>

<?php if ($result !== null): ?>
    <h1>AgeCMS установлена</h1>
    <p class="muted">Страница установки закрыта, файл ключа удалён. Сохраните данные для входа.</p>
    <section class="panel">
        <dl class="cred">
            <dt>Логин</dt><dd id="c-user"><?= $h($result['admin_user']) ?></dd><button class="ib" type="button" data-copy="#c-user" title="Копировать" aria-label="Копировать логин"><?= $icon('copy') ?></button>
            <dt>Пароль</dt>
            <?php if ($result['generated']): ?>
                <dd id="c-pass"><?= $h($result['admin_password']) ?></dd><button class="ib" type="button" data-copy="#c-pass" title="Копировать" aria-label="Копировать пароль"><?= $icon('copy') ?></button>
            <?php else: ?>
                <dd>тот, что вы ввели</dd><span></span>
            <?php endif; ?>
            <dt>Сайт</dt><dd><?= $h($result['site_url']) ?></dd><span></span>
        </dl>
    </section>
    <?php if ($result['generated']): ?><div class="notice warn"><?= $icon('warning-circle') ?><div>Пароль показан один раз. Запишите его сейчас: восстановить его нельзя, только задать новый.</div></div><?php endif; ?>
    <section class="panel">
        <div class="panel-h"><h2>Что сделать дальше</h2></div>
        <div class="panel-b">
            <p>Ничего настраивать не нужно. Войдите в админку, откройте «Страницы» и правьте текст. Сайт сам обновляет кэш, отправляет уведомления поисковикам и разбирает очередь записи: всё это происходит сразу после сохранения и при заходе посетителей, cron не требуется.</p>
            <p class="muted gap">Если хотите, чтобы это же делалось по расписанию, есть необязательная команда <code>php bin/queue-worker.php</code>.</p>
        </div>
    </section>
    <a class="btn primary" href="/admin/">Войти в админку</a>

<?php elseif ($step === 1): ?>
    <h1>Проверка среды</h1>
    <p class="muted">Смотрим, что есть на хостинге. Это занимает секунду и ничего не меняет.</p>
    <section class="panel">
        <table class="tbl">
            <tbody>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td><?= $h($c['label']) ?><span class="cell-sub"><?= $h($c['note']) ?></span></td>
                    <td class="r"><?php if ($c['ok']): ?><span class="tag ok">есть</span><?php elseif ($c['required']): ?><span class="tag err">нет, обязательно</span><?php else: ?><span class="tag warn">нет, не обязательно</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php if ($blockers !== []): ?>
        <div class="notice error"><?= $icon('warning-circle') ?><div>Установка невозможна: не хватает обязательного. Включите расширения в панели хостинга (раздел PHP) или напишите в поддержку хостера, затем обновите страницу.</div></div>
        <a class="btn" href="<?= $h($self . '?key=' . rawurlencode($given)) ?>">Проверить снова</a>
    <?php else: ?>
        <div class="notice ok"><?= $icon('check-circle') ?><div>Всё необходимое есть.</div></div>
        <a class="btn primary" href="<?= $h($self . '?key=' . rawurlencode($given) . '&step=2') ?>">Продолжить</a>
    <?php endif; ?>

<?php else: ?>
    <h1>Данные сайта</h1>
    <p class="muted">Адрес, название и доступ администратора.</p>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= $icon('warning-circle') ?><div><?= $h($error) ?></div></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="key" value="<?= $h($given) ?>">
        <section class="panel"><div class="panel-b">
            <label class="f"><span>Адрес сайта</span>
                <input class="in" type="text" name="site_url" value="<?= $h($_POST['site_url'] ?? $guess) ?>" required pattern="https?://[A-Za-z0-9.\-]+(:[0-9]+)?" placeholder="https://example.com" autocapitalize="none" spellcheck="false">
                <small class="hint">Подставлен адрес, по которому открыта эта страница. От него зависят canonical, sitemap и cookie, поэтому проверьте его.</small></label>
            <?php if ($warnUrl): ?><div class="notice warn"><?= $icon('warning-circle') ?><div>Адрес без HTTPS. Для рабочего сайта подключите сертификат и укажите https://…, иначе cookie администратора пойдёт без защиты.</div></div><?php endif; ?>
            <label class="chk"><input type="checkbox" name="confirm_url" value="1" required><span>Да, сайт будет открываться именно по этому адресу</span></label>
            <label class="f"><span>Название сайта</span><input class="in" type="text" name="site_name" value="<?= $h($_POST['site_name'] ?? '') ?>" required maxlength="100"></label>
        </div></section>
        <section class="panel"><div class="panel-b">
            <label class="f"><span>Логин администратора</span><input class="in" type="text" name="admin_user" value="<?= $h($_POST['admin_user'] ?? 'admin') ?>" required pattern="[A-Za-z0-9_.\-]{3,40}" autocapitalize="none" spellcheck="false"></label>
            <label class="f"><span>Почта администратора <small>(для восстановления пароля, можно позже)</small></span><input class="in" type="email" name="admin_email" value="<?= $h($_POST['admin_email'] ?? '') ?>" maxlength="120" autocapitalize="none" spellcheck="false" autocomplete="email"></label>
            <div class="f"><span class="lbl">Пароль</span>
                <label class="chk"><input type="radio" name="password_mode" value="generate" data-pw-mode<?= ($_POST['password_mode'] ?? 'generate') !== 'own' ? ' checked' : '' ?>><span>Создать надёжный пароль и показать его один раз</span></label>
                <label class="chk"><input type="radio" name="password_mode" value="own" data-pw-mode<?= ($_POST['password_mode'] ?? '') === 'own' ? ' checked' : '' ?>><span>Задать свой <small>(от 10 символов)</small></span></label>
            </div>
            <div id="pw-own"><label class="f"><span>Свой пароль</span><input class="in" name="admin_password" type="password" autocomplete="new-password" minlength="10"></label></div>
        </div></section>
        <label class="chk"><input type="checkbox" name="demo" value="1" checked><span>Создать демо-страницы <small>(главная, о нас, контакты)</small></span></label>
        <button class="btn primary" type="submit">Установить</button>
    </form>
<?php endif; ?>
</main>
<div class="toasts" id="toasts" role="status" aria-live="polite"></div>
<script src="<?= $h($asset('admin.js')) ?>" defer></script>
</body>
</html>
