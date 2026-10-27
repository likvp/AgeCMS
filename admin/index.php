<?php
declare(strict_types=1);

// Admin panel: sign in, pages with a block editor, menus, site settings, history with rollback, leads, system.
// All writes are POST with a CSRF token, or JSON to admin/api.php with the X-CSRF-Token header.
// Content changes go through the same queue and the same validation as the agent API.
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{AgeCMSException, Auth, Backup, Cache, CircuitBreaker, Database, Installer, Pages, Queue, Security};

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
$section = in_array($_GET['s'] ?? '', ['pages', 'edit', 'menus', 'settings', 'history', 'leads', 'system'], true) ? (string)$_GET['s'] : 'pages';

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
                case 'page_create':
                    $slug = trim((string)($_POST['slug'] ?? ''), " \t/");
                    $fields = ['title' => (string)($_POST['title'] ?? ''), 'schema_type' => (string)($_POST['schema_type'] ?? 'WebPage'), 'is_published' => 0, 'blocks' => []];
                    if (($_POST['parent_slug'] ?? '') !== '') {
                        $fields['parent_slug'] = (string)$_POST['parent_slug'];
                    }
                    Queue::submit($actor, 'create_page', ['slug' => $slug, 'fields' => $fields]);
                    header('Location: /admin/?s=edit&slug=' . rawurlencode($slug) . '&new=1');
                    exit;
                case 'settings_save':
                    $result = Queue::submit($actor, 'set_settings', ['values' => [
                        'site_name' => (string)($_POST['site_name'] ?? ''),
                        'site_description' => (string)($_POST['site_description'] ?? ''),
                    ]]);
                    $flash = $result['changed'] ? 'Настройки сохранены, ревизия #' . $result['revision_id'] : 'Без изменений';
                    $section = 'settings';
                    break;
                case 'rollback':
                    $result = Queue::submit($actor, 'rollback', ['slug' => (string)$_POST['slug'], 'revision_id' => (int)$_POST['revision']]);
                    $flash = 'Откат выполнен, новая ревизия #' . ($result['revision_id'] ?? '-');
                    $section = 'history';
                    break;
                case 'unfreeze':
                    Security::requirePermission($actor, 'system.manage');
                    CircuitBreaker::unfreeze();
                    $flash = 'Запись снова разрешена';
                    break;
                case 'backup':
                    Security::requirePermission($actor, 'system.manage');
                    $flash = 'Бэкап создан: ' . basename(Backup::create());
                    $section = 'system';
                    break;
                case 'clear_cache':
                    Security::requirePermission($actor, 'cache.clear');
                    $flash = 'Кэш очищен, файлов: ' . Cache::clearAll();
                    $section = 'system';
                    break;
                case 'token_create':
                    Security::requirePermission($actor, 'system.manage');
                    $newToken = Auth::createToken((string)$_POST['name'], (string)$_POST['role']);
                    $flash = 'Токен создан. Скопируйте его сейчас, второй раз он не показывается.';
                    $section = 'system';
                    break;
                case 'token_revoke':
                    Security::requirePermission($actor, 'system.manage');
                    Auth::revokeToken((string)$_POST['name']);
                    $flash = 'Токен отозван';
                    $section = 'system';
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

/** @param array<string,mixed> $vars */
function admin_view(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../core/views/admin/' . $name . '.php';
}

/** URL of an admin section. @param array<string,string> $params */
function admin_url(string $section, array $params = []): string
{
    return '/admin/?' . http_build_query(['s' => $section] + $params, '', '&', PHP_QUERY_RFC3986);
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
<?php
if ($user === null) {
    admin_view('login', ['error' => $error]);
} else {
    $csrf = Auth::csrf();
    $isAdmin = Security::can($user['role'], 'system.manage');
    $canWrite = Security::can($user['role'], 'content.write');
    admin_view('layout', compact('user', 'csrf', 'isAdmin', 'canWrite', 'section', 'flash', 'error', 'newToken'));
}
?>
</body>
</html>
