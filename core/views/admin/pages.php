<?php
/**
 * @var string $csrf
 * @var bool $canWrite
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Pages, SchemaValidator};

$pages = Pages::listing();
?>
<h1>Страницы</h1>
<p>Текст можно править прямо на сайте (нажмите на него) или здесь, вместе с блоками и параметрами SEO.</p>
<table>
    <tr><th>Страница</th><th>Тип</th><th>Статус</th><th>Изменена</th><th></th></tr>
    <?php foreach ($pages as $p): ?>
        <tr>
            <td><a href="<?= h(admin_url('edit', ['slug' => $p['slug']])) ?>"><?= h($p['title']) ?></a> <small>/<?= h($p['slug']) ?></small></td>
            <td><?= h($p['schema_type']) ?></td>
            <td><?= (int)$p['is_published'] === 1 ? 'опубликована' : 'скрыта' ?><?= (int)$p['is_noindex'] === 1 ? ', noindex' : '' ?></td>
            <td><?= h($p['updated_at']) ?></td>
            <td class="actions">
                <a href="<?= h(admin_url('edit', ['slug' => $p['slug']])) ?>">править</a>
                <a href="/<?= $p['slug'] === 'index' ? '' : h($p['slug']) ?>" target="_blank" rel="noopener">на сайте</a>
                <a href="<?= h(admin_url('history', ['slug' => $p['slug']])) ?>">история</a>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if ($canWrite): ?>
<h2>Новая страница</h2>
<form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="page_create">
    <input name="slug" placeholder="адрес, например uslugi/remont" required pattern="[a-z0-9][a-z0-9_\-]*(/[a-z0-9][a-z0-9_\-]*)*" title="латиница, цифры, - и _, части через /">
    <input name="title" placeholder="Заголовок" required maxlength="200">
    <select name="parent_slug"><option value="">без родителя</option>
        <?php foreach ($pages as $p): ?><option value="<?= h($p['slug']) ?>"><?= h($p['title']) ?> (/<?= h($p['slug']) ?>)</option><?php endforeach; ?>
    </select>
    <select name="schema_type"><?php foreach (SchemaValidator::SCHEMA_TYPES as $type): ?><option><?= h($type) ?></option><?php endforeach; ?></select>
    <button class="btn small" type="submit">Создать</button>
</form>
<p><small>Новая страница создаётся скрытой. Наполните её блоками и включите «опубликована».</small></p>
<?php endif; ?>
