<?php /** @var array<string,mixed> $data */ /** @var string $id */
$wide = !empty($data['wide']);
?>
        <section class="section" id="<?= ag_e($id) ?>">
            <div class="container<?= $wide ? '' : ' narrow' ?>">
                <figure class="figure">
                    <?= ag_img((string)($data['src'] ?? ''), (string)($data['alt'] ?? ''), ['priority' => !empty($data['priority']), 'sizes' => $wide ? '(min-width: 1100px) 1060px, 100vw' : '(min-width: 760px) 720px, 100vw']) ?>

<?php if (($data['caption'] ?? '') !== '' || $editing): ?>
                    <figcaption<?= ag_edit('caption') ?>><?= ag_e($data['caption'] ?? '') ?></figcaption>
<?php endif; ?>
                </figure>
            </div>
        </section>
