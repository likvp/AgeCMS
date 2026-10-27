<?php
defined('AGECMS_BOOTED') || exit;

use AgeCMS\Database;

$leads = Database::rows('SELECT created_at, page_slug, name, phone, message FROM leads ORDER BY id DESC LIMIT 50');
$stats = Database::rows('SELECT date, views, unique_visitors, leads_count FROM analytics_daily ORDER BY date DESC LIMIT 7');
?>
<div class="pagehead"><div class="grow"><h1>Заявки</h1><p>Последние 50 обращений с форм сайта.</p></div></div>
<section class="panel">
<?php if ($leads === []): ?>
    <div class="empty"><?= icon('chat-circle-text') ?><b>Заявок пока нет</b><span>Они появятся, когда посетитель заполнит форму на сайте.</span></div>
<?php else: ?>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>Когда</th><th>Страница</th><th>Имя</th><th>Телефон</th><th>Сообщение</th></tr></thead>
        <tbody>
        <?php foreach ($leads as $l): ?>
            <tr><td class="num muted"><?= admin_date($l['created_at']) ?></td><td class="mono">/<?= h($l['page_slug']) ?></td><td><?= h($l['name']) ?></td><td class="num"><?= h($l['phone']) ?></td><td><?= h($l['message']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</section>

<section class="sect">
    <h2>Посещаемость, 7 дней</h2>
    <div class="panel">
    <?php if ($stats === []): ?>
        <div class="empty"><?= icon('info') ?><b>Данных пока нет</b><span>Счётчики появятся после первых посещений.</span></div>
    <?php else: ?>
        <table class="tbl">
            <thead><tr><th>День</th><th>Просмотры</th><th>Посетители</th><th>Заявки</th></tr></thead>
            <tbody>
            <?php foreach ($stats as $s): ?>
                <tr><td class="num"><?= h($s['date']) ?></td><td class="num"><?= (int)$s['views'] ?></td><td class="num"><?= (int)$s['unique_visitors'] ?></td><td class="num"><?= (int)$s['leads_count'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>
