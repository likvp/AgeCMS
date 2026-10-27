<?php
/**
 * @var string $csrf
 * @var bool $isAdmin
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Config, ModuleSettings, SkillRegistry};

$name = (string)($_GET['m'] ?? '');
$fields = ModuleSettings::fields($name);
$info = SkillRegistry::modules()[$name] ?? null;
$title = (string)($info['manifest']['title'] ?? $name);
?>
<div class="pagehead"><div class="grow"><h1>Настройки модуля «<?= h($title) ?>»</h1><p><a href="<?= h(admin_url('system')) ?>">← Система</a></p></div></div>
<?php if ($fields === []): ?>
    <section class="panel"><div class="empty"><?= icon('gear-six') ?><b>У этого модуля нет настроек</b><span>Либо модуль не найден, либо заблокирован.</span></div></section>
<?php else: ?>
<section class="panel">
    <form method="post" class="panel-b" action="/admin/">
        <?= admin_post_fields($csrf, 'module_settings_save') ?>
        <input type="hidden" name="module" value="<?= h($name) ?>">
        <?php foreach ($fields as $f):
            $key = (string)$f['key'];
            $id = 'ms-' . $key;
            $value = ModuleSettings::get($name, $key);
            $field = 'f_' . $key;
        ?>
            <?php if ($f['type'] === 'bool'): ?>
                <div class="f"><label class="chk"><input type="checkbox" name="<?= h($field) ?>" value="1"<?= $value === true ? ' checked' : '' ?>><span><?= h($f['label']) ?><?php if ($f['help'] !== ''): ?> <small><?= h($f['help']) ?></small><?php endif; ?></span></label></div>
            <?php else: ?>
                <label class="f" for="<?= h($id) ?>"><span><?= h($f['label']) ?><?php if ($f['help'] !== ''): ?> <small><?= h($f['help']) ?></small><?php endif; ?></span>
                <?php if ($f['type'] === 'textarea'): ?>
                    <textarea class="in" id="<?= h($id) ?>" name="<?= h($field) ?>" rows="5" maxlength="<?= (int)$f['length'] ?>"><?= h($value) ?></textarea>
                <?php elseif ($f['type'] === 'number'): ?>
                    <input class="in" id="<?= h($id) ?>" type="number" name="<?= h($field) ?>" min="<?= (int)$f['min'] ?>" max="<?= (int)$f['max'] ?>" value="<?= (int)$value ?>">
                <?php elseif ($f['type'] === 'select'): ?>
                    <select class="in" id="<?= h($id) ?>" name="<?= h($field) ?>"><?php foreach ($f['options'] as $o): ?><option value="<?= h($o) ?>"<?= $o === $value ? ' selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select>
                <?php elseif ($f['type'] === 'secret'): ?>
                    <input class="in" id="<?= h($id) ?>" type="password" name="<?= h($field) ?>" maxlength="<?= (int)$f['length'] ?>" autocomplete="new-password" placeholder="<?= $value !== '' ? 'задано, оставьте пустым чтобы не менять' : 'не задано' ?>">
                    <?php if ($value !== ''): ?><span class="chk"><input type="checkbox" name="clear_<?= h($field) ?>" value="1"><span>Стереть значение</span></span><?php endif; ?>
                <?php else: ?>
                    <input class="in" id="<?= h($id) ?>" type="text" name="<?= h($field) ?>" maxlength="<?= (int)$f['length'] ?>" value="<?= h($value) ?>">
                <?php endif; ?>
                </label>
            <?php endif; ?>
        <?php endforeach; ?>
        <button class="btn primary" type="submit">Сохранить</button>
    </form>
</section>
<?php endif; ?>
