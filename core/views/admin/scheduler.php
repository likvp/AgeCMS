<?php
/**
 * «Система», планировщик: когда и чем запускался, чем его запускать, ближайшие и упавшие задачи.
 *
 * @var string $csrf
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Config, Cron, Scheduler};

$cronStatus = Cron::status();
$cronState = Cron::state();
$cronCounts = Scheduler::counts();
$cronOn = Config::get('scheduler.enabled', true) === true;
$cronUpcoming = Scheduler::upcoming(8);
$cronTroubled = Scheduler::troubled(8);
$cronUrl = Cron::url();
$cronAt = static fn(?int $t): string => $t === null ? 'ещё не было' : admin_date(gmdate('Y-m-d\TH:i:s\Z', $t));
$cronAgo = static function (int $t): string {
    $s = max(0, time() - $t);
    return match (true) {
        $s < 90 => 'только что',
        $s < 5400 => (int)round($s / 60) . ' мин назад',
        $s < 129600 => (int)round($s / 3600) . ' ч назад',
        default => (int)round($s / 86400) . ' дн. назад',
    };
};
$cronNames = ['cron' => 'cron сервера', 'url' => 'адрес запуска', 'visit' => 'посещение сайта'];
[$cronTag, $cronTagText] = !$cronOn ? ['err', 'выключен'] : match ($cronStatus['level']) {
    'ok' => ['ok', 'работает'],
    'warn' => ['warn', 'cron молчит'],
    default => $cronCounts['waiting'] > 0 ? ['warn', 'cron не настроен'] : ['plain', 'cron не настроен'],
};
$cronNotHandled = array_filter($cronUpcoming, static fn(array $t): bool => !$t['handled']);
?>
<section class="panel" id="scheduler">
    <div class="panel-h"><h2>Планировщик</h2><span class="tag <?= $cronTag ?>"><?= $cronTagText ?></span></div>
    <div class="panel-b">
        <?php if (!$cronOn): ?>
            <div class="notice error"><?= icon('warning-circle') ?><div><p><b>Планировщик выключен настройкой.</b> Задачи модулей не выполняются. Включить: <code>php bin/config.php set scheduler.enabled true</code>.</p></div></div>
        <?php elseif ($cronStatus['level'] === 'warn'): ?>
            <div class="notice warn" role="status"><?= icon('warning-circle') ?><div><p><b>Запусков по расписанию нет с <?= $cronAt($cronStatus['at']) ?></b> (<?= h($cronAgo((int)$cronStatus['at'])) ?>). Пока они не вернутся, задачи выполняются только при посещениях сайта и могут опоздать. Проверьте задание cron на сервере или в панели хостинга.</p></div></div>
        <?php elseif ($cronStatus['level'] === 'none' && $cronCounts['waiting'] > 0): ?>
            <div class="notice warn" role="status"><?= icon('info') ?><div><p><b>Есть задачи со сроком, а cron не настроен.</b> Они выполнятся при ближайшем посещении после срока. Чтобы работа шла вовремя и без посетителей, добавьте запуск ниже.</p></div></div>
        <?php elseif ($cronStatus['level'] === 'none'): ?>
            <p class="muted">Сайт работает и без cron: фоновые дела идут при посещениях. Cron нужен только модулям, у которых работа назначена на точное время (например, напоминание за день до события).</p>
        <?php endif; ?>
        <dl class="facts">
            <div><dt>Последний запуск</dt><dd><?= $cronStatus['last'] === null ? 'ещё не было' : $cronAt($cronStatus['last']['at']) . ', ' . h($cronNames[$cronStatus['last']['source']]) ?></dd></div>
            <?php foreach ($cronNames as $source => $name): if (!isset($cronState[$source]) || count($cronState) < 2) { continue; } ?>
                <div><dt><?= h(mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1)) ?></dt><dd><?= $cronAt($cronState[$source]['at']) ?> <span class="muted">(<?= h($cronAgo($cronState[$source]['at'])) ?>)</span></dd></div>
            <?php endforeach; ?>
            <div><dt>Задачи</dt><dd>ждут <?= $cronCounts['waiting'] ?><?= $cronCounts['overdue'] > 0 ? ', опаздывают ' . $cronCounts['overdue'] : '' ?><?= $cronCounts['running'] > 0 ? ', выполняются ' . $cronCounts['running'] : '' ?><?= $cronCounts['failed'] > 0 ? ', со сбоями ' . $cronCounts['failed'] : '' ?></dd></div>
        </dl>
    </div>
    <?php if ($cronUpcoming !== []): ?>
        <h3 class="task-h">Ближайшие задачи</h3>
        <ul class="task-list">
        <?php foreach ($cronUpcoming as $t): $late = $t['status'] === 'pending' && (int)$t['run_at'] < time() - 120; ?>
            <li>
                <div class="task-name"><b><?= h($t['task_key']) ?></b><small class="muted">модуль <?= h($t['module']) ?><?= $t['repeat'] !== '' ? ', ' . h($t['repeat']) : '' ?><?= (int)$t['attempts'] > 0 && $t['status'] === 'pending' ? ', попытка ' . ((int)$t['attempts'] + 1) : '' ?></small></div>
                <div class="task-when"><span class="num"><?= $cronAt((int)$t['run_at']) ?></span>
                    <?php if ($t['status'] === 'running'): ?><span class="tag info">выполняется</span>
                    <?php elseif (!$t['handled']): ?><span class="tag warn">модуль не работает</span>
                    <?php elseif ($late): ?><span class="tag warn">опаздывает</span><?php endif; ?></div>
                <form method="post" data-confirm="Отменить задачу <?= h($t['task_key']) ?>?" data-confirm-text="Она не выполнится. Повторяющуюся задачу модуль поставит снова при следующем запросе." data-confirm-ok="Отменить задачу" data-danger><?= admin_post_fields($csrf, 'task_remove') ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="ib danger" type="submit" title="Отменить задачу" aria-label="Отменить задачу <?= h($t['task_key']) ?>"><?= icon('trash') ?></button></form>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($cronTroubled !== []): ?>
        <h3 class="task-h">Сбои и пропуски</h3>
        <ul class="task-list">
        <?php foreach ($cronTroubled as $t): $open = in_array($t['status'], ['pending', 'running'], true); $fresh = $t['expires_at'] === null || (int)$t['expires_at'] > time(); ?>
            <li>
                <div class="task-name"><b><?= h($t['task_key']) ?></b><small class="muted">модуль <?= h($t['module']) ?>, срок <?= $cronAt((int)$t['due_at']) ?><?= $t['repeat'] !== '' ? ', ' . h($t['repeat']) : '' ?></small><small class="task-err"><?= h($t['last_error']) ?></small></div>
                <div class="task-when"><span class="num"><?= $cronAt((int)($t['finished_at'] ?? $t['last_run_at'] ?? $t['updated_at'])) ?></span>
                    <span class="tag <?= $t['status'] === 'failed' ? 'err' : 'warn' ?>"><?= $t['status'] === 'failed' ? 'не выполнена' : ($t['status'] === 'expired' ? 'пропущена' : 'будет повтор') ?></span></div>
                <div class="task-acts">
                    <?php if (($t['status'] === 'failed' || $t['status'] === 'pending') && $fresh): ?><form method="post"><?= admin_post_fields($csrf, 'task_retry') ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="ib" type="submit" title="Повторить сейчас" aria-label="Повторить задачу <?= h($t['task_key']) ?>"><?= icon('arrow-counter-clockwise') ?></button></form><?php endif; ?>
                    <?php if (!$open): ?><form method="post"><?= admin_post_fields($csrf, 'task_remove') ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="ib danger" type="submit" title="Убрать из списка" aria-label="Убрать задачу <?= h($t['task_key']) ?> из списка"><?= icon('trash') ?></button></form><?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <details class="panel-d"<?= $cronOn && $cronStatus['level'] !== 'ok' && ($cronStatus['level'] === 'warn' || $cronCounts['waiting'] > 0) ? ' open' : '' ?>>
        <summary>Как запускать по расписанию</summary>
        <div class="panel-b">
            <p><b>Свой сервер.</b> Одна команда от root, запуск раз в минуту от пользователя сайта:</p>
            <pre class="agent-text" id="cron-root"><?= h(Cron::installCommand()) ?></pre>
            <p><button class="btn sm" type="button" data-copy="#cron-root"><?= icon('copy') ?>Копировать</button></p>
            <p><b>Хостинг с планировщиком команд</b> (раздел «Cron», «Планировщик заданий» в панели), раз в минуту или чаще всего, что разрешает хостинг:</p>
            <pre class="agent-text" id="cron-line"><?= h(Cron::crontabLine()) ?></pre>
            <p><button class="btn sm" type="button" data-copy="#cron-line"><?= icon('copy') ?>Копировать</button></p>
            <p><b>Хостинг, который умеет только открыть адрес.</b>
            <?php if ($cronUrl === ''): ?>Включите адрес запуска: сайт выдаст ссылку с секретом, её вписывают в планировщик хостинга или внешнего сервиса.</p>
                <form method="post"><?= admin_post_fields($csrf, 'cron_url_on') ?><button class="btn sm" type="submit"><?= icon('link-simple') ?>Включить адрес запуска</button></form>
            <?php else: ?>Откройте этот адрес раз в минуту (методом GET или POST):</p>
                <pre class="agent-text" id="cron-url"><?= h($cronUrl) ?></pre>
                <p class="muted">Адрес с секретом никому не показывайте: он только запускает задачи, но попадает в журнал веб-сервера. Если планировщик умеет заголовки, открывайте <code><?= h(Cron::PATH) ?></code> без параметров, а секрет (часть после <code>key=</code>) передавайте в заголовке <code><?= h(Cron::HEADER) ?></code>.</p>
                <div class="actions-row">
                    <button class="btn sm" type="button" data-copy="#cron-url"><?= icon('copy') ?>Копировать</button>
                    <form method="post" data-confirm="Сменить адрес запуска?" data-confirm-text="Прежний адрес сразу перестанет работать, новый нужно вписать в планировщик." data-confirm-ok="Сменить"><?= admin_post_fields($csrf, 'cron_url_on') ?><button class="btn sm" type="submit">Сменить адрес</button></form>
                    <form method="post" data-confirm="Выключить адрес запуска?" data-confirm-text="Запуск по этому адресу прекратится." data-confirm-ok="Выключить"><?= admin_post_fields($csrf, 'cron_url_off') ?><button class="btn sm" type="submit">Выключить</button></form>
                </div>
            <?php endif; ?>
        </div>
    </details>
</section>
