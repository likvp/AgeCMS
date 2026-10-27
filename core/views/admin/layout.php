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
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{CircuitBreaker, Database, Pages};

$frozen = CircuitBreaker::frozenInfo();
$nav = [
    ['overview', 'Обзор', 'squares-four', null],
    ['pages', 'Страницы', 'file-text', Pages::count()],
    ['menus', 'Меню', 'list', null],
    ['history', 'История', 'clock-counter-clockwise', null],
    ['leads', 'Заявки', 'chat-circle-text', (int)(Database::row('SELECT COUNT(*) AS n FROM leads')['n'] ?? 0)],
];
if ($isAdmin) {
    $nav[] = ['settings', 'Настройки', 'gear-six', null];
}
$nav[] = ['system', 'Система', 'terminal-window', null];
$active = $section === 'edit' ? 'pages' : $section;
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
                <span class="avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string)$user['username'], 0, 1))) ?></span>
                <span class="nm"><b><?= h($user['username']) ?></b><small><?= h($roleNames[$user['role']] ?? $user['role']) ?></small></span>
                <form method="post" class="inline"><?= admin_post_fields($csrf, 'logout') ?><button class="ib" type="submit" title="Выйти" aria-label="Выйти"><?= icon('sign-out') ?></button></form>
            </div>
        </div>
    </aside>
    <main class="main" id="main">
        <div class="wrap<?= $section === 'edit' ? ' wide' : '' ?>">
            <?php if ($flash !== ''): ?><div class="notice ok" role="status"><?= icon('check-circle') ?><div><?= h($flash) ?></div></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= icon('warning-circle') ?><div><?= h($error) ?></div></div><?php endif; ?>
            <?php if ($newToken !== ''): ?>
                <div class="notice warn" role="status"><?= icon('key') ?><div>
                    <p><b>Скопируйте токен сейчас.</b> Потом он не показывается.</p>
                    <p class="row center"><code id="new-token"><?= h($newToken) ?></code> <button class="btn sm" type="button" data-copy="#new-token"><?= icon('copy') ?>Копировать</button></p>
                </div></div>
            <?php endif; ?>
            <?php if ($frozen !== null): ?>
                <div class="notice error" role="alert"><?= icon('snowflake') ?><div>
                    <p><b>Запись заморожена предохранителем</b> с <?= h($frozen['since']) ?>: <?= h($frozen['reason']) ?></p>
                    <?php if ($isAdmin): ?>
                        <form method="post"><?= admin_post_fields($csrf, 'unfreeze') ?><button class="btn sm" type="submit">Разморозить запись</button></form>
                    <?php else: ?><p>Разморозить может администратор.</p><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <div class="enter">
            <?php
            $vars = compact('user', 'csrf', 'isAdmin', 'canWrite');
            if ($section === 'settings' && !$isAdmin) {
                echo '<div class="notice error">' . icon('lock-key') . '<div>Нужна роль администратора.</div></div>';
            } else {
                admin_view($section, $vars);
            }
            ?>
            </div>
        </div>
    </main>
</div>
