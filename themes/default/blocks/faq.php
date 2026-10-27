<?php /** @var array<string,mixed> $data */ /** @var string $id */ ?>
        <section class="section" id="<?= ag_e($id) ?>">
            <div class="container narrow">
<?php if (($data['heading'] ?? '') !== '' || $editing): ?>
                <h2<?= ag_edit('heading') ?>><?= ag_e($data['heading'] ?? '') ?></h2>
<?php endif; ?>
<?php foreach ($data['items'] ?? [] as $i => $item): ?>
                <details class="faq-item"<?= $editing ? ' open' : '' ?>>
                    <summary<?= ag_edit("items.{$i}.q") ?>><?= ag_e($item['q']) ?></summary>
                    <div class="prose"<?= ag_edit("items.{$i}.a", true) ?>><?= ag_e($item['a']) ?></div>
                </details>
<?php endforeach; ?>
            </div>
        </section>
