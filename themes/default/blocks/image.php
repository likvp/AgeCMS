<?php /** @var array<string,mixed> $data */ /** @var string $id */
$src = (string)($data['src'] ?? '');
$avif = '';
if (preg_match('~^/assets/uploads/([a-f0-9]{20})\.webp$~', $src, $m) === 1 && is_file(\AgeCMS\Media::dir() . '/' . $m[1] . '.avif')) {
    $avif = '/assets/uploads/' . $m[1] . '.avif';
}
?>
        <section class="section" id="<?= ag_e($id) ?>">
            <div class="container<?= !empty($data['wide']) ? '' : ' narrow' ?>">
                <figure class="figure">
<?php if ($avif !== ''): ?>
                    <picture><source srcset="<?= ag_e($avif) ?>" type="image/avif"><img src="<?= ag_e($src) ?>" alt="<?= ag_e($data['alt'] ?? '') ?>" loading="lazy" decoding="async"></picture>
<?php else: ?>
                    <img src="<?= ag_e($src) ?>" alt="<?= ag_e($data['alt'] ?? '') ?>" loading="lazy" decoding="async">
<?php endif; ?>
<?php if (($data['caption'] ?? '') !== '' || $editing): ?>
                    <figcaption<?= ag_edit('caption') ?>><?= ag_e($data['caption'] ?? '') ?></figcaption>
<?php endif; ?>
                </figure>
            </div>
        </section>
