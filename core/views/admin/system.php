<?php
/**
 * @var string $csrf
 * @var bool $isAdmin
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Backup, Config, Database, SkillRegistry, Updater, Updates};

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
<?php $envChecks = \AgeCMS\Installer::checks(); $envMissing = array_filter($envChecks, static fn(array $c): bool => !$c['ok']); ?>
<section class="panel">
    <div class="panel-h"><h2>Требования сервера</h2><span class="tag <?= $envMissing === [] ? 'ok' : 'warn' ?>"><?= $envMissing === [] ? 'всё есть' : 'не хватает ' . count($envMissing) ?></span></div>
    <div class="panel-b">
        <p class="muted">PHP <?= h(PHP_VERSION) ?>, так его видит сам сайт (командная строка на сервере может использовать другой PHP).</p>
        <?php foreach ($envChecks as $c): ?>
            <p><span class="tag <?= $c['ok'] ? 'ok' : ($c['level'] === 'required' ? 'err' : 'warn') ?>"><?= $c['ok'] ? 'есть' : ($c['level'] === 'required' ? 'нет, нужно' : 'нет, совет') ?></span> <b><?= h($c['label']) ?></b><?= $c['note'] !== '' ? ' <span class="muted">' . h($c['note']) . '</span>' : '' ?><?= !$c['ok'] && $c['fix'] !== '' ? '<br><small class="muted">Как поставить: ' . h($c['fix']) . '</small>' : '' ?></p>
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
                    <?php elseif ($m['status'] === 'ok'): ?><span class="tag err">подпись не проверена</span> <small class="muted">свой модуль, работает без подписи</small>
                    <?php elseif ($m['status'] === 'disabled'): ?><span class="tag plain">выключен</span>
                    <?php else: ?><span class="tag err">заблокирован</span> <small><?= h((string)$m['reason']) ?></small><?php endif; ?></td>
                <td><div class="actions-row">
                    <?php if ($isAdmin && $m['status'] === 'ok' && \AgeCMS\ModuleSettings::fields($name) !== []): ?><a class="btn sm" href="<?= h(admin_url('module_settings', ['m' => $name])) ?>"><?= icon('gear-six') ?>Настройки</a><?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <?php if ($m['status'] === 'ok' && $m['signed'] === null): ?><form method="post" data-confirm="Подписать модуль <?= h($name) ?> ключом этого сайта?" data-confirm-text="Подписывайте только код, который вы проверили. После подписи любое изменение его файлов заблокирует модуль." data-confirm-ok="Подписать"><?= admin_post_fields($csrf, 'module_sign') ?><input type="hidden" name="module" value="<?= h($name) ?>"><button class="btn sm" type="submit"><?= icon('seal-check') ?>Подписать</button></form><?php endif; ?>
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
        <p>Свой модуль загружается zip-архивом: <code>manifest.json</code> (поля <code>name</code>, <code>class</code>, <code>entry</code>) и файлы модуля, как в <code>docs/MODULES.md</code>. Модуль с подписью разработчика или этого сайта проверяется; модуль без подписи работает сразу, но помечается красным. Код модуля выполняется на сервере с правами сайта: ставьте только то, чему доверяете.</p>
        <div class="row center">
            <input class="in" type="file" name="module_zip" accept=".zip,application/zip" required aria-label="Архив модуля">
            <label class="chk"><input type="checkbox" name="replace" value="1"><span>Заменить, если уже есть</span></label>
            <button class="btn primary" type="submit"><?= icon('upload-simple') ?>Загрузить модуль</button>
        </div>
    </form>
    <?php endif; ?>
</section>

<?php if ($isAdmin):
    $tokens = Database::rows('SELECT t.name, t.role, t.last_used_at, t.revoked_at, t.expires_at, u.username FROM api_tokens t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.id');
    $selfIssue = Config::get('agent_access.self_issue', true) === true;
    $tokenDays = (int)Config::get('agent_access.token_days', 90);
    $beforeAgent = Config::get('backup.before_agent', true) === true;
    $now = gmdate('Y-m-d\TH:i:s\Z');
?>
<section class="panel">
    <div class="panel-h"><h2>Подключить ИИ-агента</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'invite_create') ?>
        <p>Нажмите кнопку, скопируйте готовый текст и вставьте его в чат с агентом. В нём адрес сайта, ссылка на инструкцию <a href="/AGENT.md" target="_blank" rel="noopener"><code>/AGENT.md</code></a> и одноразовый код. Агент обменяет код на собственный токен, пароль ему не нужен. Код действует сутки и сработает один раз.</p>
        <div class="row">
            <label class="f"><span>Что разрешить</span><select class="in" name="role"><option value="editor">Править контент (editor)</option><option value="viewer">Только читать (viewer)</option><option value="admin">Всё, включая настройки (admin)</option></select></label>
            <label class="f"><span>На сколько дней токен <small>(0 без срока)</small></span><input class="in" type="number" name="token_days" min="0" max="3650" value="<?= $tokenDays ?>"></label>
            <label class="f"><span>Пометка <small>(для себя)</small></span><input class="in" type="text" name="note" maxlength="80" placeholder="сео-аудит"></label>
        </div>
        <button class="btn primary" type="submit"><?= icon('key') ?>Получить текст для агента</button>
    </form>
</section>
<section class="panel">
    <div class="panel-h"><h2>Правила для агентов</h2></div>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'agent_access_save') ?>
        <div class="f">
            <label class="chk"><input type="checkbox" name="self_issue" value="1"<?= $selfIssue ? ' checked' : '' ?>><span>Агент может получить токен по логину и паролю пользователя <small>(для тех, кому вы дали логин)</small></span></label>
            <label class="chk"><input type="checkbox" name="before_agent" value="1"<?= $beforeAgent ? ' checked' : '' ?>><span>Делать копию базы перед правками агента <small>(одна на сеанс, хранятся последние пять)</small></span></label>
        </div>
        <label class="f"><span>Срок жизни токена по умолчанию, дней <small>(0 без срока; агент предупреждается за неделю до конца)</small></span><input class="in" type="number" name="token_days" min="0" max="3650" value="<?= $tokenDays ?>"></label>
        <button class="btn primary" type="submit">Сохранить</button>
    </form>
</section>
<section class="panel">
    <div class="panel-h"><h2>Токены для ИИ-агентов</h2></div>
    <?php if ($tokens !== []): ?>
    <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Имя</th><th>Роль</th><th>Действует до</th><th>Последний вызов</th><th></th></tr></thead><tbody>
        <?php foreach ($tokens as $t): $dead = $t['revoked_at'] !== null; $expired = !$dead && $t['expires_at'] !== null && $t['expires_at'] < $now; ?>
            <tr><td class="mono"><?= h($t['name']) ?><?= $t['username'] !== null ? ' <small class="muted">(' . h($t['username']) . ')</small>' : '' ?></td><td><span class="tag plain"><?= h($t['role']) ?></span></td>
                <td class="num muted"><?= $t['expires_at'] === null ? 'без срока' : admin_date($t['expires_at']) ?><?= $expired ? ' <span class="tag warn">истёк</span>' : '' ?></td>
                <td class="num muted"><?= $t['last_used_at'] === null ? 'не было' : admin_date($t['last_used_at']) ?></td>
                <td class="r"><?php if (!$dead): ?>
                    <form method="post" class="inline" data-confirm="Отозвать токен «<?= h($t['name']) ?>»?" data-confirm-text="Агент с этим токеном сразу потеряет доступ." data-confirm-ok="Отозвать" data-danger>
                        <?= admin_post_fields($csrf, 'token_revoke') ?><input type="hidden" name="name" value="<?= h($t['name']) ?>"><button class="btn sm ghost danger" type="submit">Отозвать</button>
                    </form>
                <?php else: ?><span class="tag err">отозван</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <form method="post" class="panel-b">
        <?= admin_post_fields($csrf, 'token_create') ?>
        <p class="muted">Токен вручную нужен, если агент не может обменять код сам. Его показывают один раз.</p>
        <div class="row">
            <label class="f"><span>Имя токена</span><input class="in" type="text" name="name" placeholder="seo-agent" required pattern="[A-Za-z0-9_.\-]{2,40}" autocapitalize="none" spellcheck="false"></label>
            <label class="f"><span>Роль</span><select class="in" name="role"><option value="editor">editor: правит контент</option><option value="viewer">viewer: только чтение</option><option value="admin">admin: полный доступ</option></select></label>
            <label class="f"><span>Дней <small>(0 без срока)</small></span><input class="in" type="number" name="days" min="0" max="3650" value="<?= $tokenDays ?>"></label>
            <button class="btn primary" type="submit"><?= icon('key') ?>Создать токен вручную</button>
        </div>
    </form>
</section>
<?php endif; ?>
