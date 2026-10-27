<?php
/** @var string $csrf */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\Seo;

$settings = Seo::settings();
?>
<h1>Настройки сайта</h1>
<form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="settings_save">
    <label>Название сайта
        <input name="site_name" value="<?= h($settings['site_name'] ?? '') ?>" required maxlength="100">
    </label>
    <label>Описание сайта (для поисковиков и <code>llms.txt</code>)
        <textarea name="site_description" rows="3" maxlength="300"><?= h($settings['site_description'] ?? '') ?></textarea>
    </label>
    <p><button class="btn small" type="submit">Сохранить</button></p>
</form>
<p><small>Адрес сайта (<code><?= h(Seo::siteUrl()) ?></code>) меняется командой <code>php bin/config.php set site_url https://…</code>: от него зависят canonical, sitemap и cookie.</small></p>
