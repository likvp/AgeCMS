<?php
/**
 * @var array<string,mixed> $user
 * @var string $csrf
 * @var bool $isAdmin
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Audit, Config, Tokens};

if (!$isAdmin) {
    echo '<div class="notice error">' . icon('lock-key') . '<div>Нужна роль администратора.</div></div>';
    return;
}

$tokens = Tokens::all();
$allFrozen = Tokens::allFrozen();
$roleNames = ['editor' => 'editor: правит контент', 'viewer' => 'viewer: только чтение', 'admin' => 'admin: полный доступ'];
$groups = ['active' => ['active'], 'held' => ['frozen', 'blocked', 'user_blocked'], 'off' => ['revoked', 'expired']];
$count = static fn(string $g): int => count(array_filter($tokens, static fn($t) => in_array($t['status'], $groups[$g], true)));
$filter = isset($groups[$_GET['f'] ?? '']) ? (string)$_GET['f'] : '';
$card = isset($_GET['id']) ? Tokens::find((int)$_GET['id']) : null;

/** Buttons for one token: what can be done with it in its current state. */
$controls = static function (array $t, bool $withReason) use ($csrf): string {
    $id = '<input type="hidden" name="id" value="' . (int)$t['id'] . '">';
    $name = h($t['name']);
    $reason = $withReason ? '<input class="in sm" type="text" name="reason" maxlength="200" placeholder="причина, необязательно" aria-label="Причина">' : '';
    $out = '';
    if (in_array($t['status'], ['active', 'user_blocked', 'expired'], true)) {
        $terms = '';
        foreach (Tokens::TERMS as $hours => $label) {
            $terms .= '<option value="' . $hours . '"' . ($hours === 24 ? ' selected' : '') . '>на ' . h($label) . '</option>';
        }
        $out .= '<form method="post" class="inline hold-form">' . admin_post_fields($csrf, 'token_freeze') . $id . '<select class="in sm" name="hours" aria-label="Срок заморозки ' . $name . '">' . $terms . '</select>' . $reason
            . '<button class="btn sm ghost" type="submit">' . icon('snowflake') . 'Заморозить</button></form>';
    }
    if ($t['status'] === 'frozen') {
        $out .= '<form method="post" class="inline">' . admin_post_fields($csrf, 'token_unfreeze') . $id . '<button class="btn sm ghost" type="submit">' . icon('sun') . 'Разморозить</button></form>';
    }
    if ($t['status'] === 'blocked') {
        $out .= '<form method="post" class="inline">' . admin_post_fields($csrf, 'token_unblock') . $id . '<button class="btn sm ghost" type="submit">' . icon('check-circle') . 'Разблокировать</button></form>';
    } elseif ($t['status'] !== 'revoked') {
        $out .= '<form method="post" class="inline hold-form" data-confirm="Заблокировать токен «' . $name . '»?" data-confirm-text="Агент сразу получит отказ и не сможет работать, пока вы не снимете блокировку. Токен, роль и история останутся." data-confirm-ok="Заблокировать" data-danger>'
            . admin_post_fields($csrf, 'token_block') . $id . $reason . '<button class="btn sm ghost" type="submit">' . icon('lock-key') . 'Заблокировать</button></form>';
    }
    if ($t['status'] !== 'revoked') {
        $out .= '<form method="post" class="inline" data-confirm="Отнять токен «' . $name . '» навсегда?" data-confirm-text="Агент сразу потеряет доступ, вернуть этот токен нельзя: понадобится новый код подключения. История его правок останется." data-confirm-ok="Отнять" data-danger>'
            . admin_post_fields($csrf, 'token_revoke') . $id . ($withReason
                ? '<button class="btn sm ghost danger" type="submit">' . icon('x-circle') . 'Отнять навсегда</button>'
                : '<button class="ib danger" type="submit" title="Отнять навсегда" aria-label="Отнять токен ' . $name . ' навсегда">' . icon('x-circle') . '</button>') . '</form>';
    }
    return $out;
};

/** The status tag with until when and why. */
$statusCell = static function (array $t): string {
    [$label, $kind] = Tokens::STATUS_LABELS[$t['status']] ?? [$t['status'], 'plain'];
    $out = '<span class="tag ' . $kind . '">' . h($label) . '</span>';
    $detail = match ($t['status']) {
        'frozen' => 'до ' . admin_date($t['frozen_until']),
        'blocked' => 'с ' . admin_date($t['blocked_at']),
        'revoked' => admin_date($t['revoked_at']),
        default => '',
    };
    if (in_array($t['status'], ['frozen', 'blocked'], true) && $t['hold_reason'] !== '') {
        $detail .= ($detail !== '' ? ', ' : '') . h($t['hold_reason']);
    }
    return $out . ($detail !== '' ? '<br><small class="muted">' . $detail . '</small>' : '');
};
?>
<div class="pagehead"><div class="grow">
    <h1><?= $card !== null ? 'Агент ' . h($card['name']) : 'ИИ-агенты' ?></h1>
    <p><?= $card !== null ? 'Доступ этого агента и всё, что он сделал на сайте.' : 'Какие агенты имеют доступ к сайту, когда обращались и сколько правили. Токен можно заморозить на время, заблокировать до снятия или отнять навсегда, как пользователя.' ?></p>
</div><?php if ($card !== null): ?><a class="btn" href="<?= h(admin_url('agents')) ?>"><?= icon('arrow-left') ?>Все агенты</a><?php endif; ?></div>

<?php if ($allFrozen !== null): ?>
<div class="notice warn" role="status"><?= icon('snowflake') ?><div>
    <p><b>Все ИИ-агенты заморожены</b> до <?= admin_date($allFrozen['until']) ?><?= $allFrozen['reason'] !== '' ? ': ' . h($allFrozen['reason']) : '' ?>. Любой токен, в том числе новый, получает отказ. Люди работают как обычно.</p>
    <form method="post"><?= admin_post_fields($csrf, 'agents_unfreeze') ?><button class="btn sm" type="submit"><?= icon('sun') ?>Разморозить всех</button></form>
</div></div>
<?php endif; ?>

<?php if ($card !== null): $t = $card; $actions = Tokens::actions((string)$t['name']); ?>
<section class="panel">
    <div class="panel-h"><h2>Доступ</h2><?= $statusCell($t) ?></div>
    <div class="tbl-scroll"><table class="tbl"><tbody>
        <tr><th>Токен</th><td class="mono"><?= h($t['name']) ?></td></tr>
        <tr><th>Пользователь</th><td><?= $t['username'] !== null ? h($t['username']) . ($t['status'] === 'user_blocked' ? ' <span class="tag err">заблокирован</span>' : '') . ' <a href="' . h(admin_url('users')) . '">в разделе «Пользователи»</a>' : '<span class="muted">свой, без пользователя</span>' ?></td></tr>
        <tr><th>Роль</th><td>
            <?php if ($t['status'] !== 'revoked'): ?>
            <form method="post" class="role-form"><?= admin_post_fields($csrf, 'token_role') ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <select class="in sm" name="role" aria-label="Роль токена" data-autosubmit><?php foreach ($roleNames as $k => $label): ?><option value="<?= h($k) ?>"<?= $t['role'] === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
                <noscript><button class="btn sm" type="submit">Сохранить</button></noscript>
            </form>
            <?php else: ?><span class="tag plain"><?= h($t['role']) ?></span><?php endif; ?>
        </td></tr>
        <tr><th>Выдан</th><td class="num"><?= admin_date($t['created_at']) ?></td></tr>
        <tr><th>Действует до</th><td class="num"><?= $t['expires_at'] === null ? 'без срока' : admin_date($t['expires_at']) ?></td></tr>
        <tr><th>Последний вызов</th><td class="num"><?= $t['last_used_at'] === null ? 'не было' : admin_date($t['last_used_at']) . ($t['last_ip'] !== '' ? ', адрес ' . h($t['last_ip']) : '') ?></td></tr>
        <tr><th>Правок за 30 дней</th><td class="num"><?= (int)$t['actions_30d'] ?></td></tr>
        <?php if (in_array($t['status'], ['frozen', 'blocked'], true) && $t['hold_by'] !== ''): ?><tr><th>Кто остановил</th><td><?= h($t['hold_by']) ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($t['status'] !== 'revoked'): ?><div class="panel-b"><div class="actions-row"><?= $controls($t, true) ?></div>
        <p class="muted">Заморозка проходит сама в конце срока. Блокировка держится, пока вы её не снимете. «Отнять» отзывает токен навсегда.</p></div><?php endif; ?>
</section>
<section class="panel">
    <div class="panel-h"><h2>Что делал агент</h2><span class="muted">последние <?= count($actions) ?></span></div>
    <?php if ($actions === []): ?>
        <div class="empty"><?= icon('clock-counter-clockwise') ?><b>Правок нет</b><span>Здесь появятся страницы, меню и настройки, которые агент менял этим токеном. Чтение в журнал не пишется.</span></div>
    <?php else: ?>
    <div class="tbl-scroll"><table class="tbl"><thead><tr><th>#</th><th>Когда</th><th>Где</th><th>Что</th></tr></thead><tbody>
        <?php foreach ($actions as $a): ?>
            <tr><td class="num muted"><?= (int)$a['id'] ?></td><td class="num muted"><?= admin_date($a['created_at']) ?></td>
                <td><a class="mono" href="<?= h(admin_url('history', ['slug' => $a['page_slug']])) ?>"><?= $a['page_slug'][0] === '@' ? h(Audit::label($a['page_slug']) ?? $a['page_slug']) : '/' . h($a['page_slug']) ?></a></td>
                <td><?= h($a['action_desc']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php else: ?>

<div class="stats">
    <div class="stat"><div class="v"><?= $count('active') ?></div><div class="l">работают</div></div>
    <div class="stat"><div class="v"><?= $count('held') ?></div><div class="l">заморожены или заблокированы</div></div>
    <div class="stat"><div class="v"><?= $count('off') ?></div><div class="l">отозваны или истекли</div></div>
    <div class="stat"><div class="v"><?= array_sum(array_column($tokens, 'actions_30d')) ?></div><div class="l">правок агентов за 30 дней</div></div>
</div>

<section class="panel">
    <div class="panel-h"><h2>Токены</h2></div>
    <nav class="tabs" aria-label="Какие токены показать">
        <?php foreach (['' => 'Все', 'active' => 'Работают', 'held' => 'Остановлены', 'off' => 'Без доступа'] as $f => $label): ?>
            <a href="<?= h(admin_url('agents', $f !== '' ? ['f' => $f] : [])) ?>"<?= $filter === $f ? ' aria-current="page"' : '' ?>><?= h($label) ?> <span class="muted"><?= $f === '' ? count($tokens) : $count($f) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php $shown = $filter === '' ? $tokens : array_values(array_filter($tokens, static fn($t) => in_array($t['status'], $groups[$filter], true))); ?>
    <?php if ($shown === []): ?>
        <div class="empty"><?= icon('key') ?><b><?= $tokens === [] ? 'Агентов пока нет' : 'Здесь пусто' ?></b><span><?= $tokens === [] ? 'Подключите агента кнопкой ниже: он получит свой токен и появится в этом списке.' : 'В этой группе токенов нет.' ?></span></div>
    <?php else: ?>
    <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Агент</th><th>Состояние</th><th>Действует до</th><th>Последний вызов</th><th title="Правок за 30 дней">Правок</th><th></th></tr></thead><tbody>
        <?php foreach ($shown as $t): ?>
            <tr>
                <td><a class="mono nowrap" href="<?= h(admin_url('agents', ['id' => (int)$t['id']])) ?>"><?= h($t['name']) ?></a> <span class="tag plain"><?= h($t['role']) ?></span><?= $t['username'] !== null ? '<br><small class="muted">' . h($t['username']) . '</small>' : '' ?></td>
                <td><?= $statusCell($t) ?></td>
                <td class="num muted"><?= $t['expires_at'] === null ? 'без срока' : admin_date($t['expires_at']) ?></td>
                <td class="num muted"><?= $t['last_used_at'] === null ? 'не было' : admin_date($t['last_used_at']) . ($t['last_ip'] !== '' ? '<br><small>' . h($t['last_ip']) . '</small>' : '') ?></td>
                <td class="num"><?= (int)$t['actions_30d'] ?></td>
                <td class="r"><div class="actions"><?= $controls($t, false) ?></div></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-h"><h2>Заморозить всех агентов</h2></div>
    <form method="post" class="panel-b" data-confirm="Заморозить всех ИИ-агентов?" data-confirm-text="Каждый токен, в том числе выданный за это время, получит отказ до конца срока. Люди в админке работают как обычно." data-confirm-ok="Заморозить" data-danger>
        <?= admin_post_fields($csrf, 'agents_freeze') ?>
        <p>На случай, когда агенты делают что-то не то, а разбираться с каждым некогда. Заморозка проходит сама в конце срока, раньше её снимает кнопка «Разморозить всех».</p>
        <div class="row">
            <label class="f"><span>На сколько</span><select class="in" name="hours"><?php foreach (Tokens::TERMS as $hours => $label): ?><option value="<?= $hours ?>"<?= $hours === 24 ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
            <label class="f"><span>Причина <small>(агенты её увидят)</small></span><input class="in" type="text" name="reason" maxlength="200" placeholder="разбираемся с правками"></label>
        </div>
        <button class="btn" type="submit"><?= icon('snowflake') ?>Заморозить всех</button>
    </form>
</section>

<?php
    $selfIssue = Config::get('agent_access.self_issue', true) === true;
    $tokenDays = (int)Config::get('agent_access.token_days', 90);
    $beforeAgent = Config::get('backup.before_agent', true) === true;
?>
<section class="panel">
    <div class="panel-h"><h2>Подключить ИИ-агента</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'invite_create') ?>
        <p>Нажмите кнопку, скопируйте готовый текст и вставьте его в чат с агентом. В нём адрес сайта, ссылка на инструкцию <a href="/AGENT.md" target="_blank" rel="noopener"><code>/AGENT.md</code></a> и одноразовый код. Агент обменяет код на собственный токен, пароль ему не нужен. Код действует сутки и сработает один раз.</p>
        <div class="row">
            <label class="f"><span>Что разрешить</span><select class="in" name="role"><option value="editor">Править контент (editor)</option><option value="viewer">Только читать (viewer)</option><option value="admin">Всё, включая настройки (admin)</option></select></label>
            <label class="f"><span>На сколько дней токен <small>(0 без срока)</small></span><input class="in" type="number" name="token_days" min="0" max="3650" value="<?= $tokenDays ?>"></label>
            <label class="f"><span>Пометка <small>(для себя)</small></span><input class="in" type="text" name="note" maxlength="80" placeholder="сео-аудит"></label>
        </div>
        <button class="btn primary" type="submit"><?= icon('key') ?>Получить текст для агента</button>
    </form>
</section>
<section class="panel">
    <div class="panel-h"><h2>Правила для агентов</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'agent_access_save') ?>
        <div class="f">
            <label class="chk"><input type="checkbox" name="self_issue" value="1"<?= $selfIssue ? ' checked' : '' ?>><span>Агент может получить токен по логину и паролю пользователя <small>(для тех, кому вы дали логин)</small></span></label>
            <label class="chk"><input type="checkbox" name="before_agent" value="1"<?= $beforeAgent ? ' checked' : '' ?>><span>Делать копию базы перед правками агента <small>(одна на сеанс, хранятся последние пять)</small></span></label>
        </div>
        <label class="f"><span>Срок жизни токена по умолчанию, дней <small>(0 без срока; агент предупреждается за неделю до конца)</small></span><input class="in" type="number" name="token_days" min="0" max="3650" value="<?= $tokenDays ?>"></label>
        <button class="btn primary" type="submit">Сохранить</button>
    </form>
</section>
<section class="panel">
    <div class="panel-h"><h2>Токен вручную</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'token_create') ?>
        <p class="muted">Токен вручную нужен, если агент не может обменять код сам. Его показывают один раз.</p>
        <div class="row">
            <label class="f"><span>Имя токена</span><input class="in" type="text" name="name" placeholder="seo-agent" required pattern="[A-Za-z0-9_.\-]{2,40}" autocapitalize="none" spellcheck="false"></label>
            <label class="f"><span>Роль</span><select class="in" name="role"><?php foreach ($roleNames as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
            <label class="f"><span>Дней <small>(0 без срока)</small></span><input class="in" type="number" name="days" min="0" max="3650" value="<?= $tokenDays ?>"></label>
            <button class="btn primary" type="submit"><?= icon('key') ?>Создать токен вручную</button>
        </div>
    </form>
</section>
<?php endif; ?>
