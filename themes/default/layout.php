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
 */
$asset = static fn(string $file): string => $file . '?v=' . (is_file(AGECMS_ROOT . $file) ? filemtime(AGECMS_ROOT . $file) : 1);
$site = $settings['site_name'] ?? '';
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
    <header class="site-header">
        <div class="container bar">
            <a class="brand" href="/"><?= ag_e($site) ?></a>
            <nav class="nav" aria-label="Основное меню">
<?php foreach ($menus['header'] as $item): ?>
                <a href="<?= ag_e($item['url']) ?>"><?= ag_e($item['title']) ?></a>
<?php endforeach; ?>
            </nav>
        </div>
    </header>
    <main>
<?= $content ?>

    </main>
    <footer class="site-footer">
        <div class="container bar">
            <span>&copy; <?= ag_e(date('Y')) ?> <?= ag_e($site) ?></span>
            <nav class="nav" aria-label="Нижнее меню">
<?php foreach ($menus['footer'] as $item): ?>
                <a href="<?= ag_e($item['url']) ?>"><?= ag_e($item['title']) ?></a>
<?php endforeach; ?>
            </nav>
        </div>
    </footer>
</body>
</html>
