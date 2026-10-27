<?php
/**
 * @var string $error
 * @var string $notice
 */
defined('AGECMS_BOOTED') || exit;
?>
<div class="auth">
    <?php admin_view('auth-art'); ?>
    <main class="auth-form" id="main">
        <div class="box enter">
            <h1>Восстановление пароля</h1>
            <p class="lead">Введите логин или почту. Мы отправим ссылку, по которой можно задать новый пароль.</p>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= icon('warning-circle') ?><div><?= h($error) ?></div></div><?php endif; ?>
            <?php if ($notice !== ''): ?>
                <div class="notice ok" role="status"><?= icon('check-circle') ?><div><?= h($notice) ?></div></div>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="forgot">
                <label class="f"><span>Логин или почта</span><input class="in" type="text" name="identifier" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="120" required autofocus></label>
                <button class="btn primary block" type="submit">Отправить ссылку</button>
            </form>
            <?php endif; ?>
            <p class="auth-alt"><a href="<?= h(admin_url('overview')) ?>">Назад ко входу</a></p>
            <p class="muted auth-note">Письмо придёт, только если для пользователя указана почта. Если её нет или письмо не приходит, новый пароль может создать администратор в разделе «Пользователи».</p>
        </div>
    </main>
</div>
