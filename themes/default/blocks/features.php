<?php /** @var array<string,mixed> $data */ /** @var string $id */ ?>
        <section class="section" id="<?= ag_e($id) ?>">
            <div class="container">
<?php if (($data['heading'] ?? '') !== '' || $editing): ?>
                <h2<?= ag_edit('heading') ?>><?= ag_e($data['heading'] ?? '') ?></h2>
<?php endif; ?>
                <div class="bento">
<?php foreach ($data['items'] ?? [] as $i => $item): ?>
                    <article class="tile<?= ($item['size'] ?? '') === 'wide' ? ' tile-wide' : '' ?>">
                        <h3<?= ag_edit("items.{$i}.title") ?>><?= ag_e($item['title']) ?></h3>
                        <p<?= ag_edit("items.{$i}.text", true) ?>><?= ag_e($item['text'] ?? '') ?></p>
                    </article>
<?php endforeach; ?>
                </div>
            </div>
        </section>
