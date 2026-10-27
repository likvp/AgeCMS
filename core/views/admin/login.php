<?php
/** @var string $error */
defined('AGECMS_BOOTED') || exit;
?>
<div class="auth">
    <aside class="auth-art">
        <a class="brand" href="/"><span class="mark">A</span><span>AgeCMS</span></a>
        <div>
            <h2>Сайт, который одинаково удобно вести человеку и ИИ-агенту</h2>
            <ul>
                <li><?= icon('clock-counter-clockwise') ?><span>Каждая правка сохраняется в истории и откатывается в один клик</span></li>
                <li><?= icon('seal-check') ?><span>Страницы отдаются из готового кэша, без базы на каждый запрос</span></li>
                <li><?= icon('lock-key') ?><span>Запись под защитой: роли, токены и предохранитель</span></li>
            </ul>
        </div>
    </aside>
    <main class="auth-form" id="main">
        <div class="box enter">
            <h1>Вход в админку</h1>
            <p class="lead">Введите логин и пароль администратора.</p>
            <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= icon('warning-circle') ?><div><?= h($error) ?></div></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label class="f"><span>Логин</span><input class="in" type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
                <label class="f"><span>Пароль</span><input class="in" type="password" name="password" autocomplete="current-password" required></label>
                <button class="btn primary block" type="submit">Войти</button>
            </form>
        </div>
    </main>
</div>
