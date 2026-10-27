<?php
/**
 * @var array<string,mixed> $user
 * @var string $csrf
 * @var bool $isAdmin
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\Auth;

// Two roles for people: an administrator and everyone else. The read-only role stays for agent tokens and old accounts.
$roles = ['admin' => 'Администратор', 'editor' => 'Редактор'];
$hints = ['admin' => 'всё, включая пользователей, настройки и окончательное удаление', 'editor' => 'правит страницы, меню и картинки, может убрать в корзину'];
$rolesFor = static fn(string $current): array => $current === 'viewer' ? $roles + ['viewer' => 'Наблюдатель'] : $roles;
?>
<div class="pagehead"><div class="grow">
    <h1><?= $isAdmin ? 'Пользователи' : 'Профиль' ?></h1>
    <p><?= $isAdmin ? 'Кто может входить в админку и что ему разрешено.' : 'Здесь меняется ваш пароль.' ?></p>
</div></div>

<?php if ($isAdmin): $users = Auth::listUsers(); ?>
<section class="panel">
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>Логин</th><th>Почта</th><th>Роль</th><th>2 шага</th><th>Создан</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$user['id']; ?>
            <tr>
                <td><b class="cell-title"><?= h($u['username']) ?></b><?= $self ? ' <span class="tag plain">это вы</span>' : '' ?><?= $u['blocked_at'] !== null ? ' <span class="tag err">заблокирован</span>' : '' ?></td>
                <td>
                    <form method="post" class="role-form mail-form"><?= admin_post_fields($csrf, 'user_email') ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <input class="in sm" type="email" name="email" value="<?= h($u['email']) ?>" placeholder="нет почты" maxlength="120" autocomplete="off" aria-label="Почта <?= h($u['username']) ?>">
                        <button class="ib" type="submit" title="Сохранить почту" aria-label="Сохранить почту"><?= icon('check-circle') ?></button>
                    </form>
                </td>
                <td>
                    <?php if ($self): ?><span class="tag info"><?= h($roles[$u['role']] ?? $u['role']) ?></span>
                    <?php else: ?>
                    <form method="post" class="role-form"><?= admin_post_fields($csrf, 'user_role') ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <select class="in sm" name="role" aria-label="Роль <?= h($u['username']) ?>" data-autosubmit><?php foreach ($rolesFor((string)$u['role']) as $k => $label): ?><option value="<?= h($k) ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
                        <noscript><button class="btn sm" type="submit">Сохранить</button></noscript>
                    </form>
                    <?php endif; ?>
                </td>
                <td><?= $u['totp_enabled_at'] !== null ? '<span class="tag ok">включены</span>' : '<span class="muted">нет</span>' ?></td>
                <td class="num muted"><?= admin_date($u['created_at']) ?></td>
                <td class="r"><div class="actions">
                    <?php if (!$self && $u['totp_enabled_at'] !== null): ?>
                    <form method="post" class="inline" data-confirm="Убрать второй шаг входа у <?= h($u['username']) ?>?" data-confirm-text="Для случая, когда телефон и коды восстановления потеряны. Сеансы пользователя закроются, второй шаг он включит заново сам." data-confirm-ok="Убрать" data-danger><?= admin_post_fields($csrf, 'user_totp_reset') ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn sm ghost" type="submit"><?= icon('lock-key') ?>Сбросить 2 шага</button></form>
                    <?php endif; ?>
                    <?php if (!$self): ?>
                    <?php $blocked = $u['blocked_at'] !== null; ?>
                    <form method="post" class="inline"<?= $blocked ? '' : ' data-confirm="Заблокировать ' . h($u['username']) . '?" data-confirm-text="Войти он не сможет, а его токены для агентов перестанут работать. Пользователь и история останутся, блокировку можно снять." data-confirm-ok="Заблокировать" data-danger' ?>><?= admin_post_fields($csrf, 'user_block') ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="blocked" value="<?= $blocked ? '0' : '1' ?>"><button class="btn sm ghost" type="submit"><?= icon($blocked ? 'check-circle' : 'lock-key') ?><?= $blocked ? 'Разблокировать' : 'Заблокировать' ?></button></form>
                    <form method="post" class="inline" data-confirm="Создать новый пароль для <?= h($u['username']) ?>?" data-confirm-text="Старый пароль перестанет работать. Новый покажется один раз." data-confirm-ok="Создать"><?= admin_post_fields($csrf, 'user_reset') ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn sm ghost" type="submit"><?= icon('key') ?>Новый пароль</button></form>
                    <form method="post" class="inline" data-confirm="Удалить пользователя <?= h($u['username']) ?>?" data-confirm-text="Войти он больше не сможет. Его правки в истории останутся." data-confirm-ok="Удалить" data-danger><?= admin_post_fields($csrf, 'user_delete') ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="ib danger" type="submit" title="Удалить" aria-label="Удалить"><?= icon('trash') ?></button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<section class="panel">
    <div class="panel-h"><h2>Новый пользователь</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'user_create') ?>
        <div class="row">
            <label class="f"><span>Логин</span><input class="in" type="text" name="username" required pattern="[A-Za-z0-9_.@\-]{3,40}" autocapitalize="none" spellcheck="false" autocomplete="off"></label>
            <label class="f"><span>Почта <small>(для восстановления пароля)</small></span><input class="in" type="email" name="email" maxlength="120" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
        </div>
        <div class="row">
            <label class="f"><span>Роль</span><select class="in" name="role"><?php foreach ($roles as $k => $label): ?><option value="<?= h($k) ?>"<?= $k === 'editor' ? ' selected' : '' ?>><?= h($label) ?>: <?= h($hints[$k]) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="f"><span class="lbl">Пароль</span>
            <label class="chk"><input type="radio" name="password_mode" value="generate" data-pw-mode checked><span>Создать надёжный пароль и показать его один раз</span></label>
            <label class="chk"><input type="radio" name="password_mode" value="own" data-pw-mode><span>Задать свой <small>(от 10 символов)</small></span></label>
        </div>
        <div id="pw-own"><label class="f"><span>Пароль</span><input class="in" type="password" name="password" minlength="10" autocomplete="new-password"></label></div>
        <button class="btn primary" type="submit"><?= icon('user-plus') ?>Создать пользователя</button>
    </form>
</section>
<section class="panel">
    <div class="panel-h"><h2>Пользователь для ИИ-агента</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'agent_user_create') ?>
        <p>Если агенту нужен именно логин и пароль, создайте ему отдельного пользователя-редактора. Вы получите готовый текст для чата с агентом: адрес, инструкция, логин и пароль. Свой пароль агенту давать не нужно, а лишнего пользователя потом можно заблокировать или удалить. Проще и безопаснее <a href="<?= h(admin_url('system')) ?>">код подключения</a> в разделе «Система»: он одноразовый и без пароля.</p>
        <button class="btn" type="submit"><?= icon('user-plus') ?>Создать пользователя для агента</button>
    </form>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-h"><h2>Мой пароль</h2><span class="muted"><?= h($user['username']) ?></span></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'password_change') ?>
        <label class="f"><span>Текущий пароль</span><input class="in" type="password" name="current_password" required autocomplete="current-password"></label>
        <div class="row">
            <label class="f"><span>Новый пароль</span><input class="in" type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>
            <label class="f"><span>Ещё раз</span><input class="in" type="password" name="new_password2" required minlength="10" autocomplete="new-password"></label>
        </div>
        <p class="muted">От 10 символов. После смены вы останетесь в системе на этом устройстве, а на остальных придётся войти заново.</p>
        <button class="btn primary" type="submit">Сменить пароль</button>
    </form>
</section>

<?php
$totpOn = $user['totp_enabled_at'] !== null;
$setup = $totpOn ? null : Auth::totpPending($user);
?>
<section class="panel" id="totp">
    <div class="panel-h"><h2>Двухфакторный вход</h2><span class="muted"><?= $totpOn ? 'включён' : 'выключен' ?></span></div>
    <?php if ($totpOn): ?>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'totp_disable') ?>
        <p>После пароля у вас спрашивают код из приложения. Кодов восстановления осталось: <b><?= Auth::recoveryLeft((int)$user['id']) ?></b>.</p>
        <label class="f"><span>Чтобы выключить, введите пароль</span><input class="in" type="password" name="current_password" required autocomplete="current-password"></label>
        <button class="btn" type="submit">Выключить</button>
    </form>
    <?php elseif ($setup !== null): ?>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'totp_enable') ?>
        <p>1. В приложении-аутентификаторе (Google Authenticator, Яндекс Ключ, 1Password и другие) добавьте ключ вручную или откройте ссылку на телефоне.</p>
        <p>Ключ: <code id="totp-secret"><?= h($setup['pretty']) ?></code> <button class="btn sm" type="button" data-copy="#totp-secret"><?= icon('copy') ?>Копировать</button></p>
        <p class="muted">Ссылка для приложения: <a href="<?= h($setup['uri']) ?>"><?= h($setup['uri']) ?></a></p>
        <p>2. Введите шесть цифр, которые покажет приложение:</p>
        <label class="f"><span>Код</span><input class="in" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required></label>
        <button class="btn primary" type="submit">Включить</button>
    </form>
    <?php else: ?>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'totp_start') ?>
        <p>Пароль можно украсть или подсмотреть. Второй шаг (шесть цифр из приложения на телефоне) защитит админку, даже если пароль узнали. Включение займёт минуту. Для входа ИИ-агентов это ничего не меняет: у них свои токены и коды подключения. Агенту с логином и паролем такого пользователя токен выдан не будет, нужен код подключения.</p>
        <button class="btn primary" type="submit"><?= icon('lock-key') ?>Включить двухфакторный вход</button>
    </form>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-h"><h2>Моя почта</h2><span class="muted"><?= $user['email'] !== '' ? h($user['email']) : 'не указана' ?></span></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'email_change') ?>
        <div class="row">
            <label class="f"><span>Почта для восстановления пароля</span><input class="in" type="email" name="email" value="<?= h($user['email']) ?>" maxlength="120" autocomplete="email"></label>
            <label class="f"><span>Текущий пароль</span><input class="in" type="password" name="current_password" required autocomplete="current-password"></label>
        </div>
        <p class="muted">На этот адрес придёт ссылка, если вы забудете пароль. Пустое поле убирает адрес.</p>
        <button class="btn primary" type="submit">Сохранить почту</button>
    </form>
</section>
