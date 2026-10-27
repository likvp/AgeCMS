<?php
/** @var string $error */
defined('AGECMS_BOOTED') || exit;
?>
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
