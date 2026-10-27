<?php /** @var array<string,mixed> $data */ /** @var string $id */ ?>
        <section class="section" id="<?= ag_e($id) ?>">
            <div class="container narrow">
<?php if (($data['heading'] ?? '') !== '' || $editing): ?>
                <h2<?= ag_edit('heading') ?>><?= ag_e($data['heading'] ?? '') ?></h2>
<?php endif; ?>
                <div class="prose"<?= ag_edit('body', true) ?>><?= ag_e($data['body'] ?? '') ?></div>
            </div>
        </section>
