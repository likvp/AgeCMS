<?php
/** @var string $csrf */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Audit, Content, Revisions};

Audit::catchUp();
$slug = (string)($_GET['slug'] ?? '');
$rows = $slug !== '' ? Revisions::history($slug, 100) : Revisions::recent(100);
$kinds = ['human' => ['человек', 'info'], 'agent' => ['агент', 'warn'], 'system' => ['система', 'plain'], 'cli' => ['консоль', 'plain']];
?>
<div class="pagehead">
    <div class="grow"><h1>История изменений<?= $slug !== '' ? ': /' . h($slug) : '' ?></h1>
        <p>Каждое действие записано навсегда: правки страниц (их можно откатить), настройки, обновления ядра, пользователи и токены, модули, почта, бэкапы. Пароли и ключи в журнал не попадают.</p></div>
    <?php if ($slug !== ''): ?><a class="btn" href="<?= h(admin_url('history')) ?>">Все страницы</a><?php endif; ?>
</div>
<section class="panel">
<?php if ($rows === []): ?>
    <div class="empty"><?= icon('clock-counter-clockwise') ?><b>Пока нет записей</b><span>Здесь появятся все правки страниц, меню и настроек, а также обновления ядра, пользователи, токены, модули, почта и бэкапы.</span></div>
<?php else: ?>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>#</th><th>Когда</th><th>Страница</th><th>Кто</th><th>Что</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): [$kl, $kc] = $kinds[$r['actor_type']] ?? [$r['actor_type'], 'plain']; ?>
            <tr>
                <td class="num muted"><?= (int)$r['id'] ?></td>
                <td class="num muted"><?= admin_date($r['created_at']) ?></td>
                <td><a href="<?= h(admin_url('history', ['slug' => $r['page_slug']])) ?>" class="mono"><?= $r['page_slug'][0] === '@' ? h(Audit::label($r['page_slug']) ?? $r['page_slug']) : '/' . h($r['page_slug']) ?></a></td>
                <td><span class="tag <?= h($kc) ?>"><?= h($kl) ?></span> <?= h($r['actor_name']) ?></td>
                <td><?= h($r['action_desc']) ?></td>
                <td class="r"><?php if ($r['page_slug'][0] !== '@' && !in_array($r['action_desc'], [Content::DELETE_DESC, Content::PURGE_DESC], true)): ?>
                    <form method="post" class="inline" data-confirm="Вернуть страницу к ревизии #<?= (int)$r['id'] ?>?" data-confirm-text="Текущее состояние сохранится в истории, откат можно будет отменить." data-confirm-ok="Откатить">
                        <?= admin_post_fields($csrf, 'rollback') ?><input type="hidden" name="slug" value="<?= h($r['page_slug']) ?>"><input type="hidden" name="revision" value="<?= (int)$r['id'] ?>">
                        <button class="btn sm ghost" type="submit"><?= icon('clock-counter-clockwise') ?>Откатить</button>
                    </form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</section>
