<?php
/**
 * @var string $csrf
 * @var bool $canWrite
 * @var bool $isAdmin
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Media, Pages};

$pages = $canWrite ? Pages::deleted() : [];
$images = $canWrite ? Media::trashed() : [];
$fmt = static fn(int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '') . ' МБ' : max(1, (int)round($b / 1024)) . ' КБ';
?>
<div class="pagehead"><div class="grow">
    <h1>Корзина</h1>
    <p>Удалённые страницы и картинки лежат здесь, пока их не вернут или не удалят навсегда. Сами они не стираются.</p>
</div><span class="muted"><?= count($pages) + count($images) ?> шт.</span></div>

<?php if (!$canWrite): ?>
    <div class="notice error"><?= icon('lock-key') ?><div>Корзина доступна редакторам и администраторам.</div></div>
<?php return; endif; ?>
<?php if (!$isAdmin && ($pages !== [] || $images !== [])): ?>
    <div class="notice info trash-note"><?= icon('lock-key') ?><div>Вернуть можно любую запись. Удалить навсегда может только администратор.</div></div>
<?php endif; ?>

<?php if ($pages === [] && $images === []): ?>
    <div class="panel"><div class="empty"><?= icon('trash') ?><b>Корзина пуста</b><span>Здесь появятся удалённые страницы и картинки.</span></div></div>
<?php endif; ?>

<?php if ($pages !== []): ?>
<section class="panel">
    <div class="panel-h"><h2>Страницы</h2><span class="muted"><?= count($pages) ?> шт.</span></div>
    <div class="tbl-scroll"><table class="tbl"><tbody>
        <?php foreach ($pages as $d): ?>
            <tr>
                <td><span class="mono">/<?= h($d['slug']) ?></span><span class="cell-sub">удалил <?= h($d['actor_name']) ?>, <?= admin_date($d['created_at']) ?></span></td>
                <td class="r"><div class="actions">
                    <form method="post" class="inline"><?= admin_post_fields($csrf, 'page_restore') ?><input type="hidden" name="slug" value="<?= h($d['slug']) ?>"><button class="btn sm" type="submit"><?= icon('arrow-counter-clockwise') ?>Восстановить</button></form>
                    <?php if ($isAdmin): ?>
                    <form method="post" class="inline" data-confirm="Удалить страницу /<?= h($d['slug']) ?> навсегда?" data-confirm-text="Вернуть её из корзины будет нельзя. Записи в истории останутся для учёта, но страница больше не восстанавливается." data-confirm-ok="Удалить навсегда" data-danger><?= admin_post_fields($csrf, 'page_purge') ?><input type="hidden" name="slug" value="<?= h($d['slug']) ?>"><button class="ib danger" type="submit" title="Удалить навсегда" aria-label="Удалить навсегда"><?= icon('trash') ?></button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

<?php if ($images !== []): ?>
<section class="sect">
    <div class="panel-h"><h2>Картинки</h2><span class="muted"><?= count($images) ?> шт.</span></div>
    <div class="gallery">
        <?php foreach ($images as $m): ?>
            <figure class="shot gone">
                <span class="thumb"><img src="/admin/upload.php?trash=<?= h($m['name']) ?>" alt="" loading="lazy" decoding="async"></span>
                <figcaption>
                    <span class="muted"><?= $fmt($m['size']) ?> &middot; удалена <?= admin_date(gmdate('Y-m-d H:i', $m['time'])) ?></span>
                    <span class="actions-row">
                        <form method="post" class="inline"><?= admin_post_fields($csrf, 'media_restore') ?><input type="hidden" name="name" value="<?= h($m['name']) ?>"><button class="btn sm" type="submit"><?= icon('arrow-counter-clockwise') ?>Восстановить</button></form>
                        <?php if ($isAdmin): ?>
                        <form method="post" class="inline" data-confirm="Удалить картинку навсегда?" data-confirm-text="Файл будет стёрт с сервера, вернуть его будет нельзя." data-confirm-ok="Удалить навсегда" data-danger><?= admin_post_fields($csrf, 'media_purge') ?><input type="hidden" name="name" value="<?= h($m['name']) ?>"><button class="ib danger" type="submit" title="Удалить навсегда" aria-label="Удалить навсегда"><?= icon('trash') ?></button></form>
                        <?php endif; ?>
                    </span>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
