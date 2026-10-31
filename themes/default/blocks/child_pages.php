<?php /** @var array<string,mixed> $data */ /** @var string $id */
// Published pages nested in the given one (or in this page), as cards. The cached page is dropped when one of them changes.
$children = ag_child_pages((string)($data['parent'] ?? ''));
$months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
?>
        <section class="section" id="<?= ag_e($id) ?>">
            <div class="container">
<?php if (($data['heading'] ?? '') !== '' || $editing): ?>
                <h2<?= ag_edit('heading') ?>><?= ag_e($data['heading'] ?? '') ?></h2>
<?php endif; ?>
<?php if ($children === []): ?>
<?php if ($editing): ?>
                <p class="lead">Вложенных опубликованных страниц пока нет. Здесь появятся их карточки.</p>
<?php endif; ?>
<?php else: ?>
                <div class="cards">
<?php foreach ($children as $child): ?>
                    <a class="card" href="/<?= ag_e($child['slug']) ?>">
<?php if (preg_match('~^(https://|/(?![/\\\\]))~', $child['og_image']) === 1): ?>
                        <img src="<?= ag_e($child['og_image']) ?>" alt="" loading="lazy" decoding="async">
<?php endif; ?>
                        <span class="card-body">
<?php if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $child['published_at'], $d) === 1): ?>
                            <time datetime="<?= ag_e($child['published_at']) ?>"><?= (int)$d[3] . ' ' . $months[(int)$d[2] - 1] . ' ' . $d[1] ?></time>
<?php endif; ?>
                            <strong><?= ag_e($child['title']) ?></strong>
<?php if ($child['meta_description'] !== ''): ?>
                            <span class="card-text"><?= ag_e($child['meta_description']) ?></span>
<?php endif; ?>
                        </span>
                    </a>
<?php endforeach; ?>
                </div>
<?php endif; ?>
            </div>
        </section>
