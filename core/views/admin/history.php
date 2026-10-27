<?php
/** @var string $csrf */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\Revisions;

$slug = (string)($_GET['slug'] ?? '');
$rows = $slug !== '' ? Revisions::history($slug, 100) : Revisions::recent(100);
?>
<h1>История изменений<?= $slug !== '' ? ': /' . h($slug) : '' ?></h1>
<?php if ($slug !== ''): ?><p><a href="<?= h(admin_url('history')) ?>">Показать все страницы</a></p><?php endif; ?>
<table>
    <tr><th>#</th><th>Когда</th><th>Страница</th><th>Кто</th><th>Что</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= h($r['created_at']) ?></td>
            <td><?= h($r['page_slug']) ?></td>
            <td><?= h($r['actor_type']) ?>: <?= h($r['actor_name']) ?></td>
            <td><?= h($r['action_desc']) ?></td>
            <td><?php if ($r['page_slug'][0] !== '@'): ?>
                <form method="post" class="inline" onsubmit="return confirm('Вернуть страницу к состоянию после этой ревизии?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="rollback"><input type="hidden" name="slug" value="<?= h($r['page_slug']) ?>"><input type="hidden" name="revision" value="<?= (int)$r['id'] ?>"><button class="link" type="submit">откатить</button></form>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<?php if ($rows === []): ?><p>Пока нет записей.</p><?php endif; ?>
