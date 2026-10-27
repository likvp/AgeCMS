<?php
declare(strict_types=1);

// Admin panel: sign in, pages with a block editor, menus, site settings, history with rollback, leads, system.
// All writes are POST with a CSRF token, or JSON to admin/api.php with the X-CSRF-Token header.
// Content changes go through the same queue and the same validation as the agent API.
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{AgeCMSException, AntiSpam, Auth, Backup, Background, Cache, CircuitBreaker, Config, Database, ForbiddenException, Installer, Mailer, Media, Pages, Queue, Security, Seo, Slug};

header('Cache-Control: no-store');
foreach (require __DIR__ . '/../core/security-headers.php' as $name => $value) {
    header($name . ': ' . $value);
}

if (!Installer::installed() || !Database::exists()) {
    http_response_code(503);
    echo 'AgeCMS is not installed. Run php bin/install.php';
    exit;
}

// After the page is sent: finish queued work, ping search engines, re-cache the pages that changed. No cron needed.
Background::afterResponse(true);

$flash = '';
$error = '';
$newToken = '';
$newPassword = null;
$newAgent = null;
$notice = '';
$user = Auth::user();
// Set by the router for /admin/m/<module>/...: a page that a module declared in its manifest (ModuleAdmin).
$adminModule = $GLOBALS['AGECMS_ADMIN_MODULE'] ?? null;
$moduleReply = null;
$section = in_array($_GET['s'] ?? '', ['overview', 'pages', 'edit', 'media', 'trash', 'menus', 'settings', 'history', 'leads', 'users', 'system', 'forgot', 'reset'], true) ? (string)$_GET['s'] : 'overview';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminModule === null) {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            if (Auth::login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? '')) === null) {
                $error = 'Неверный логин или пароль';
            } else {
                header('Location: /admin/');
                exit;
            }
        } elseif ($action === 'forgot') {
            // Same answer whether or not the account exists. The link goes only to the address stored for it.
            Auth::requestReset((string)($_POST['identifier'] ?? ''));
            $notice = 'Если такой пользователь есть и для него указана почта, мы отправили письмо со ссылкой. Она действует один час.';
            $section = 'forgot';
        } elseif ($action === 'reset_password') {
            if ((string)($_POST['new_password'] ?? '') !== (string)($_POST['new_password2'] ?? '')) {
                $section = 'reset';
                $_GET['token'] = (string)($_POST['token'] ?? '');
                throw new AgeCMSException('Пароли не совпадают');
            }
            try {
                Auth::completeReset((string)($_POST['token'] ?? ''), (string)($_POST['new_password'] ?? ''));
            } catch (AgeCMSException $e) {
                $section = 'reset';
                $_GET['token'] = (string)($_POST['token'] ?? '');
                throw $e;
            }
            header('Location: /admin/?reset=ok');
            exit;
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
                    if ($slug === '') {
                        // The address is optional: it is made from the title (Cyrillic transliterated), under the parent page if one is chosen.
                        $slug = Slug::free((string)($_POST['title'] ?? ''), (string)($_POST['parent_slug'] ?? ''));
                    }
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
                        'llms_text' => (string)($_POST['llms_text'] ?? ''),
                    ]]);
                    $flash = $result['changed'] ? 'Настройки сохранены, ревизия #' . $result['revision_id'] : 'Без изменений';
                    $section = 'settings';
                    break;
                case 'page_delete':
                    $slug = (string)($_POST['slug'] ?? '');
                    Queue::submit($actor, 'delete_page', ['slug' => $slug]);
                    $flash = 'Страница /' . $slug . ' перенесена в корзину. Вернуть её можно в разделе «Корзина».';
                    $section = 'pages';
                    break;
                case 'page_restore':
                    $slug = (string)($_POST['slug'] ?? '');
                    Queue::submit($actor, 'restore_page', ['slug' => $slug]);
                    header('Location: /admin/?s=edit&slug=' . rawurlencode($slug) . '&restored=1');
                    exit;
                case 'page_rename':
                    $slug = (string)($_POST['slug'] ?? '');
                    $newSlug = trim((string)($_POST['new_slug'] ?? ''), " \t/");
                    Queue::submit($actor, 'rename_page', ['slug' => $slug, 'new_slug' => $newSlug]);
                    header('Location: /admin/?s=edit&slug=' . rawurlencode($newSlug) . '&renamed=' . rawurlencode($slug));
                    exit;
                case 'media_upload':
                    Security::requirePermission($actor, 'content.write');
                    $files = $_FILES['files'] ?? null;
                    $done = 0;
                    if (is_array($files) && is_array($files['name'] ?? null)) {
                        foreach (array_keys($files['name']) as $i) {
                            if ((int)$files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                                continue;
                            }
                            Media::saveUpload(['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]]);
                            $done++;
                        }
                    }
                    if ($done === 0) {
                        throw new AgeCMSException('Выберите картинку');
                    }
                    $flash = 'Загружено: ' . $done;
                    $section = 'media';
                    break;
                case 'media_delete':
                    Security::requirePermission($actor, 'content.write');
                    Media::trash((string)($_POST['name'] ?? ''));
                    $flash = 'Картинка перенесена в корзину. Вернуть её можно в разделе «Корзина».';
                    $section = 'media';
                    break;
                case 'media_restore':
                    Security::requirePermission($actor, 'content.write');
                    Media::restore((string)($_POST['name'] ?? ''));
                    $flash = 'Картинка возвращена в медиатеку';
                    $section = 'trash';
                    break;
                case 'media_purge':
                    Security::requirePermission($actor, 'system.manage');
                    Media::purge((string)($_POST['name'] ?? ''));
                    $flash = 'Картинка удалена навсегда';
                    $section = 'trash';
                    break;
                case 'page_purge':
                    $slug = (string)($_POST['slug'] ?? '');
                    Queue::submit($actor, 'purge_page', ['slug' => $slug]);
                    $flash = 'Страница /' . $slug . ' удалена навсегда';
                    $section = 'trash';
                    break;
                case 'user_create':
                    Security::requirePermission($actor, 'system.manage');
                    $own = ($_POST['password_mode'] ?? 'generate') === 'own';
                    $password = $own ? (string)($_POST['password'] ?? '') : Auth::generatePassword();
                    Auth::createUser((string)($_POST['username'] ?? ''), $password, (string)($_POST['role'] ?? 'editor'), (string)($_POST['email'] ?? ''));
                    if (!$own) {
                        $newPassword = ['user' => (string)$_POST['username'], 'value' => $password];
                    }
                    $flash = 'Пользователь ' . (string)$_POST['username'] . ' создан';
                    $section = 'users';
                    break;
                case 'user_email':
                    Security::requirePermission($actor, 'system.manage');
                    Auth::setEmail((int)($_POST['id'] ?? 0), (string)($_POST['email'] ?? ''));
                    $flash = 'Почта сохранена';
                    $section = 'users';
                    break;
                case 'email_change':
                    Auth::changeOwnEmail((int)$user['id'], (string)($_POST['current_password'] ?? ''), (string)($_POST['email'] ?? ''));
                    $flash = 'Почта сохранена';
                    $section = 'users';
                    break;
                case 'mail_save':
                    Security::requirePermission($actor, 'system.manage');
                    $from = trim((string)($_POST['mail_from'] ?? ''));
                    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                        throw new AgeCMSException('Адрес отправителя записан неверно');
                    }
                    $transport = (string)($_POST['mail_transport'] ?? 'mail');
                    $secure = (string)($_POST['smtp_secure'] ?? 'ssl');
                    $old = (array)Config::get('mail.smtp', []);
                    $pass = (string)($_POST['smtp_pass'] ?? '');
                    $leadsTo = [];
                    foreach (preg_split('~[,;\s]+~', (string)($_POST['leads_to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $addr) {
                        if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
                            throw new AgeCMSException('Адрес для заявок записан неверно: ' . $addr);
                        }
                        $leadsTo[] = $addr;
                    }
                    Config::set('mail', [
                        'leads_notify' => isset($_POST['leads_notify']),
                        'leads_to' => implode(', ', array_slice($leadsTo, 0, 5)),
                        'transport' => in_array($transport, ['mail', 'smtp'], true) ? $transport : 'mail',
                        'from' => $from,
                        'from_name' => mb_substr(trim((string)($_POST['mail_from_name'] ?? '')), 0, 80),
                        'smtp' => [
                            'host' => trim((string)($_POST['smtp_host'] ?? '')),
                            'port' => max(1, min(65535, (int)($_POST['smtp_port'] ?? 465))),
                            'secure' => in_array($secure, ['ssl', 'tls', ''], true) ? $secure : 'ssl',
                            'user' => trim((string)($_POST['smtp_user'] ?? '')),
                            'pass' => $pass !== '' ? $pass : (string)($old['pass'] ?? ''),
                        ],
                    ]);
                    $flash = 'Настройки почты сохранены';
                    $section = 'settings';
                    break;
                case 'mail_test':
                    Security::requirePermission($actor, 'system.manage');
                    $to = (string)($user['email'] ?? '');
                    if ($to === '') {
                        throw new AgeCMSException('Сначала укажите свою почту в разделе «Пользователи»');
                    }
                    Mailer::send($to, 'Проверка почты AgeCMS', "Это тестовое письмо. Если вы его читаете, сброс пароля по почте будет работать.\n");
                    $flash = 'Тестовое письмо отправлено на ' . Mailer::mask($to);
                    $section = 'settings';
                    break;
                case 'user_role':
                    Security::requirePermission($actor, 'system.manage');
                    Auth::changeRole((int)($_POST['id'] ?? 0), (string)($_POST['role'] ?? ''), (int)$user['id']);
                    $flash = 'Роль изменена';
                    $section = 'users';
                    break;
                case 'user_delete':
                    Security::requirePermission($actor, 'system.manage');
                    Auth::deleteUser((int)($_POST['id'] ?? 0), (int)$user['id']);
                    $flash = 'Пользователь удалён';
                    $section = 'users';
                    break;
                case 'user_reset':
                    Security::requirePermission($actor, 'system.manage');
                    $target = Database::row('SELECT id, username FROM users WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
                    if ($target === null) {
                        throw new AgeCMSException('Пользователь не найден');
                    }
                    $password = Auth::generatePassword();
                    Auth::resetPassword((int)$target['id'], $password);
                    $newPassword = ['user' => $target['username'], 'value' => $password];
                    $flash = 'Новый пароль для ' . $target['username'] . ' создан';
                    $section = 'users';
                    break;
                case 'password_change':
                    if ((string)($_POST['new_password'] ?? '') !== (string)($_POST['new_password2'] ?? '')) {
                        throw new AgeCMSException('Новые пароли не совпадают');
                    }
                    Auth::changeOwnPassword((int)$user['id'], (string)($_POST['current_password'] ?? ''), (string)($_POST['new_password'] ?? ''));
                    $flash = 'Пароль изменён';
                    $section = 'users';
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
                case 'agent_access_save':
                    Security::requirePermission($actor, 'system.manage');
                    Config::set('agent_access.self_issue', isset($_POST['self_issue']));
                    Config::set('backup.before_agent', isset($_POST['before_agent']));
                    $days = (int)($_POST['token_days'] ?? 90);
                    if ($days < 0 || $days > 3650) {
                        throw new AgeCMSException('Срок токена: от 0 (без срока) до 3650 дней');
                    }
                    Config::set('agent_access.token_days', $days);
                    $flash = 'Настройки доступа для агентов сохранены';
                    $section = 'system';
                    break;
                case 'invite_create':
                    Security::requirePermission($actor, 'system.manage');
                    $role = (string)($_POST['role'] ?? 'editor');
                    $days = (int)($_POST['token_days'] ?? Config::get('agent_access.token_days', 90));
                    $code = Auth::createInvite($role, $days, (string)($_POST['note'] ?? ''), (string)$user['username']);
                    $newAgent = ['kind' => 'invite', 'secret' => $code, 'text' => agent_invite_text($code), 'hours' => max(1, (int)Config::get('agent_access.invite_hours', 24))];
                    $flash = 'Код подключения создан';
                    $section = 'system';
                    break;
                case 'agent_user_create':
                    Security::requirePermission($actor, 'system.manage');
                    $login = 'agent-' . substr(bin2hex(random_bytes(3)), 0, 5);
                    $password = Auth::generatePassword();
                    Auth::createUser($login, $password, 'editor', '');
                    $newAgent = ['kind' => 'user', 'secret' => $password, 'text' => agent_user_text($login, $password), 'user' => $login];
                    $flash = 'Пользователь ' . $login . ' для агента создан';
                    $section = 'users';
                    break;
                case 'user_block':
                    Security::requirePermission($actor, 'system.manage');
                    $block = (string)($_POST['blocked'] ?? '1') === '1';
                    Auth::setBlocked((int)($_POST['id'] ?? 0), $block, (int)$user['id']);
                    $flash = $block ? 'Пользователь заблокирован: вход и его токены не работают' : 'Пользователь разблокирован';
                    $section = 'users';
                    break;
                case 'token_create':
                    Security::requirePermission($actor, 'system.manage');
                    $newToken = Auth::createToken((string)$_POST['name'], (string)$_POST['role'], max(0, (int)($_POST['days'] ?? 0)));
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
} catch (ForbiddenException $e) {
    $error = $section === 'forgot' || ($_POST['action'] ?? '') === 'forgot' ? 'Слишком много запросов. Попробуйте через час.' : $e->getMessage();
    $section = ($_POST['action'] ?? '') === 'forgot' ? 'forgot' : $section;
} catch (AgeCMSException $e) {
    $error = $e->getMessage();
}
$user = Auth::user();
if ($user !== null && in_array($section, ['forgot', 'reset'], true)) {
    $section = 'overview';
}
if ($adminModule !== null) {
    $section = 'module';
    if ($user === null) {
        // Not signed in: no page of any module is shown, a person is sent to the sign-in, anything else is refused.
        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
            header('Location: /admin/');
        } else {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Sign in first\n";
        }
        exit;
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if (!AntiSpam::rateLimit('admmod:' . (int)$user['id'], 300, 60)) {
        $moduleReply = \AgeCMS\ModuleResponse::html('Слишком много запросов', '<div class="notice error">Подождите минуту и повторите.</div>', 429);
    } elseif ($method === 'POST' && !Auth::checkCsrf($_POST['csrf'] ?? null)) {
        $moduleReply = \AgeCMS\ModuleResponse::html('Сессия устарела', '<div class="notice error">Сессия устарела, обновите страницу и повторите.</div>', 403);
    } else {
        $input = $_POST;
        unset($input['csrf']);
        $moduleReply = \AgeCMS\ModuleAdmin::run($adminModule, $method, (string)$adminModule['path'], $_GET, $input, Auth::actor());
    }
    if ($moduleReply->kind === 'redirect') {
        http_response_code($moduleReply->status);
        header('Location: ' . $moduleReply->body);
        exit;
    }
    if ($moduleReply->kind === 'json') {
        http_response_code($moduleReply->status);
        header('Content-Type: application/json; charset=utf-8');
        echo $moduleReply->body;
        exit;
    }
    http_response_code($moduleReply->status);
}

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

/** Inline SVG icon from the sprite. */
function icon(string $name): string
{
    return '<svg class="i" aria-hidden="true"><use href="/assets/admin/icons.svg#' . $name . '"/></svg>';
}

/** Compact timestamp: 2026-10-27 06:15. */
function admin_date(?string $v): string
{
    return $v === null ? '' : h(str_replace('T', ' ', substr($v, 0, 16)));
}

/** Hidden CSRF + action fields for a POST form. */
function admin_post_fields(string $csrf, string $action): string
{
    return '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">';
}

/** The message the owner pastes into the chat with an agent: address and a one-time code, no password. */
function agent_invite_text(string $code): string
{
    $url = Seo::siteUrl();
    return "Нужно поправить сайт {$url}. Инструкция для тебя: {$url}/AGENT.md\nКод подключения: {$code}\nОн одноразовый и действует сутки: обменяй его на свой токен, как написано в инструкции.";
}

/** The same for an agent that gets its own login and password instead of a code. */
function agent_user_text(string $login, string $password): string
{
    $url = Seo::siteUrl();
    return "Нужно поправить сайт {$url}. Инструкция для тебя: {$url}/AGENT.md\nЛогин: {$login}\nПароль: {$password}\nВыдай себе токен по инструкции и дальше работай только с ним.";
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
    <title>AgeCMS</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23cf4a17'/%3E%3Ctext x='16' y='22' text-anchor='middle' font-family='monospace' font-size='18' font-weight='700' fill='white'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="/assets/admin/admin.css">
    <script src="/assets/admin/theme.js"></script>
</head>
<body>
<?php
if ($user === null) {
    if ($section === 'reset') {
        header('Referrer-Policy: no-referrer'); // the link carries the secret
    }
    admin_view(in_array($section, ['forgot', 'reset'], true) ? $section : 'login', ['error' => $error, 'notice' => $notice, 'resetDone' => ($_GET['reset'] ?? '') === 'ok']);
} else {
    $csrf = Auth::csrf();
    $isAdmin = Security::can($user['role'], 'system.manage');
    $canWrite = Security::can($user['role'], 'content.write');
    admin_view('layout', compact('user', 'csrf', 'isAdmin', 'canWrite', 'section', 'flash', 'error', 'newToken', 'newPassword', 'newAgent', 'adminModule', 'moduleReply'));
}
?>
<div class="toasts" id="toasts" role="status" aria-live="polite"></div>
<script src="/assets/admin/admin.js" defer></script>
</body>
</html>
