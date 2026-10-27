<?php
/**
 * @var string $error
 */
defined('AGECMS_BOOTED') || exit;
?>
<div class="auth">
    <?php admin_view('auth-art'); ?>
    <main class="auth-form" id="main">
        <div class="box enter">
            <h1>Код из приложения</h1>
            <p class="lead">Откройте приложение-аутентификатор и введите шесть цифр для этого сайта. Потеряли телефон? Подойдёт один из кодов восстановления.</p>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= icon('warning-circle') ?><div><?= h($error) ?></div></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login_2fa">
                <label class="f"><span>Код</span><input class="in" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" maxlength="12" required autofocus></label>
                <button class="btn primary block" type="submit">Войти</button>
            </form>
            <form method="post" class="auth-alt"><input type="hidden" name="action" value="login_2fa_cancel"><button class="btn ghost" type="submit">Назад</button></form>
        </div>
    </main>
</div>
