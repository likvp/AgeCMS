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
$self = '/admin/install.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Установка AgeCMS</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<main class="container narrow" style="padding-top:32px;padding-bottom:60px">
    <h1>Установка AgeCMS</h1>
    <p><small>Шаг <?= $result !== null ? 3 : $step ?> из 3: <?= $result !== null ? 'готово' : ($step === 1 ? 'проверка среды' : 'данные сайта') ?></small></p>
<?php if ($result !== null): ?>
    <p class="notice ok">Установлено. Эта страница закрыта, файл ключа удалён.</p>
    <table>
        <tr><th>Логин</th><td><strong><?= $h($result['admin_user']) ?></strong></td></tr>
        <tr><th>Пароль</th><td><?php if ($result['generated']): ?><strong><?= $h($result['admin_password']) ?></strong><br><small>Показан один раз, запишите его сейчас.</small><?php else: ?>тот, что вы ввели<?php endif; ?></td></tr>
        <tr><th>Сайт</th><td><?= $h($result['site_url']) ?></td></tr>
    </table>
    <h2>Что сделать дальше</h2>
    <p>Добавьте задание cron раз в минуту (очередь записи, пересборка кэша, IndexNow):</p>
    <p><code>* * * * * php <?= $h($root) ?>/bin/queue-worker.php</code></p>
    <p>Если у хостинга нет cron, сайт всё равно работает: очередь разбирается при каждой записи, не будет только отложенных пересборок и пингов.</p>
    <p><a class="btn" href="/admin/">В админку</a></p>
<?php elseif ($step === 1): ?>
    <h2>Проверка среды</h2>
    <table>
        <tr><th>Что</th><th>Статус</th><th></th></tr>
        <?php foreach ($checks as $c): ?>
            <tr>
                <td><?= $h($c['label']) ?></td>
                <td><?= $c['ok'] ? 'есть' : ($c['required'] ? '<strong>нет, обязательно</strong>' : 'нет, не обязательно') ?></td>
                <td><small><?= $h($c['note']) ?></small></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($blockers !== []): ?>
        <p class="notice error">Установка невозможна: не хватает обязательного. Включите расширения в панели хостинга (раздел PHP) или напишите в поддержку хостера.</p>
    <?php else: ?>
        <p class="notice ok">Всё необходимое есть.</p>
        <p><a class="btn" href="<?= $h($self . '?key=' . rawurlencode($given) . '&step=2') ?>">Продолжить</a></p>
    <?php endif; ?>
<?php else: ?>
    <h2>Данные сайта</h2>
    <?php if ($error !== ''): ?><p class="notice error"><?= $h($error) ?></p><?php endif; ?>
    <form method="post" class="stack">
        <input type="hidden" name="key" value="<?= $h($given) ?>">
        <label>Адрес сайта
            <input name="site_url" value="<?= $h($_POST['site_url'] ?? $guess) ?>" required pattern="https?://[A-Za-z0-9.\-]+(:[0-9]+)?" placeholder="https://example.com">
        </label>
        <p><small>Подставлен адрес, по которому вы открыли эту страницу. От него зависят canonical, sitemap и cookie, поэтому проверьте его.</small></p>
        <?php if ($warnUrl): ?><p class="notice error">Адрес без HTTPS. Для рабочего сайта подключите сертификат и укажите https://…, иначе cookie администратора пойдёт без защиты.</p><?php endif; ?>
        <label class="check"><input type="checkbox" name="confirm_url" value="1" required> Да, сайт будет открываться именно по этому адресу</label>
        <label>Название сайта <input name="site_name" value="<?= $h($_POST['site_name'] ?? '') ?>" required maxlength="100"></label>
        <label>Логин администратора <input name="admin_user" value="<?= $h($_POST['admin_user'] ?? 'admin') ?>" required pattern="[A-Za-z0-9_.\-]{3,40}"></label>
        <fieldset class="ag-list">
            <legend>Пароль администратора</legend>
            <label class="check"><input type="radio" name="password_mode" value="generate" checked> Создать надёжный пароль и показать его один раз</label>
            <label class="check"><input type="radio" name="password_mode" value="own"> Задать свой (от 10 символов)</label>
            <input name="admin_password" type="password" autocomplete="new-password" placeholder="свой пароль">
        </fieldset>
        <label class="check"><input type="checkbox" name="demo" value="1" checked> Создать демо-страницы (главная, о нас, контакты)</label>
        <p><button class="btn" type="submit">Установить</button></p>
    </form>
<?php endif; ?>
</main>
</body>
</html>
