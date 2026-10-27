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
    <div class="notice error"><?= icon('warning-circle') ?><div>Страница не найдена. <a href="<?= h(admin_url('pages')) ?>">К списку страниц</a></div></div>
<?php return; endif;
if (!$canWrite): ?>
    <div class="notice error"><?= icon('lock-key') ?><div>Для правки нужна роль editor или admin.</div></div>
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
$url = '/' . ($slug === 'index' ? '' : $slug);
?>
<a class="crumb" href="<?= h(admin_url('pages')) ?>"><?= icon('arrow-left') ?>Все страницы</a>
<div class="pagehead">
    <div class="grow"><h1><?= h($doc['title']) ?></h1><p class="mono"><?= h($url) ?></p></div>
    <div class="actions-row">
        <a class="btn" href="<?= h(admin_url('history', ['slug' => $slug])) ?>"><?= icon('clock-counter-clockwise') ?>История</a>
        <a class="btn" href="<?= h($url) ?>" target="_blank" rel="noopener"><?= icon('eye') ?>На сайте</a>
    </div>
</div>
<?php if (isset($_GET['new'])): ?><div class="notice ok"><?= icon('check-circle') ?><div>Страница создана и пока скрыта. Добавьте блоки и включите «опубликована».</div></div><?php endif; ?>
<div id="ag-editor"><div class="skel"></div><div class="skel"></div><div class="skel"></div></div>
<noscript><div class="notice error"><?= icon('warning-circle') ?><div>Редактору нужен JavaScript.</div></div></noscript>
<script type="application/json" id="ag-editor-data"><?= $json ?></script>
<script src="/assets/admin/editor.js" defer></script>
