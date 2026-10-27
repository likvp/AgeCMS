<?php
/**
 * @var string $csrf
 * @var bool $canWrite
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Database, Pages};

if (!$canWrite): ?>
    <p class="notice error">Для правки меню нужна роль editor или admin.</p>
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
<h1>Меню</h1>
<p>Верхнее и нижнее меню сайта. Ссылка: адрес на сайте (<code>/about</code>), полный адрес <code>https://…</code> или якорь <code>#form</code>. До 30 пунктов.</p>
<div id="ag-editor" class="ag-editor"></div>
<script type="application/json" id="ag-editor-data"><?= $json ?></script>
<script src="/assets/admin-editor.js" defer></script>
