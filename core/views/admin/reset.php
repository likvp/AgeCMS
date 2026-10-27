<?php
/** @var string $error */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\Auth;

$token = (string)($_GET['token'] ?? '');
$valid = Auth::resetTokenValid($token);
?>
<div class="auth">
    <?php admin_view('auth-art'); ?>
    <main class="auth-form" id="main">
        <div class="box enter">
            <h1>Новый пароль</h1>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= icon('warning-circle') ?><div><?= h($error) ?></div></div><?php endif; ?>
            <?php if (!$valid): ?>
                <div class="notice error" role="alert"><?= icon('warning-circle') ?><div>Ссылка не работает: она уже использована или прошёл час. Запросите новую.</div></div>
                <a class="btn primary block" href="<?= h(admin_url('forgot')) ?>">Запросить ссылку</a>
            <?php else: ?>
            <p class="lead">Придумайте пароль от 10 символов. После смены вы выйдете со всех устройств.</p>
            <form method="post">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <label class="f"><span>Новый пароль</span><input class="in" type="password" name="new_password" required minlength="10" autocomplete="new-password" autofocus></label>
                <label class="f"><span>Ещё раз</span><input class="in" type="password" name="new_password2" required minlength="10" autocomplete="new-password"></label>
                <button class="btn primary block" type="submit">Сохранить пароль</button>
            </form>
            <?php endif; ?>
        </div>
    </main>
</div>
