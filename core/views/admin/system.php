<?php
/**
 * @var string $csrf
 * @var bool $isAdmin
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Backup, Config, Database, Seo, SkillRegistry};

$modules = SkillRegistry::modules();
?>
<div class="pagehead"><div class="grow"><h1>Система</h1><p>Модули, доступ для ИИ-агентов и обслуживание.</p></div></div>

<section class="panel">
    <div class="panel-h"><h2>Обслуживание</h2></div>
    <form method="post" class="panel-b row center">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button class="btn" name="action" value="clear_cache" type="submit"><?= icon('database') ?>Очистить кэш</button>
        <?php if ($isAdmin): ?><button class="btn" name="action" value="backup" type="submit"><?= icon('copy') ?>Сделать бэкап</button><?php endif; ?>
    </form>
    <?php if ($isAdmin): $backups = array_slice(Backup::list(), 0, 5); if ($backups !== []): ?>
        <table class="tbl"><thead><tr><th>Последние бэкапы</th><th class="r">Размер</th></tr></thead><tbody>
        <?php foreach ($backups as $b): ?><tr><td class="mono"><?= h($b['file']) ?></td><td class="r num muted"><?= round($b['size'] / 1024) ?> КБ</td></tr><?php endforeach; ?>
        </tbody></table>
    <?php endif; endif; ?>
</section>

<section class="panel">
    <div class="panel-h"><h2>Модули</h2></div>
    <?php if ($modules === []): ?>
        <div class="empty"><?= icon('seal-check') ?><b>Модулей нет</b><span>Подписанные модули кладутся в каталог modules.</span></div>
    <?php else: ?>
    <table class="tbl"><thead><tr><th>Модуль</th><th>Состояние</th></tr></thead><tbody>
        <?php foreach ($modules as $name => $m): ?>
            <tr><td class="mono"><?= h($name) ?></td><td><?= $m['status'] === 'ok' ? '<span class="tag ok">подпись проверена</span>' : '<span class="tag err">заблокирован</span> <small>' . h($m['reason']) . '</small>' ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</section>

<?php if ($isAdmin): $tokens = Database::rows('SELECT name, role, last_used_at, revoked_at FROM api_tokens ORDER BY id'); $selfIssue = Config::get('agent_access.self_issue', true) === true; ?>
<section class="panel">
    <div class="panel-h"><h2>Доступ для ИИ-агентов</h2><span class="tag <?= $selfIssue ? 'ok' : 'warn' ?>"><?= $selfIssue ? 'агент получает токен сам' : 'выдача вручную' ?></span></div>
    <div class="panel-b">
        <p>Обычно токены создаёте не вы. Дайте агенту адрес сайта, логин и пароль и попросите прочитать инструкцию: она лежит по адресу <a href="/AGENT.md" target="_blank" rel="noopener"><code><?= h(Seo::siteUrl()) ?>/AGENT.md</code></a> и указана в <a href="/llms.txt" target="_blank" rel="noopener"><code>/llms.txt</code></a>. Агент войдёт, выдаст себе токен и появится в списке ниже. Роль токена не выше роли того пользователя, чей пароль вы дали, поэтому для агента удобно создать отдельного пользователя-редактора.</p>
        <p class="muted">Ниже вы видите, кто и когда обращался, и можете отозвать любой токен. Создавать токен вручную нужно только если выдачу выключили.</p>
        <form method="post" class="row center">
            <?= admin_post_fields($csrf, 'agent_access_save') ?>
            <label class="chk"><input type="checkbox" name="self_issue" value="1" data-autosubmit<?= $selfIssue ? ' checked' : '' ?>><span>Разрешить агентам получать токен по логину и паролю</span></label>
            <noscript><button class="btn sm" type="submit">Сохранить</button></noscript>
        </form>
    </div>
</section>
<section class="panel">
    <div class="panel-h"><h2>Токены для ИИ-агентов</h2></div>
    <?php if ($tokens !== []): ?>
    <table class="tbl"><thead><tr><th>Имя</th><th>Роль</th><th>Последний вызов</th><th></th></tr></thead><tbody>
        <?php foreach ($tokens as $t): ?>
            <tr><td class="mono"><?= h($t['name']) ?></td><td><span class="tag plain"><?= h($t['role']) ?></span></td><td class="num muted"><?= $t['last_used_at'] === null ? 'не было' : admin_date($t['last_used_at']) ?></td>
                <td class="r"><?php if ($t['revoked_at'] === null): ?>
                    <form method="post" class="inline" data-confirm="Отозвать токен «<?= h($t['name']) ?>»?" data-confirm-text="Агент с этим токеном сразу потеряет доступ." data-confirm-ok="Отозвать" data-danger>
                        <?= admin_post_fields($csrf, 'token_revoke') ?><input type="hidden" name="name" value="<?= h($t['name']) ?>"><button class="btn sm ghost danger" type="submit">Отозвать</button>
                    </form>
                <?php else: ?><span class="tag err">отозван</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'token_create') ?>
        <div class="row">
            <label class="f"><span>Имя токена</span><input class="in" type="text" name="name" placeholder="seo-agent" required pattern="[A-Za-z0-9_.\-]{2,40}" autocapitalize="none" spellcheck="false"></label>
            <label class="f"><span>Роль</span><select class="in" name="role"><option value="editor">editor: правит контент</option><option value="viewer">viewer: только чтение</option><option value="admin">admin: полный доступ</option></select></label>
            <button class="btn primary" type="submit"><?= icon('key') ?>Создать токен вручную</button>
        </div>
    </form>
</section>
<?php endif; ?>
