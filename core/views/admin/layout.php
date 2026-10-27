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

use AgeCMS\CircuitBreaker;

$frozen = CircuitBreaker::frozenInfo();
$tabs = ['pages' => 'Страницы', 'menus' => 'Меню', 'history' => 'История', 'leads' => 'Заявки'];
if ($isAdmin) {
    $tabs['settings'] = 'Настройки';
}
$tabs['system'] = 'Система';
$active = $section === 'edit' ? 'pages' : $section;
?>
<header class="site-header"><div class="container bar">
    <strong>AgeCMS</strong>
    <nav class="nav">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= h(admin_url($key)) ?>"<?= $active === $key ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
        <a href="/" target="_blank" rel="noopener">Сайт</a>
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
            <?php else: ?>Разморозить может администратор.<?php endif; ?>
        </section>
    <?php endif; ?>
    <?php
    $vars = compact('user', 'csrf', 'isAdmin', 'canWrite');
    switch ($section) {
        case 'settings':
            $isAdmin ? admin_view('settings', $vars) : print('<p class="notice error">Нужна роль администратора.</p>');
            break;
        case 'system':
            admin_view('system', $vars);
            break;
        default:
            admin_view($section, $vars);
    }
    ?>
</main>
