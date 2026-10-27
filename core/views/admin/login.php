<?php
/**
 * @var string $error
 * @var bool $resetDone
 */
defined('AGECMS_BOOTED') || exit;
?>
<div class="auth">
    <?php admin_view('auth-art'); ?>
    <main class="auth-form" id="main">
        <div class="box enter">
            <h1>Вход в админку</h1>
            <p class="lead">Введите логин и пароль администратора.</p>
            <?php if (!empty($resetDone)): ?><div class="notice ok" role="status"><?= icon('check-circle') ?><div>Пароль изменён. Войдите с новым паролем.</div></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= icon('warning-circle') ?><div><?= h($error) ?></div></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label class="f"><span>Логин</span><input class="in" type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
                <label class="f"><span>Пароль</span><input class="in" type="password" name="password" autocomplete="current-password" required></label>
                <button class="btn primary block" type="submit">Войти</button>
            </form>
            <p class="auth-alt"><a href="<?= h(admin_url('forgot')) ?>">Забыли пароль?</a></p>
            <p class="muted auth-note">ИИ-агентам: инструкция и порядок получения доступа лежат по адресу <a href="/AGENT.md">/AGENT.md</a>.</p>
        </div>
    </main>
</div>
