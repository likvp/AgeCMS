<?php
defined('AGECMS_BOOTED') || exit;

use AgeCMS\Database;

$leads = Database::rows('SELECT created_at, page_slug, name, phone, message FROM leads ORDER BY id DESC LIMIT 50');
$stats = Database::rows('SELECT date, views, unique_visitors, leads_count FROM analytics_daily ORDER BY date DESC LIMIT 7');
?>
<h1>Заявки</h1>
<?php if ($leads === []): ?><p>Пока нет.</p><?php else: ?>
<table>
    <tr><th>Когда</th><th>Страница</th><th>Имя</th><th>Телефон</th><th>Сообщение</th></tr>
    <?php foreach ($leads as $l): ?>
        <tr><td><?= h($l['created_at']) ?></td><td><?= h($l['page_slug']) ?></td><td><?= h($l['name']) ?></td><td><?= h($l['phone']) ?></td><td><?= h($l['message']) ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Посещаемость, 7 дней</h2>
<?php if ($stats === []): ?><p>Данных пока нет.</p><?php else: ?>
<table>
    <tr><th>День</th><th>Просмотры</th><th>Посетители</th><th>Заявки</th></tr>
    <?php foreach ($stats as $s): ?>
        <tr><td><?= h($s['date']) ?></td><td><?= (int)$s['views'] ?></td><td><?= (int)$s['unique_visitors'] ?></td><td><?= (int)$s['leads_count'] ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
