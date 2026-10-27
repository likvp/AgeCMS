<?php /** @var array<string,mixed> $data */ /** @var string $id */ ?>
        <section class="hero" id="<?= ag_e($id) ?>">
            <div class="container">
                <h1<?= ag_edit('h1') ?>><?= ag_e($data['h1'] ?? '') ?></h1>
<?php if (($data['subtitle'] ?? '') !== '' || $editing): ?>
                <p class="lead"<?= ag_edit('subtitle', true) ?>><?= ag_e($data['subtitle'] ?? '') ?></p>
<?php endif; ?>
<?php if (($data['cta_text'] ?? '') !== ''): ?>
                <a class="btn" href="<?= ag_e($data['cta_url'] ?? '#') ?>"<?= ag_edit('cta_text') ?>><?= ag_e($data['cta_text']) ?></a>
<?php endif; ?>
            </div>
        </section>
