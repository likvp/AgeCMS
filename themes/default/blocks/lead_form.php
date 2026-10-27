<?php /** @var array<string,mixed> $data */ /** @var string $id */ /** @var string $slug */ ?>
        <section class="section" id="<?= ag_e($id) ?>">
            <div class="container narrow">
<?php if (($data['heading'] ?? '') !== '' || $editing): ?>
                <h2<?= ag_edit('heading') ?>><?= ag_e($data['heading'] ?? '') ?></h2>
<?php endif; ?>
<?php if (($data['subtitle'] ?? '') !== ''): ?>
                <p class="lead"<?= ag_edit('subtitle', true) ?>><?= ag_e($data['subtitle']) ?></p>
<?php endif; ?>
                <form class="lead-form" data-lead data-page="<?= ag_e($slug) ?>" data-success="<?= ag_e($data['success_text'] ?? 'Спасибо, заявка отправлена.') ?>" method="post" action="/_/lead">
                    <input name="name" type="text" placeholder="Имя" maxlength="100" autocomplete="name">
                    <input name="phone" type="tel" placeholder="Телефон" maxlength="30" autocomplete="tel">
                    <textarea name="message" placeholder="Сообщение" maxlength="2000" rows="3"></textarea>
                    <input class="hp" name="website_hp" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <input name="token" type="hidden" value="">
                    <button class="btn" type="submit"<?= ag_edit('button_text') ?>><?= ag_e($data['button_text'] ?? 'Отправить') ?></button>
                    <p class="form-status" role="status"></p>
                </form>
            </div>
        </section>
