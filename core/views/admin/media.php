<?php
/**
 * @var string $csrf
 * @var bool $canWrite
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\Media;

$items = Media::items();
$total = array_sum(array_column($items, 'size'));
$fmt = static fn(int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '') . ' МБ' : max(1, (int)round($b / 1024)) . ' КБ';
?>
<div class="pagehead">
    <div class="grow">
        <h1>Медиа</h1>
        <p>Картинки для страниц и соцсетей. Каждая сжимается в WebP, лишние данные (геометки, камера) удаляются. До 10 МБ за файл.</p>
    </div>
    <span class="muted"><?= count($items) ?> шт., <?= $fmt((int)$total) ?></span>
</div>

<?php if (isset($_GET['up'])): ?><div class="notice ok"><?= icon('check-circle') ?><div>Загружено: <?= (int)$_GET['up'] ?></div></div><?php endif; ?>
<?php if (!Media::available()): ?>
    <div class="notice error"><?= icon('warning-circle') ?><div>Для загрузки нужно расширение GD с поддержкой WebP. Включите его в настройках PHP на хостинге.</div></div>
<?php elseif ($canWrite): ?>
<form method="post" enctype="multipart/form-data" class="drop nojs" id="drop" data-upload>
    <?= admin_post_fields($csrf, 'media_upload') ?>
    <?= icon('upload-simple') ?>
    <b>Перетащите картинки сюда</b>
    <span class="muted">или выберите на устройстве: JPEG, PNG, GIF, WebP</span>
    <input class="sr" id="drop-file" type="file" name="files[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
    <label class="btn" for="drop-file">Выбрать файлы</label>
    <button class="btn primary" type="submit" data-nojs-only>Загрузить</button>
</form>
<?php endif; ?>

<section class="sect">
<?php if ($items === []): ?>
    <div class="panel"><div class="empty"><?= icon('image') ?><b>Картинок пока нет</b><span>Загрузите первую, и её можно будет вставить в блок или поставить на страницу для соцсетей.</span></div></div>
<?php else: ?>
    <div class="gallery" id="gallery">
        <?php foreach ($items as $m): $used = Media::usage($m['name']); ?>
            <figure class="shot">
                <a class="thumb" href="<?= h($m['url']) ?>" target="_blank" rel="noopener"><img src="<?= h($m['url']) ?>" alt="" loading="lazy" decoding="async"></a>
                <figcaption>
                    <code id="u-<?= h(substr($m['name'], 0, 8)) ?>"><?= h($m['url']) ?></code>
                    <span class="muted"><?= $fmt($m['size']) ?> &middot; <?= $used === [] ? 'не используется' : 'на страницах: ' . count($used) ?></span>
                    <span class="actions-row">
                        <button class="btn sm" type="button" data-copy="#u-<?= h(substr($m['name'], 0, 8)) ?>"><?= icon('copy') ?>Адрес</button>
                        <?php if ($canWrite): ?>
                        <form method="post" class="inline" data-confirm="Убрать картинку в корзину?" data-confirm-text="С сайта она пропадёт, но в корзине её можно вернуть." data-confirm-ok="В корзину" data-danger>
                            <?= admin_post_fields($csrf, 'media_delete') ?><input type="hidden" name="name" value="<?= h($m['name']) ?>">
                            <button class="ib danger" type="submit" title="<?= $used === [] ? 'В корзину' : 'Используется, сначала уберите со страниц' ?>" aria-label="В корзину"<?= $used === [] ? '' : ' disabled' ?>><?= icon('trash') ?></button>
                        </form>
                        <?php endif; ?>
                    </span>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</section>
