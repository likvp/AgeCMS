<?php
/**
 * Header and footer menus. Shown as the tab «Меню» of the settings page ($embedded), the old address ?s=menus leads there.
 *
 * @var string $csrf
 * @var bool $canWrite
 * @var bool $embedded
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Database, Pages};

if (!$canWrite): ?>
    <div class="notice error"><?= icon('lock-key') ?><div>Для правки меню нужна роль editor или admin.</div></div>
<?php return; endif;

$menus = ['header' => [], 'footer' => []];
foreach (Database::rows('SELECT name, items_json FROM menus') as $row) {
    $menus[$row['name']] = json_decode((string)$row['items_json'], true) ?: [];
}
$known = [['title' => 'Главная', 'url' => '/']];
foreach (Pages::listing(true) as $p) {
    if ($p['slug'] !== 'index') {
        $known[] = ['title' => $p['title'], 'url' => '/' . $p['slug']];
    }
}
$json = json_encode(['mode' => 'menus', 'csrf' => $csrf, 'menus' => $menus, 'known' => $known], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
<?php if (empty($embedded)): ?><div class="pagehead"><div class="grow"><h1>Меню</h1></div></div><?php endif; ?>
<p class="muted">Верхнее и нижнее меню сайта. Ссылка: адрес на сайте (<code>/about</code>), полный адрес <code>https://…</code> или якорь <code>#form</code>. До 30 пунктов.</p>
<div id="ag-editor"><div class="skel"></div><div class="skel"></div></div>
<noscript><div class="notice error"><?= icon('warning-circle') ?><div>Редактору нужен JavaScript.</div></div></noscript>
<script type="application/json" id="ag-editor-data"><?= $json ?></script>
<script src="<?= h(admin_asset('editor.js')) ?>" defer></script>
