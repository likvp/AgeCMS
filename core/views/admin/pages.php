<?php
/**
 * @var string $csrf
 * @var bool $canWrite
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Pages, SchemaValidator};

$pages = Pages::listing();
?>
<div class="pagehead">
    <div class="grow">
        <h1>Страницы</h1>
        <p>Текст можно править прямо на сайте, нажав на него, или здесь: вместе с блоками и параметрами SEO.</p>
    </div>
    <?php if ($canWrite): ?><a class="btn primary" href="#new"><?= icon('plus') ?>Новая страница</a><?php endif; ?>
</div>

<section class="panel">
    <div class="toolbar">
        <label class="search"><span class="sr">Найти страницу</span><?= icon('magnifying-glass') ?><input class="in" type="search" placeholder="Найти страницу" data-filter="#pages-tbl" autocomplete="off"></label>
        <span class="muted"><?= count($pages) ?> шт.</span>
    </div>
    <div class="tbl-scroll">
    <table class="tbl" id="pages-tbl">
        <thead><tr><th>Страница</th><th>Тип</th><th>Статус</th><th>Изменена</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pages as $p):
            $url = '/' . ($p['slug'] === 'index' ? '' : $p['slug']); ?>
            <tr>
                <td><a class="cell-title" href="<?= h(admin_url('edit', ['slug' => $p['slug']])) ?>"><?= h($p['title']) ?></a><span class="cell-sub"><?= h($url) ?></span></td>
                <td><span class="tag plain"><?= h($p['schema_type']) ?></span></td>
                <td>
                    <?php if ((int)$p['is_published'] === 1): ?><span class="tag ok">опубликована</span><?php else: ?><span class="tag warn">скрыта</span><?php endif; ?>
                    <?php if ((int)$p['is_noindex'] === 1): ?><span class="tag plain">noindex</span><?php endif; ?>
                </td>
                <td class="num muted"><?= admin_date($p['updated_at']) ?></td>
                <td class="r"><div class="actions">
                    <a class="ib" href="<?= h(admin_url('edit', ['slug' => $p['slug']])) ?>" title="Править" aria-label="Править"><?= icon('file-text') ?></a>
                    <a class="ib" href="<?= h($url) ?>" target="_blank" rel="noopener" title="Открыть на сайте" aria-label="Открыть на сайте"><?= icon('eye') ?></a>
                    <a class="ib" href="<?= h(admin_url('history', ['slug' => $p['slug']])) ?>" title="История" aria-label="История"><?= icon('clock-counter-clockwise') ?></a>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="empty" id="filter-none" hidden><?= icon('magnifying-glass') ?><b>Ничего не найдено</b><span>Попробуйте другой запрос.</span></div>
    <?php if ($pages === []): ?><div class="empty"><?= icon('file-text') ?><b>Страниц пока нет</b><span>Создайте первую ниже.</span></div><?php endif; ?>
</section>

<?php if ($canWrite): ?>
<section class="panel" id="new">
    <div class="panel-h"><h2>Новая страница</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'page_create') ?>
        <div class="row">
            <label class="f"><span>Адрес</span><input class="in" type="text" name="slug" placeholder="uslugi/remont" required pattern="[a-z0-9][a-z0-9_\-]*(/[a-z0-9][a-z0-9_\-]*)*" title="латиница, цифры, - и _, части через /" autocapitalize="none" spellcheck="false"></label>
            <label class="f"><span>Заголовок</span><input class="in" type="text" name="title" placeholder="Ремонт" required maxlength="200"></label>
        </div>
        <div class="row">
            <label class="f"><span>Родитель</span>
                <select class="in" name="parent_slug"><option value="">без родителя</option>
                    <?php foreach ($pages as $p): ?><option value="<?= h($p['slug']) ?>"><?= h($p['title']) ?> (/<?= h($p['slug']) ?>)</option><?php endforeach; ?>
                </select></label>
            <label class="f"><span>Тип (schema.org)</span>
                <select class="in" name="schema_type"><?php foreach (SchemaValidator::SCHEMA_TYPES as $type): ?><option><?= h($type) ?></option><?php endforeach; ?></select></label>
        </div>
        <p class="muted">Новая страница создаётся скрытой. Наполните её блоками и включите «опубликована».</p>
        <button class="btn primary" type="submit">Создать и открыть редактор</button>
    </form>
</section>
<?php endif; ?>
