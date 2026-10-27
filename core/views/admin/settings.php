<?php
/** @var string $csrf */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\Seo;

$settings = Seo::settings();
?>
<div class="pagehead"><div class="grow"><h1>Настройки сайта</h1><p>Название и описание используются в заголовках страниц, поисковой выдаче и <code>llms.txt</code>.</p></div></div>
<section class="panel">
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'settings_save') ?>
        <label class="f"><span>Название сайта</span><input class="in" type="text" name="site_name" value="<?= h($settings['site_name'] ?? '') ?>" required maxlength="100"></label>
        <label class="f"><span>Описание сайта</span><textarea class="in" name="site_description" rows="3" maxlength="300"><?= h($settings['site_description'] ?? '') ?></textarea><small class="hint">До 300 символов, одной строкой.</small></label>
        <button class="btn primary" type="submit">Сохранить</button>
    </form>
</section>
<section class="panel">
    <div class="panel-h"><h2>Адрес сайта</h2></div>
    <div class="panel-b">
        <p><code><?= h(Seo::siteUrl()) ?></code></p>
        <p class="muted">От адреса зависят canonical, sitemap и cookie, поэтому он меняется только из консоли:</p>
        <code class="cmd">php bin/config.php set site_url https://example.com</code>
    </div>
</section>
