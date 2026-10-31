<?php /** @var array<string,mixed> $data */ /** @var string $id */ ?>
        <section class="section" id="<?= ag_e($id) ?>">
            <article class="container narrow">
<?php if (($data['heading'] ?? '') !== '' || $editing): ?>
                <h2<?= ag_edit('heading') ?>><?= ag_e($data['heading'] ?? '') ?></h2>
<?php endif; ?>
                <div class="rich"<?= ag_edit_rich('body') ?>><?= ag_rich($data['body'] ?? '') ?></div>
            </article>
        </section>
