<?php
declare(strict_types=1);

// Web installer for hosting without shell access. It is closed unless the owner uploaded data/install.key
// (any random string of 32+ characters) and open it as /admin/install.php?key=<that string>.
// Nothing happens on a plain first visit. After installation data/install.lock closes it for good.
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

$result = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = Installer::install([
            'site_url' => (string)($_POST['site_url'] ?? ''),
            'site_name' => (string)($_POST['site_name'] ?? 'AgeCMS'),
            'admin_user' => (string)($_POST['admin_user'] ?? 'admin'),
            'admin_password' => (string)($_POST['admin_password'] ?? ''),
            'demo' => isset($_POST['demo']),
        ]);
    } catch (AgeCMSException $e) {
        $error = $e->getMessage();
    }
}
$scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$guess = $scheme . '://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Установка AgeCMS</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="container narrow section">
    <h1>Установка AgeCMS</h1>
<?php if ($result !== null): ?>
    <p>Готово. Логин: <strong><?= ag_e($result['admin_user']) ?></strong><?php if ($result['generated']): ?>, пароль: <strong><?= ag_e($result['admin_password']) ?></strong> (показан один раз, сохраните)<?php endif; ?>.</p>
    <p><a class="btn" href="/admin/">В админку</a></p>
<?php else: ?>
    <?php if ($error !== ''): ?><p><strong><?= ag_e($error) ?></strong></p><?php endif; ?>
    <form method="post" class="lead-form">
        <input type="hidden" name="key" value="<?= ag_e($given) ?>">
        <label>Адрес сайта <input name="site_url" value="<?= ag_e($_POST['site_url'] ?? $guess) ?>" required></label>
        <label>Название сайта <input name="site_name" value="<?= ag_e($_POST['site_name'] ?? '') ?>" required></label>
        <label>Логин администратора <input name="admin_user" value="<?= ag_e($_POST['admin_user'] ?? 'admin') ?>" required></label>
        <label>Пароль (от 10 символов, пусто: создать случайный) <input name="admin_password" type="password" autocomplete="new-password"></label>
        <label><input type="checkbox" name="demo" value="1" checked> Создать демо-страницы</label>
        <button class="btn" type="submit">Установить</button>
    </form>
<?php endif; ?>
</main>
</body>
</html>
