<?php
/**
 * @var string $csrf
 * @var bool $canWrite
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Pages, SchemaValidator, Theme};

$slug = (string)($_GET['slug'] ?? '');
$doc = Pages::slugValid($slug) ? Pages::doc($slug) : null;
if ($doc === null): ?>
    <p class="notice error">Страница не найдена. <a href="<?= h(admin_url('pages')) ?>">К списку страниц</a></p>
<?php return; endif;
if (!$canWrite): ?>
    <p class="notice error">Для правки нужна роль editor или admin.</p>
<?php return; endif;

$theme = $doc['theme'] !== '' ? $doc['theme'] : Theme::active();
$others = [];
foreach (Pages::listing() as $p) {
    if ($p['slug'] !== $slug) {
        $others[] = ['slug' => $p['slug'], 'title' => $p['title']];
    }
}
$data = [
    'mode' => 'page',
    'slug' => $slug,
    'csrf' => $csrf,
    'page' => $doc,
    'blockTypes' => Theme::blocks($theme),
    'schemaTypes' => SchemaValidator::SCHEMA_TYPES,
    'pages' => $others,
];
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
<p><a href="<?= h(admin_url('pages')) ?>">&larr; Все страницы</a></p>
<h1><?= h($doc['title']) ?> <small>/<?= h($slug) ?></small></h1>
<?php if (isset($_GET['new'])): ?><p class="notice ok">Страница создана и пока скрыта. Добавьте блоки и включите «опубликована».</p><?php endif; ?>
<p class="row">
    <a class="btn small" href="/<?= $slug === 'index' ? '' : h($slug) ?>" target="_blank" rel="noopener">Открыть на сайте</a>
    <a href="<?= h(admin_url('history', ['slug' => $slug])) ?>">История правок</a>
</p>
<div id="ag-editor" class="ag-editor"></div>
<script type="application/json" id="ag-editor-data"><?= $json ?></script>
<script src="/assets/admin-editor.js" defer></script>
