<?php
/**
 * @var array<string,mixed> $doc
 * @var string $slug
 * @var array<string,string> $settings
 * @var string $head
 * @var string $content
 * @var array{header:list<array{title:string,url:string}>,footer:list<array{title:string,url:string}>} $menus
 * @var bool $editing
 * @var string $csrf
 * @var array{header:array<string,mixed>,footer:array<string,mixed>} $themeSettings values of "settings" in theme.json
 */
$asset = static fn(string $file): string => $file . '?v=' . (is_file(AGECMS_ROOT . $file) ? filemtime(AGECMS_ROOT . $file) : 1);
$site = $settings['site_name'] ?? '';
$phone = $settings['org_phone'] ?? '';
$email = $settings['org_email'] ?? '';
$header = $themeSettings['header'];
$footer = $themeSettings['footer'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $head ?>

    <link rel="stylesheet" href="<?= ag_e($asset('/assets/app.css')) ?>">
<?php if ($editing): ?>
    <meta name="ag-csrf" content="<?= ag_e($csrf) ?>">
    <script src="<?= ag_e($asset('/assets/editor.js')) ?>" defer></script>
<?php endif; ?>
    <script src="<?= ag_e($asset('/assets/app.js')) ?>" defer></script>
</head>
<body<?= $editing ? ' class="is-editing"' : '' ?>>
    <header class="site-header" data-ag-region="header">
        <div class="container bar">
            <a class="brand" href="/"><?= ag_e($site) ?><?php if ($header['tagline'] !== ''): ?> <small class="tagline"><?= ag_e($header['tagline']) ?></small><?php endif; ?></a>
            <nav class="nav" aria-label="Основное меню" data-ag-region="menu">
<?php foreach ($menus['header'] as $item): ?>
                <a href="<?= ag_e($item['url']) ?>"><?= ag_e($item['title']) ?></a>
<?php endforeach; ?>
            </nav>
<?php if ($header['show_phone'] && $phone !== ''): ?>
            <a class="phone" href="tel:<?= ag_e(preg_replace('/[^+0-9]/', '', $phone)) ?>"><?= ag_e($phone) ?></a>
<?php endif; ?>
        </div>
    </header>
    <main>
<?= $content ?>

    </main>
    <footer class="site-footer" data-ag-region="footer">
        <div class="container bar">
            <span>&copy; <?= ag_e(date('Y')) ?> <?= ag_e($site) ?></span>
<?php if ($footer['show_contacts'] && ($phone !== '' || $email !== '')): ?>
            <span class="contacts"><?php if ($phone !== ''): ?><a href="tel:<?= ag_e(preg_replace('/[^+0-9]/', '', $phone)) ?>"><?= ag_e($phone) ?></a><?php endif; ?><?php if ($email !== ''): ?> <a href="mailto:<?= ag_e($email) ?>"><?= ag_e($email) ?></a><?php endif; ?></span>
<?php endif; ?>
            <nav class="nav" aria-label="Нижнее меню" data-ag-region="menu">
<?php foreach ($menus['footer'] as $item): ?>
                <a href="<?= ag_e($item['url']) ?>"><?= ag_e($item['title']) ?></a>
<?php endforeach; ?>
            </nav>
        </div>
<?php if ($footer['note'] !== ''): ?>
        <div class="container"><p class="note"><?= ag_e($footer['note']) ?></p></div>
<?php endif; ?>
    </footer>
</body>
</html>
