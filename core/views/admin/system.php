<?php
/**
 * @var string $csrf
 * @var bool $isAdmin
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Backup, Database, SkillRegistry};

$modules = SkillRegistry::modules();
?>
<h1>Система</h1>

<h2>Модули</h2>
<table>
    <tr><th>Модуль</th><th>Состояние</th></tr>
    <?php foreach ($modules as $name => $m): ?>
        <tr><td><?= h($name) ?></td><td><?= $m['status'] === 'ok' ? 'подпись проверена' : 'заблокирован: ' . h($m['reason']) ?></td></tr>
    <?php endforeach; ?>
</table>

<?php if ($isAdmin): ?>
<h2>Токены для ИИ-агентов</h2>
<table>
    <tr><th>Имя</th><th>Роль</th><th>Последний вызов</th><th></th></tr>
    <?php foreach (Database::rows('SELECT name, role, last_used_at, revoked_at FROM api_tokens ORDER BY id') as $t): ?>
        <tr><td><?= h($t['name']) ?></td><td><?= h($t['role']) ?></td><td><?= h($t['last_used_at'] ?? 'не было') ?></td>
            <td><?php if ($t['revoked_at'] === null): ?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="token_revoke"><input type="hidden" name="name" value="<?= h($t['name']) ?>"><button class="link" type="submit">отозвать</button></form><?php else: ?>отозван<?php endif; ?></td></tr>
    <?php endforeach; ?>
</table>
<form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="token_create">
    <input name="name" placeholder="имя, например seo-agent" required pattern="[A-Za-z0-9_.\-]{2,40}">
    <select name="role"><option value="editor">editor</option><option value="viewer">viewer</option><option value="admin">admin</option></select>
    <button class="btn small" type="submit">Создать токен</button>
</form>
<?php endif; ?>

<h2>Обслуживание</h2>
<form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <?php if ($isAdmin): ?><button class="btn small" name="action" value="backup" type="submit">Сделать бэкап</button><?php endif; ?>
    <button class="btn small" name="action" value="clear_cache" type="submit">Очистить кэш</button>
</form>
<?php if ($isAdmin): foreach (array_slice(Backup::list(), 0, 5) as $b): ?><p><small><?= h($b['file']) ?>, <?= round($b['size'] / 1024) ?> КБ</small></p><?php endforeach; endif; ?>
