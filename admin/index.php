<?php
declare(strict_types=1);

// Admin panel: sign in, see pages, history with rollback, leads, API tokens, backups, freeze state.
// All writes are POST with a CSRF token. Content changes go through the same queue as the API.
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{AgeCMSException, Auth, Backup, Cache, CircuitBreaker, Database, Http, Installer, Pages, Queue, Revisions, Security, SkillRegistry};

header('Cache-Control: no-store');
foreach (require __DIR__ . '/../core/security-headers.php' as $name => $value) {
    header($name . ': ' . $value);
}

if (!Installer::installed() || !Database::exists()) {
    http_response_code(503);
    echo 'AgeCMS is not installed. Run php bin/install.php';
    exit;
}

$flash = '';
$error = '';
$newToken = '';
$user = Auth::user();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            if (Auth::login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? '')) === null) {
                $error = 'Неверный логин или пароль';
            } else {
                header('Location: /admin/');
                exit;
            }
        } elseif ($user !== null) {
            if (!Auth::checkCsrf($_POST['csrf'] ?? null)) {
                throw new AgeCMSException('Сессия устарела, обновите страницу');
            }
            $actor = Auth::actor();
            switch ($action) {
                case 'logout':
                    Auth::logout();
                    header('Location: /admin/');
                    exit;
                case 'rollback':
                    $result = Queue::submit($actor, 'rollback', ['slug' => (string)$_POST['slug'], 'revision_id' => (int)$_POST['revision']]);
                    $flash = 'Откат выполнен, новая ревизия #' . ($result['revision_id'] ?? '-');
                    break;
                case 'unfreeze':
                    Security::requirePermission($actor, 'system.manage');
                    CircuitBreaker::unfreeze();
                    $flash = 'Запись снова разрешена';
                    break;
                case 'backup':
                    Security::requirePermission($actor, 'system.manage');
                    $flash = 'Бэкап создан: ' . basename(Backup::create());
                    break;
                case 'clear_cache':
                    Security::requirePermission($actor, 'cache.clear');
                    $flash = 'Кэш очищен, файлов: ' . Cache::clearAll();
                    break;
                case 'token_create':
                    Security::requirePermission($actor, 'system.manage');
                    $newToken = Auth::createToken((string)$_POST['name'], (string)$_POST['role']);
                    $flash = 'Токен создан. Скопируйте его сейчас, второй раз он не показывается.';
                    break;
                case 'token_revoke':
                    Security::requirePermission($actor, 'system.manage');
                    Auth::revokeToken((string)$_POST['name']);
                    $flash = 'Токен отозван';
                    break;
            }
        }
    }
} catch (AgeCMSException $e) {
    $error = $e->getMessage();
}
$user = Auth::user();

/** @param mixed $v */
function h($v): string
{
    return ag_e($v);
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Админка AgeCMS</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<?php if ($user === null): ?>
    <main class="container narrow login">
        <h1>Вход</h1>
        <?php if ($error !== ''): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
        <form method="post" class="lead-form">
            <input type="hidden" name="action" value="login">
            <input name="username" placeholder="Логин" autocomplete="username" required>
            <input name="password" type="password" placeholder="Пароль" autocomplete="current-password" required>
            <button class="btn" type="submit">Войти</button>
        </form>
    </main>
<?php else:
    $csrf = Auth::csrf();
    $isAdmin = Security::can($user['role'], 'system.manage');
    $frozen = CircuitBreaker::frozenInfo();
    $pages = Pages::listing();
    $recent = Revisions::recent(25);
    $leads = Database::rows('SELECT created_at, page_slug, name, phone, message FROM leads ORDER BY id DESC LIMIT 20');
    $stats = Database::rows('SELECT date, views, unique_visitors, leads_count FROM analytics_daily ORDER BY date DESC LIMIT 7');
    $modules = SkillRegistry::modules();
    ?>
    <header class="site-header"><div class="container bar">
        <strong>AgeCMS</strong>
        <nav class="nav"><a href="/" target="_blank" rel="noopener">Сайт</a>
            <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="link" type="submit">Выйти (<?= h($user['username']) ?>)</button></form>
        </nav>
    </div></header>
    <main class="container">
        <?php if ($flash !== ''): ?><p class="notice ok"><?= h($flash) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
        <?php if ($newToken !== ''): ?><p class="notice ok">Токен: <code><?= h($newToken) ?></code></p><?php endif; ?>
        <?php if ($frozen !== null): ?>
            <section class="notice error">
                <strong>Запись заморожена предохранителем</strong> с <?= h($frozen['since']) ?>: <?= h($frozen['reason']) ?>
                <?php if ($isAdmin): ?>
                <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="unfreeze"><button class="btn small" type="submit">Разморозить</button></form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <h2>Страницы</h2>
        <p>Чтобы править текст, откройте страницу и нажмите на нужное место.</p>
        <table>
            <tr><th>Страница</th><th>Тип</th><th>Статус</th><th>Изменена</th></tr>
            <?php foreach ($pages as $p): ?>
                <tr>
                    <td><a href="/<?= $p['slug'] === 'index' ? '' : h($p['slug']) ?>"><?= h($p['title']) ?></a> <small>/<?= h($p['slug']) ?></small></td>
                    <td><?= h($p['schema_type']) ?></td>
                    <td><?= (int)$p['is_published'] === 1 ? 'опубликована' : 'скрыта' ?><?= (int)$p['is_noindex'] === 1 ? ', noindex' : '' ?></td>
                    <td><?= h($p['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2>История изменений</h2>
        <table>
            <tr><th>#</th><th>Когда</th><th>Страница</th><th>Кто</th><th>Что</th><th></th></tr>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($r['created_at']) ?></td>
                    <td><?= h($r['page_slug']) ?></td>
                    <td><?= h($r['actor_type']) ?>: <?= h($r['actor_name']) ?></td>
                    <td><?= h($r['action_desc']) ?></td>
                    <td><?php if ($r['page_slug'][0] !== '@'): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Вернуть страницу к состоянию после этой ревизии?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="rollback"><input type="hidden" name="slug" value="<?= h($r['page_slug']) ?>"><input type="hidden" name="revision" value="<?= (int)$r['id'] ?>"><button class="link" type="submit">откатить</button></form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2>Заявки</h2>
        <?php if ($leads === []): ?><p>Пока нет.</p><?php else: ?>
        <table>
            <tr><th>Когда</th><th>Страница</th><th>Имя</th><th>Телефон</th><th>Сообщение</th></tr>
            <?php foreach ($leads as $l): ?>
                <tr><td><?= h($l['created_at']) ?></td><td><?= h($l['page_slug']) ?></td><td><?= h($l['name']) ?></td><td><?= h($l['phone']) ?></td><td><?= h($l['message']) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <h2>Посещаемость</h2>
        <table>
            <tr><th>День</th><th>Просмотры</th><th>Уникальные</th><th>Заявки</th></tr>
            <?php foreach ($stats as $s): ?>
                <tr><td><?= h($s['date']) ?></td><td><?= (int)$s['views'] ?></td><td><?= (int)$s['unique_visitors'] ?></td><td><?= (int)$s['leads_count'] ?></td></tr>
            <?php endforeach; ?>
        </table>

        <?php if ($isAdmin): ?>
        <h2>Модули</h2>
        <table>
            <tr><th>Модуль</th><th>Состояние</th></tr>
            <?php foreach ($modules as $name => $m): ?>
                <tr><td><?= h($name) ?></td><td><?= $m['status'] === 'ok' ? 'подпись проверена' : 'заблокирован: ' . h($m['reason']) ?></td></tr>
            <?php endforeach; ?>
        </table>

        <h2>Токены для ИИ-агентов</h2>
        <table>
            <tr><th>Имя</th><th>Роль</th><th>Последний вызов</th><th></th></tr>
            <?php foreach (Database::rows('SELECT name, role, last_used_at, revoked_at FROM api_tokens ORDER BY id') as $t): ?>
                <tr><td><?= h($t['name']) ?></td><td><?= h($t['role']) ?></td><td><?= h($t['last_used_at'] ?? 'не было') ?></td>
                    <td><?php if ($t['revoked_at'] === null): ?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="token_revoke"><input type="hidden" name="name" value="<?= h($t['name']) ?>"><button class="link" type="submit">отозвать</button></form><?php else: ?>отозван<?php endif; ?></td></tr>
            <?php endforeach; ?>
        </table>
        <form method="post" class="row">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="token_create">
            <input name="name" placeholder="имя, например seo-agent" required pattern="[A-Za-z0-9_.\-]{2,40}">
            <select name="role"><option value="editor">editor</option><option value="viewer">viewer</option><option value="admin">admin</option></select>
            <button class="btn small" type="submit">Создать токен</button>
        </form>

        <h2>Обслуживание</h2>
        <form method="post" class="row">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button class="btn small" name="action" value="backup" type="submit">Сделать бэкап</button>
            <button class="btn small" name="action" value="clear_cache" type="submit">Очистить кэш</button>
        </form>
        <?php foreach (array_slice(Backup::list(), 0, 5) as $b): ?><p><small><?= h($b['file']) ?>, <?= round($b['size'] / 1024) ?> КБ</small></p><?php endforeach; ?>
        <?php endif; ?>
    </main>
<?php endif; ?>
</body>
</html>
