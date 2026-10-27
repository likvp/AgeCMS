<?php
/**
 * @var array<string,mixed> $user
 * @var string $csrf
 * @var bool $isAdmin
 * @var bool $canWrite
 * @var string $section
 * @var string $flash
 * @var string $error
 * @var string $newToken
 * @var array<string,string>|null $newPassword
 * @var array<string,mixed>|null $newAgent
 * @var array<string,mixed>|null $adminModule
 * @var \AgeCMS\ModuleResponse|null $moduleReply
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{CircuitBreaker, Database, Media, Pages};

$frozen = CircuitBreaker::frozenInfo();
// Agent tokens that run out within a week (or already did while still in use): the owner hears about it here, not only the agent.
$expiring = $isAdmin ? Database::rows("SELECT name, expires_at FROM api_tokens WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at < ? AND last_used_at IS NOT NULL AND last_used_at >= ? ORDER BY expires_at", [gmdate('Y-m-d\\TH:i:s\\Z', time() + 7 * 86400), gmdate('Y-m-d\\TH:i:s\\Z', time() - 30 * 86400)]) : [];
$nav = [
    ['overview', 'Обзор', 'squares-four', null],
    ['pages', 'Страницы', 'file-text', Pages::count()],
    ['media', 'Медиа', 'image', null],
    ['trash', 'Корзина', 'trash', $canWrite ? count(Pages::deleted()) + count(Media::trashed()) : 0],
    ['menus', 'Меню', 'list', null],
    ['history', 'История', 'clock-counter-clockwise', null],
    ['leads', 'Заявки', 'chat-circle-text', (int)(Database::row('SELECT COUNT(*) AS n FROM leads')['n'] ?? 0)],
];
if ($isAdmin) {
    $nav[] = ['users', 'Пользователи', 'users', null];
    $nav[] = ['settings', 'Настройки', 'gear-six', null];
}
$nav[] = ['system', 'Система', 'terminal-window', null];
$moduleNav = \AgeCMS\ModuleAdmin::menu((string)$user['role']);
$active = $section === 'edit' ? 'pages' : ($section === 'module_settings' ? 'system' : $section);
$roleNames = ['admin' => 'Администратор', 'editor' => 'Редактор', 'viewer' => 'Наблюдатель'];
?>
<a class="skip" href="#main">К содержимому</a>
<div class="shell">
    <aside class="side">
        <a class="brand" href="<?= h(admin_url('overview')) ?>"><span class="mark">A</span><span>AgeCMS</span><small>админка</small></a>
        <nav class="nav" aria-label="Разделы">
            <?php foreach ($nav as [$key, $label, $ico, $count]): ?>
                <a href="<?= h(admin_url($key)) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= h($label) ?></span><?php if ($count): ?><span class="count"><?= (int)$count ?></span><?php endif; ?></a>
            <?php endforeach; ?>
            <?php if ($moduleNav !== []): ?>
                <div class="nav-sep"></div>
                <?php foreach ($moduleNav as [$label, $href, $id]): ?>
                    <a href="<?= h($href) ?>"<?= $adminModule !== null && $id === $adminModule['page']['module'] . '|' . $adminModule['page']['path'] ? ' aria-current="page"' : '' ?>><?= icon('squares-four') ?><span><?= h($label) ?></span></a>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="nav-sep"></div>
            <a href="/" target="_blank" rel="noopener"><?= icon('arrow-up-right') ?><span>Открыть сайт</span></a>
        </nav>
        <div class="side-foot">
            <div class="seg" role="group" aria-label="Тема оформления">
                <button type="button" data-theme-set="light" aria-pressed="false">Светлая</button>
                <button type="button" data-theme-set="auto" aria-pressed="true">Авто</button>
                <button type="button" data-theme-set="dark" aria-pressed="false">Тёмная</button>
            </div>
            <div class="who">
                <a class="who-link" href="<?= h(admin_url('users')) ?>" title="Профиль и пароль"><span class="avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string)$user['username'], 0, 1))) ?></span>
                <span class="nm"><b><?= h($user['username']) ?></b><small><?= h($roleNames[$user['role']] ?? $user['role']) ?></small></span></a>
                <form method="post" action="/admin/" class="inline"><?= admin_post_fields($csrf, 'logout') ?><button class="ib" type="submit" title="Выйти" aria-label="Выйти"><?= icon('sign-out') ?></button></form>
            </div>
        </div>
    </aside>
    <main class="main" id="main">
        <div class="wrap<?= $section === 'edit' ? ' wide' : '' ?>"<?= $canWrite ? ' data-csrf="' . h($csrf) . '"' : '' ?>>
            <?php if ($flash !== ''): ?><div class="notice ok" role="status"><?= icon('check-circle') ?><div><?= h($flash) ?></div></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= icon('warning-circle') ?><div><?= h($error) ?></div></div><?php endif; ?>
            <?php if ($newToken !== ''): ?>
                <div class="notice warn" role="status"><?= icon('key') ?><div>
                    <p><b>Скопируйте токен сейчас.</b> Потом он не показывается.</p>
                    <p class="row center"><code id="new-token"><?= h($newToken) ?></code> <button class="btn sm" type="button" data-copy="#new-token"><?= icon('copy') ?>Копировать</button></p>
                </div></div>
            <?php endif; ?>
            <?php if (!empty($newPassword)): ?>
                <div class="notice warn" role="status"><?= icon('key') ?><div>
                    <p><b>Пароль для <?= h($newPassword['user']) ?>. Передайте его человеку сейчас.</b> Потом он не показывается.</p>
                    <p class="row center"><code id="new-password"><?= h($newPassword['value']) ?></code> <button class="btn sm" type="button" data-copy="#new-password"><?= icon('copy') ?>Копировать</button></p>
                </div></div>
            <?php endif; ?>
            <?php if (!empty($newAgent)): ?>
                <div class="notice warn" role="status"><?= icon('key') ?><div>
                    <p><b><?= $newAgent['kind'] === 'invite' ? 'Скопируйте текст и вставьте его агенту в чат.' : 'Скопируйте текст для агента: логин и пароль показываются один раз.' ?></b>
                    <?= $newAgent['kind'] === 'invite' ? 'Код сработает один раз и действует ' . (int)$newAgent['hours'] . ' ч.' : 'Пароль нигде не сохраняется открытым.' ?></p>
                    <pre class="agent-text" id="new-agent"><?= h($newAgent['text']) ?></pre>
                    <p><button class="btn sm" type="button" data-copy="#new-agent"><?= icon('copy') ?>Копировать текст</button></p>
                </div></div>
            <?php endif; ?>
            <?php if ($expiring !== []): ?>
                <div class="notice warn" role="status"><?= icon('clock-counter-clockwise') ?><div>
                    <p><b>Скоро заканчивается токен агента:</b>
                    <?php foreach ($expiring as $i => $t): $left = (int)ceil((strtotime($t['expires_at']) - time()) / 86400); ?><?= $i > 0 ? ', ' : ' ' ?><?= h($t['name']) ?> (<?= $left > 0 ? 'ещё ' . $left . ' дн.' : 'уже закончился' ?>)<?php endforeach; ?>.
                    Чтобы агент не потерял доступ, получите новый текст для него в разделе «<a href="<?= h(admin_url('system')) ?>">Система</a>».</p>
                </div></div>
            <?php endif; ?>
            <?php if ($frozen !== null): ?>
                <div class="notice error" role="alert"><?= icon('snowflake') ?><div>
                    <p><b>Запись заморожена предохранителем</b> с <?= h($frozen['since']) ?>: <?= h($frozen['reason']) ?></p>
                    <?php if ($isAdmin): ?>
                        <form method="post" action="/admin/"><?= admin_post_fields($csrf, 'unfreeze') ?><button class="btn sm" type="submit">Разморозить запись</button></form>
                    <?php else: ?><p>Разморозить может администратор.</p><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <div class="enter">
            <?php
            $vars = compact('user', 'csrf', 'isAdmin', 'canWrite');
            if ($section === 'module') {
                admin_view('module', $vars + ['reply' => $moduleReply, 'moduleName' => (string)$adminModule['page']['module']]);
            } elseif (in_array($section, ['settings', 'module_settings'], true) && !$isAdmin) {
                echo '<div class="notice error">' . icon('lock-key') . '<div>Нужна роль администратора.</div></div>';
            } else {
                admin_view($section, $vars);
            }
            ?>
            </div>
        </div>
    </main>
</div>
