<?php
/**
 * @var string $csrf
 * @var bool $isAdmin
 * @var array<string,mixed>|null $envResult
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Backup, Config, SkillRegistry, Updater, Updates};

$modules = SkillRegistry::modules();
?>
<div class="pagehead"><div class="grow"><h1>Система</h1><p>Модули, доступ для ИИ-агентов и обслуживание.</p></div></div>

<?php
$release = Updates::current();
$newer = Updates::available();
$updState = Updates::state();
$updUrl = Updates::sourceUrl();
$auto = Updater::autoEnabled();
$lastUpdate = Updater::last();
// Why the button cannot work here: no signed package, missing extensions, files PHP may not write.
$blockers = $newer === null ? [] : array_merge(Updater::verifyRelease($newer) !== null ? [Updater::verifyRelease($newer)] : [], Updater::preflight());
$rollbacks = Updater::rollbacks();
?>
<?php
$envChecks = \AgeCMS\Installer::checks();
$envMissing = array_filter($envChecks, static fn(array $c): bool => !$c['ok']);
$envInstallable = array_filter($envMissing, static fn(array $c): bool => \AgeCMS\ServerSetup::installable($c['label']));
?>
<section class="panel">
    <div class="panel-h"><h2>Требования сервера</h2><span class="tag <?= $envMissing === [] ? 'ok' : 'warn' ?>"><?= $envMissing === [] ? 'всё есть' : 'не хватает ' . count($envMissing) ?></span><?php if ($envInstallable !== [] && $isAdmin): ?><form method="post" class="inline" data-confirm="Установить недостающее?" data-confirm-text="Сайт попробует поставить пакеты PHP на сервер. Если прав нет, он покажет команды для ручной установки." data-confirm-ok="Установить"><?= admin_post_fields($csrf, 'env_install') ?><input type="hidden" name="which" value="*"><button class="btn sm primary" type="submit"><?= icon('terminal-window') ?>Установить недостающее</button></form><?php endif; ?></div>
    <div class="panel-b">
        <p class="muted">PHP <?= h(PHP_VERSION) ?>, так его видит сам сайт (командная строка на сервере может использовать другой PHP).</p>
        <?php if (!empty($envResult)): ?>
            <div class="notice <?= $envResult['ok'] ? 'ok' : 'warn' ?>" role="status"><?= icon('info') ?><div>
                <p><b><?= h($envResult['title']) ?></b></p>
                <pre class="agent-text" id="env-result"><?= h($envResult['text']) ?></pre>
                <p><button class="btn sm" type="button" data-copy="#env-result"><?= icon('copy') ?>Копировать</button></p>
            </div></div>
        <?php endif; ?>
        <?php $capStatus = \AgeCMS\ServerSetup::captureStatus(); if ($capStatus !== null && $capStatus['state'] !== 'ready'): ?>
            <div class="notice <?= $capStatus['state'] === 'running' ? 'ok' : 'warn' ?>" role="status"><?= icon('info') ?><div>
                <p><b>Node.js и Chrome для «Точной копии»:</b> <?= h(['running' => 'идёт установка, обновите страницу через несколько минут', 'failed' => 'последняя установка не удалась', 'skipped' => 'установка пропущена', 'unsupported' => 'эта система не поддерживается'][$capStatus['state']] ?? $capStatus['state']) ?>. <?= h($capStatus['message']) ?>. Журнал: <code>data/logs/install-capture.log</code>.</p>
            </div></div>
        <?php endif; ?>
        <?php foreach ($envChecks as $c): ?>
            <p><span class="tag <?= $c['ok'] ? 'ok' : ($c['level'] === 'required' ? 'err' : 'warn') ?>"><?= $c['ok'] ? 'есть' : ($c['level'] === 'required' ? 'нет, нужно' : 'нет, совет') ?></span> <b><?= h($c['label']) ?></b><?= $c['note'] !== '' ? ' <span class="muted">' . h($c['note']) . '</span>' : '' ?><?= !$c['ok'] && $c['fix'] !== '' ? '<br><small class="muted">Как поставить: ' . h($c['fix']) . '</small>' : '' ?>
                <?php if (!$c['ok'] && $isAdmin && \AgeCMS\ServerSetup::installable($c['label'])): ?><form method="post" class="inline"><?= admin_post_fields($csrf, 'env_install') ?><input type="hidden" name="which" value="<?= h($c['label']) ?>"><button class="btn sm" type="submit"><?= icon('terminal-window') ?>Установить</button></form><?php endif; ?></p>
        <?php endforeach; ?>
    </div>
</section>
<section class="panel">
    <div class="panel-h"><h2>Версия ядра</h2><?= $newer !== null ? '<span class="tag warn">есть ' . h($newer['version']) . '</span>' : ($updUrl !== '' && $updState['error'] === '' && $updState['checked_at'] > 0 ? '<span class="tag ok">последняя</span>' : '') ?></div>
    <div class="panel-b">
        <p>Установлена <b class="mono">AgeCMS <?= h($release['version']) ?></b><?= $release['date'] !== '' ? ' от ' . h(date('d.m.Y', (int)strtotime($release['date']))) : '' ?>.
        <?php if ($updUrl === ''): ?>Проверка обновлений выключена: не задан адрес, где лежит файл с номером последней версии.
        <?php elseif ($updState['checked_at'] > 0): ?>Последняя проверка <?= h(admin_date(gmdate('Y-m-d\TH:i:s\Z', $updState['checked_at']))) ?><?= $updState['error'] !== '' ? '. Не вышло: ' . h($updState['error']) . '.' : '.' ?>
        <?php else: ?>Ещё не проверялось, проверка идёт раз в сутки.<?php endif; ?></p>
        <?php if ($newer !== null): ?>
            <div class="notice warn"><?= icon('info') ?><div>
                <p><b>Вышла версия <?= h($newer['version']) ?></b><?= $newer['date'] !== '' ? ' от ' . h(date('d.m.Y', (int)strtotime($newer['date']))) : '' ?>.<?= $newer['url'] !== '' ? ' <a href="' . h($newer['url']) . '" target="_blank" rel="noopener noreferrer">Что нового</a>' : '' ?></p>
                <?php if ($newer['notes'] !== ''): ?><p class="pre-line"><?= h($newer['notes']) ?></p><?php endif; ?>
                <?php if ($blockers === []): ?>
                    <p>Обновление заменит только файлы ядра. Страницы, картинки, настройки, модули и свои темы не трогаются. Перед заменой сохраняются копия базы и прежние файлы; если что-то не запишется, прежняя версия вернётся сама.</p>
                    <?php if ($isAdmin): ?><form method="post" data-confirm="Обновить ядро до <?= h($newer['version']) ?>?" data-confirm-text="Займёт до минуты. Копия базы и прежних файлов сохранится." data-confirm-ok="Обновить"><?= admin_post_fields($csrf, 'core_update') ?><button class="btn primary" type="submit"><?= icon('upload-simple') ?>Обновить до <?= h($newer['version']) ?></button></form><?php endif; ?>
                <?php else: ?>
                    <p>Обновить отсюда не получится: <?= h(implode('; ', $blockers)) ?>. Файлы ядра может заменить хостер или разработчик, содержимое и настройки при этом не трогаются.</p>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>
        <?php if ($lastUpdate !== null): ?>
            <p class="muted"><?= $lastUpdate['ok'] ? 'Последнее обновление' : 'Последняя попытка обновления' ?> <?= h(admin_date(gmdate('Y-m-d\TH:i:s\Z', $lastUpdate['at']))) ?>: <?= h($lastUpdate['from']) ?> &rarr; <?= h($lastUpdate['to'] !== '' ? $lastUpdate['to'] : '?') ?>, <?= $lastUpdate['by'] === 'auto' ? 'автоматически' : h($lastUpdate['by']) ?><?= $lastUpdate['ok'] ? '.' : '. Не вышло: ' . h($lastUpdate['error']) . '.' ?>
            <?php if ($rollbacks !== []): ?>Прежние файлы сохранены (<code><?= h($rollbacks[0]) ?></code>), вернуть их: <code>php bin/update.php --rollback</code>.<?php endif; ?></p>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <form method="post">
            <?= admin_post_fields($csrf, 'updates_save') ?>
            <label class="f"><span>Где проверять обновления <small>(адрес файла release.json последней версии, https; пусто - не проверять)</small></span><input class="in" type="url" name="updates_url" maxlength="500" placeholder="https://..." value="<?= h($updUrl) ?>"></label>
            <label class="chk"><input type="checkbox" name="updates_auto" value="1"<?= $auto ? ' checked' : '' ?>><span>Обновлять автоматически <small>(сайт сам поставит новую подписанную версию в течение суток после выхода; по умолчанию выключено)</small></span></label>
            <div class="row center">
                <button class="btn primary" type="submit">Сохранить</button>
                <?php if ($updUrl !== ''): ?><button class="btn" type="submit" name="action" value="updates_check" formnovalidate><?= icon('magnifying-glass') ?>Проверить сейчас</button><?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <div class="panel-h"><h2>Обслуживание</h2></div>
    <form method="post" class="panel-b row center">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button class="btn" name="action" value="clear_cache" type="submit"><?= icon('database') ?>Очистить кэш</button>
        <?php if ($isAdmin): ?><button class="btn" name="action" value="backup" type="submit"><?= icon('copy') ?>Сделать бэкап</button><?php endif; ?>
    </form>
    <?php if ($isAdmin): $backups = array_slice(Backup::list(), 0, 8); if ($backups !== []): ?>
        <table class="tbl"><thead><tr><th>Последние бэкапы</th><th>Откуда</th><th class="r">Размер</th></tr></thead><tbody>
        <?php foreach ($backups as $b): ?><tr><td class="mono"><?= h($b['file']) ?></td><td><?= $b['auto'] ? '<span class="tag plain">перед правкой агента</span>' : '<span class="tag plain">вручную</span>' ?></td><td class="r num muted"><?= round($b['size'] / 1024) ?> КБ</td></tr><?php endforeach; ?>
        </tbody></table>
    <?php endif; endif; ?>
</section>

<section class="panel">
    <div class="panel-h"><h2>Модули</h2></div>
    <?php if ($modules === []): ?>
        <div class="empty"><?= icon('seal-check') ?><b>Модулей нет</b><span>Модуль загружается zip-архивом ниже или кладётся папкой в modules.</span></div>
    <?php else: ?>
    <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Модуль</th><th>Состояние</th><th></th></tr></thead><tbody>
        <?php foreach ($modules as $name => $m):
            $signedBy = $m['signed'] === 'site' ? 'подписан этим сайтом' : (is_string($m['signed']) ? 'подпись разработчика ' . substr($m['signed'], 10) : '');
        ?>
            <tr><td class="mono"><?= h($name) ?></td>
                <td><?php if ($m['status'] === 'ok' && $m['signed'] !== null): ?><span class="tag ok">подпись проверена</span> <small class="muted"><?= h($signedBy) ?></small>
                    <?php elseif ($m['status'] === 'ok'): ?><span class="tag err">подпись не проверена</span> <small class="muted">свой модуль, работает без подписи. Если вы его проверили, нажмите «Подписать этим сайтом»: красная плашка пропадёт</small>
                    <?php elseif ($m['status'] === 'disabled'): ?><span class="tag plain">выключен</span>
                    <?php else: ?><span class="tag err">заблокирован</span> <small><?= h((string)$m['reason']) ?></small><?php endif; ?></td>
                <td><div class="actions-row">
                    <?php if ($isAdmin && $m['status'] === 'ok' && \AgeCMS\ModuleSettings::fields($name) !== []): ?><a class="btn sm" href="<?= h(admin_url('module_settings', ['m' => $name])) ?>"><?= icon('gear-six') ?>Настройки</a><?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <?php if ($m['status'] === 'ok' && $m['signed'] === null): ?><form method="post" data-confirm="Подписать модуль <?= h($name) ?> ключом этого сайта?" data-confirm-text="Подписывайте только код, который вы проверили. После подписи любое изменение его файлов заблокирует модуль." data-confirm-ok="Подписать"><?= admin_post_fields($csrf, 'module_sign') ?><input type="hidden" name="module" value="<?= h($name) ?>"><button class="btn sm" type="submit"><?= icon('seal-check') ?>Подписать этим сайтом</button></form><?php endif; ?>
                        <form method="post"><?= admin_post_fields($csrf, $m['status'] === 'disabled' ? 'module_enable' : 'module_disable') ?><input type="hidden" name="module" value="<?= h($name) ?>"><button class="btn sm" type="submit"><?= $m['status'] === 'disabled' ? 'Включить' : 'Выключить' ?></button></form>
                        <form method="post" data-confirm="Удалить модуль <?= h($name) ?>?" data-confirm-text="Папка модуля уйдёт в data/module-backups, его данные в базе останутся." data-confirm-ok="Удалить" data-danger><?= admin_post_fields($csrf, 'module_delete') ?><input type="hidden" name="module" value="<?= h($name) ?>"><button class="ib danger" type="submit" title="Удалить" aria-label="Удалить модуль <?= h($name) ?>"><?= icon('trash') ?></button></form>
                    <?php endif; ?>
                </div></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <form method="post" enctype="multipart/form-data" class="panel-b">
        <?= admin_post_fields($csrf, 'module_upload') ?>
        <p>Свой модуль загружается zip-архивом: <code>manifest.json</code> (поля <code>name</code>, <code>class</code>, <code>entry</code>) и файлы модуля, как в <code>docs/MODULES.md</code>. Модуль с подписью разработчика или этого сайта проверяется; модуль без подписи (в том числе архив модуля из выпуска) работает сразу, но помечается красным, пока вы не нажмёте «Подписать этим сайтом» у него в списке. Код модуля выполняется на сервере с правами сайта: ставьте только то, чему доверяете.</p>
        <div class="row center">
            <input class="in" type="file" name="module_zip" accept=".zip,application/zip" required aria-label="Архив модуля">
            <label class="chk"><input type="checkbox" name="replace" value="1"><span>Заменить, если уже есть</span></label>
            <button class="btn primary" type="submit"><?= icon('upload-simple') ?>Загрузить модуль</button>
        </div>
    </form>
    <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
<section class="panel">
    <div class="panel-h"><h2>ИИ-агенты</h2></div>
    <div class="panel-b">
        <p>Подключение агентов, их токены и правила доступа теперь в разделе «<a href="<?= h(admin_url('agents')) ?>">ИИ-агенты</a>»: там видно, кто из агентов работает и что делал, а токен можно заморозить, заблокировать или отнять.</p>
        <a class="btn" href="<?= h(admin_url('agents')) ?>"><?= icon('key') ?>Открыть «ИИ-агенты»</a>
    </div>
</section>
<?php endif; ?>
