<?php
/**
 * @var string $csrf
 * @var bool $canWrite
 */
defined('AGECMS_BOOTED') || exit;

use AgeCMS\{Database, Pages, Revisions, Seo};

$days = [];
for ($i = 6; $i >= 0; $i--) {
    $days[gmdate('Y-m-d', time() - $i * 86400)] = ['views' => 0, 'unique_visitors' => 0, 'leads_count' => 0];
}
foreach (Database::rows('SELECT date, views, unique_visitors, leads_count FROM analytics_daily WHERE date >= ?', [array_key_first($days)]) as $r) {
    if (isset($days[$r['date']])) {
        $days[$r['date']] = $r;
    }
}
$views = array_sum(array_column($days, 'views'));
$visitors = array_sum(array_column($days, 'unique_visitors'));
$leadsWeek = array_sum(array_column($days, 'leads_count'));
$max = max(1, ...array_map('intval', array_column($days, 'views')));
$all = Pages::listing();
$published = count(array_filter($all, static fn(array $p): bool => (int)$p['is_published'] === 1));
$recent = Revisions::recent(8);
$week = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
$viewsWord = static function (int $n): string {
    $d = $n % 100;
    if ($d >= 11 && $d <= 14) {
        return 'просмотров';
    }
    return [1 => 'просмотр', 2 => 'просмотра', 3 => 'просмотра', 4 => 'просмотра'][$n % 10] ?? 'просмотров';
};
?>
<div class="pagehead">
    <div class="grow">
        <h1><?= h(Seo::settings()['site_name'] ?? 'Обзор') ?></h1>
        <p>Что происходит на сайте за последние 7 дней.</p>
    </div>
    <div class="actions-row">
        <?php if ($canWrite): ?><a class="btn primary" href="<?= h(admin_url('pages') . '#new') ?>"><?= icon('plus') ?>Новая страница</a><?php endif; ?>
    </div>
</div>

<div class="stats">
    <div class="stat"><div class="v"><?= number_format((int)$views, 0, '', ' ') ?></div><div class="l">просмотров</div></div>
    <div class="stat"><div class="v"><?= number_format((int)$visitors, 0, '', ' ') ?></div><div class="l">посетителей</div></div>
    <div class="stat"><div class="v"><?= (int)$leadsWeek ?></div><div class="l">заявок</div></div>
    <div class="stat"><div class="v"><?= $published ?><span class="of"> / <?= count($all) ?></span></div><div class="l">страниц опубликовано</div></div>
</div>

<div class="grid2">
    <section class="panel">
        <div class="panel-h"><h2>Последние изменения</h2><a class="btn ghost sm" href="<?= h(admin_url('history')) ?>">Вся история</a></div>
        <?php if ($recent === []): ?>
            <div class="empty"><?= icon('clock-counter-clockwise') ?><b>Пока ничего не менялось</b><span>Правки страниц появятся здесь.</span></div>
        <?php else: ?>
            <ul class="feed">
                <?php foreach ($recent as $r): ?>
                    <li>
                        <span class="dot<?= $r['actor_type'] === 'agent' ? ' agent' : '' ?>" title="<?= h($r['actor_type']) ?>"><?= h(mb_strtoupper(mb_substr((string)$r['actor_name'], 0, 1))) ?></span>
                        <span class="t"><b><?= h($r['action_desc']) ?></b><small><?= h($r['actor_name']) ?> &middot; <?= $r['page_slug'][0] === '@' ? h(\AgeCMS\Audit::label($r['page_slug']) ?? $r['page_slug']) : '/' . h($r['page_slug']) ?></small></span>
                        <small class="num"><?= admin_date($r['created_at']) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-h"><h2>Просмотры по дням</h2></div>
        <ul class="spark-c" aria-label="Просмотры за 7 дней">
            <?php $n = 0; foreach ($days as $date => $d): $n++; $v = (int)$d['views']; $hgt = max(3, (int)round(($v / $max) * 96)); ?>
                <li class="spark-col<?= $n === count($days) ? ' now' : '' ?>" tabindex="0"><svg viewBox="0 0 32 100" preserveAspectRatio="none" aria-hidden="true"><rect x="0" y="<?= 100 - $hgt ?>" width="32" height="<?= $hgt ?>"/></svg><span class="tip"><b><?= number_format($v, 0, '', ' ') . ' ' . $viewsWord($v) ?></b><?= $week[(int)gmdate('w', strtotime($date))] ?>, <?= gmdate('d.m', strtotime($date)) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <div class="spark-l"><?php foreach (array_keys($days) as $date): ?><span><?= $week[(int)gmdate('w', strtotime($date))] ?></span><?php endforeach; ?></div>
    </section>
</div>
