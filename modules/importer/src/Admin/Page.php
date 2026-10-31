<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Admin;

use AgeCMS\Actor;
use AgeCMS\AgeCMSException;
use AgeCMS\ModuleRequest;
use AgeCMS\ModuleResponse;
use AgeCMS\ModuleUpload;
use AgeCMS\NotFoundException;
use AgeCMS\Modules\Importer\Compat;
use AgeCMS\Modules\Importer\Jobs;
use AgeCMS\Modules\Importer\Module;
use AgeCMS\Modules\Importer\Report;
use AgeCMS\Modules\Importer\Runner;

/**
 * «Импорт сайта» in the admin: the form (site address, an uploaded export file or a file from the data/import folder), the
 * list of jobs, the preview with checkboxes, the progress, the report and the rollback. Admin pages of modules cannot bring
 * their own scripts: the progress uses data-ag-step of the core's admin.js (POST with the CSRF token, each request does one
 * more step and returns the new progress fragment). Without JavaScript the page refreshes itself and every refresh does a step.
 */
final class Page
{
    private const BASE = '/admin/m/importer';

    public static function handle(ModuleRequest $request, Actor $actor): ModuleResponse
    {
        if (str_ends_with(rtrim($request->path, '/'), '/job')) {
            $id = (int)($request->body['id'] ?? $request->query['id'] ?? 0);
            if ($id <= 0 || Jobs::get($id) === null) {
                return self::missing($id);
            }
            return $request->method === 'POST' ? self::jobPost($id, $request, $actor) : self::job($id, $request);
        }
        if ($request->method === 'POST') {
            // "/" and "/upload" (the same form; /upload is the page the core passes files to)
            return self::start($request, $actor);
        }
        return self::index();
    }

    /** A job that is not there (deleted, or an address typed by hand): a page that says so, not an error. */
    private static function missing(int $id): ModuleResponse
    {
        $text = $id > 0 ? 'Задания импорта № ' . $id . ' нет: его удалили или адрес набран неверно.' : 'Задание импорта не выбрано.';
        return ModuleResponse::html('Задание импорта', '<div class="notice warn">' . ag_e($text) . '</div><p><a href="' . self::BASE . '/">Все задания и новый импорт</a></p>');
    }

    // ---- start ---------------------------------------------------------------------------------------------------

    private static function index(string $error = '', array $old = []): ModuleResponse
    {
        $e = 'ag_e';
        $caps = Compat::capabilities();
        $body = $error !== '' ? '<div class="notice error">' . $e($error) . '</div>' : '';
        $missing = [];
        foreach (['dom' => 'расширение PHP dom (разбор страниц)', 'curl' => 'расширение PHP curl (загрузка с сайта)', 'images' => 'GD с WebP (картинки)'] as $k => $label) {
            if (!$caps[$k]) {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $body .= '<div class="notice warn">На сервере не хватает: ' . $e(implode(', ', $missing)) . '. Раздел «Система», блок «Требования сервера».</div>';
        }
        $coreGaps = [];
        if (!$caps['redirects']) {
            $coreGaps[] = 'редиректы со старых адресов';
        }
        if (!$caps['richtext']) {
            $coreGaps[] = 'форматированный текст';
        }
        if ($coreGaps !== []) {
            $body .= '<div class="notice warn">Эта версия AgeCMS не умеет: ' . $e(implode(', ', $coreGaps)) . '. Импорт работает, но без этого. Обновите ядро в разделе «Система».</div>';
        }
        $defaults = Module::options();
        $files = self::inboxFiles();
        $max = self::uploadMax();
        // The form goes to /upload, the page that takes files (the core checks them); without a file it works the same as /.
        $action = $max > 0 ? self::BASE . '/upload' : self::BASE . '/';
        $body .= '<section class="panel"><div class="panel-h"><h2>Перенести сайт</h2></div><form method="post" action="' . $action . '"' . ($max > 0 ? ' enctype="multipart/form-data"' : '') . ' class="panel-b">' . ag_admin_csrf_field()
            . '<p class="muted">Переносятся тексты, картинки, структура страниц, меню, заголовки и описания для поисковиков, старые адреса (с них будет переход на новые). Дизайн не переносится: страницы собираются из блоков вашей темы. Сначала будет предпросмотр, на сайте до кнопки «Импортировать» ничего не меняется.</p>'
            . '<label class="f"><span>Адрес сайта</span><input class="in" type="text" name="url" placeholder="https://old-site.ru" value="' . $e($old['url'] ?? '') . '" autocomplete="off"><small class="hint">WordPress, Tilda, 1С-Битрикс, Joomla, Wix, MODX, HostCMS и любой другой сайт.</small></label>';
        if ($max > 0) {
            $body .= '<label class="f"><span>Или файл выгрузки</span><input class="in" type="file" name="upload" accept=".xml,.zip,application/xml,text/xml,application/zip">'
                . '<small class="hint">Выгрузка WordPress («Инструменты», «Экспорт», «Всё содержимое», файл .xml) или архив экспорта проекта Tilda (.zip), до ' . $e(ModuleUpload::sizeText($max)) . '.'
                . ' Файл больше положите по FTP в папку <span class="mono">data/import/</span> сайта и выберите ниже.</small></label>';
        }
        $body .= '<label class="f"><span>' . ($max > 0 ? 'Или файл из папки data/import' : 'Или файл выгрузки') . '</span><select class="in" name="file"><option value="">не выбран</option>';
        foreach ($files as $f) {
            $body .= '<option value="' . $e($f['name']) . '"' . (($old['file'] ?? '') === $f['name'] ? ' selected' : '') . '>' . $e($f['name'] . ' (' . $f['size'] . ')') . '</option>';
        }
        $body .= '</select><small class="hint">' . ($max > 0 ? '' : 'Выгрузка WordPress («Инструменты», «Экспорт», файл .xml) или архив экспорта проекта Tilda (.zip). ')
            . 'Положите файл по FTP в папку <span class="mono">data/import/</span> сайта, он появится в списке.'
            . ($files === [] ? ' Сейчас там пусто.' : '') . '</small></label>';
        $body .= '<div class="row"><label class="f"><span>Сколько страниц брать</span><input class="in" type="number" name="limit" min="1" max="2000" value="' . (int)($old['limit'] ?? $defaults['limit']) . '"></label>'
            . '<label class="f"><span>Если адрес уже занят</span><select class="in" name="conflict"><option value="suffix">добавить к адресу -2</option><option value="skip"' . (($old['conflict'] ?? '') === 'skip' ? ' selected' : '') . '>не переносить страницу</option></select></label></div>'
            . '<label class="chk"><input type="checkbox" name="replace_home" value="1"' . (!empty($old['replace_home']) ? ' checked' : '') . '> Заменить главную страницу содержимым старой главной</label>'
            . '<label class="chk"><input type="checkbox" name="menus" value="1"' . (($old['menus'] ?? '1') !== '' ? ' checked' : '') . '> Заменить меню шапки и подвала меню старого сайта</label>'
            . '<p><button class="btn primary" type="submit">Посмотреть, что будет перенесено</button></p></form></section>';

        $jobs = Jobs::recent(20);
        if ($jobs !== []) {
            $body .= '<section class="panel"><div class="panel-h"><h2>Задания</h2></div><div class="tbl-scroll"><table class="tbl"><thead><tr><th>№</th><th>Источник</th><th>Платформа</th><th>Состояние</th><th>Когда</th></tr></thead><tbody>';
            foreach ($jobs as $j) {
                $src = $j['source_type'] === 'url' ? $j['source'] : Runner::sourceLabel($j) . ' (' . ($j['source_type'] === 'wxr' ? 'выгрузка WordPress' : ($j['source_type'] === 'tilda_zip' ? 'архив Tilda' : 'файл')) . ')';
                $body .= '<tr><td><a href="' . self::BASE . '/job?id=' . $j['id'] . '">' . $j['id'] . '</a></td><td class="mono">' . $e(mb_substr($src, 0, 80)) . '</td><td>' . $e((string)($j['detect']['name'] ?? '')) . '</td><td>' . self::statusTag((string)$j['status']) . '</td><td class="muted">' . $e(str_replace(['T', 'Z'], [' ', ''], (string)$j['created_at'])) . '</td></tr>';
            }
            $body .= '</tbody></table></div></section>';
        }
        return ModuleResponse::html('Импорт сайта', $body, $error !== '' ? 422 : 200);
    }

    private static function start(ModuleRequest $request, Actor $actor): ModuleResponse
    {
        $b = $request->body;
        $url = trim((string)($b['url'] ?? ''));
        $file = basename(trim((string)($b['file'] ?? '')));
        $upload = $request->files[0] ?? null;
        if (count(array_filter([$url !== '', $file !== '', $upload !== null])) > 1) {
            return self::index('Выберите что-то одно: адрес сайта, загруженный файл или файл из папки data/import.', $b);
        }
        $input = $url;
        $stored = null;
        if ($upload !== null) {
            // The core has checked the file (signature, size) and keeps it under data/; the job takes its own copy below.
            $stored = $upload->store();
            $input = (string)ModuleUpload::stored('importer', $stored);
        } elseif ($input === '' && $file !== '') {
            $path = Runner::inbox() . '/' . $file;
            if (!is_file($path)) {
                return self::index('Файл ' . $file . ' не найден в папке data/import.', $b);
            }
            $input = $path;
        }
        if ($input === '') {
            return self::index('Укажите адрес сайта или выберите файл.', $b);
        }
        $args = [
            'conflict' => (string)($b['conflict'] ?? 'suffix'),
            'replace_home' => !empty($b['replace_home']),
            'menus' => !empty($b['menus']),
        ];
        if ((int)($b['limit'] ?? 0) > 0) {
            $args['limit'] = (int)$b['limit'];
        }
        $options = Module::options($args);
        try {
            $id = Runner::create($input, $options, $actor, $upload !== null ? $upload->name : ($input === $url ? null : $file));
        } catch (AgeCMSException $e) {
            return self::index($e->getMessage(), $b);
        } finally {
            if ($stored !== null) {
                ModuleUpload::delete('importer', $stored);
            }
        }
        return ModuleResponse::redirect(self::BASE . '/job?id=' . $id);
    }

    /** The largest export file the upload page takes on this server, 0 when the core cannot take xml and zip from a module. */
    private static function uploadMax(): int
    {
        if (!method_exists(ModuleUpload::class, 'maxBytes')) {
            return 0;
        }
        return ModuleUpload::maxBytes('importer', '/upload');
    }

    /** @return list<array{name:string,size:string}> */
    private static function inboxFiles(): array
    {
        $out = [];
        foreach (glob(Runner::inbox() . '/*') ?: [] as $f) {
            if (is_file($f) && preg_match('~\.(xml|zip|html?)$~i', $f) === 1) {
                $size = (int)filesize($f);
                $out[] = ['name' => basename($f), 'size' => $size >= 1048576 ? round($size / 1048576, 1) . ' МБ' : max(1, (int)round($size / 1024)) . ' КБ'];
            }
        }
        return $out;
    }

    // ---- job -----------------------------------------------------------------------------------------------------

    private static function job(int $id, ModuleRequest $request): ModuleResponse
    {
        $job = Jobs::get($id) ?? throw new NotFoundException('Задание не найдено');
        if (in_array($job['status'], Jobs::ACTIVE, true) && hash_equals((string)$job['token'], (string)($request->query['run'] ?? ''))) {
            $limit = (int)ini_get('max_execution_time');
            $budget = $limit > 0 ? max(5, min(20, $limit - 8)) : 20;
            $job = (new Runner())->step($id, microtime(true) + $budget);
        }
        $r = Report::job($id);
        $e = 'ag_e';
        $body = '<p><a href="' . self::BASE . '/">Все задания</a></p>';
        $body .= self::summary($r, $job);
        if (in_array($job['status'], Jobs::ACTIVE, true)) {
            $next = self::BASE . '/job?id=' . $id . '&run=' . $job['token'];
            // With JavaScript: admin.js posts to the step address until the job is no longer active, then opens the report.
            // Without it: the page refreshes itself (the refresh with run= does a step).
            $body .= '<div data-ag-step="' . $e(self::BASE . '/job?id=' . $id . '&action=step') . '" data-ag-step-interval="600">' . self::live($id, $job, $r) . '</div>';
            $body .= '<noscript><meta http-equiv="refresh" content="1;url=' . $e($next) . '"></noscript>';
            $body .= '<p class="muted">Работа идёт порциями, пока страница открыта. Если прогресс остановился, <a href="' . $e($next) . '">продолжить</a>. Можно закрыть страницу: работа продолжится при заходах на сайт, но медленнее.</p>';
            return ModuleResponse::html('Импорт: ' . Runner::statusText($job['status']), $body);
        }
        if ($job['status'] === 'failed') {
            $body .= '<div class="notice error">' . $e((string)$job['error']) . '</div>';
        }
        $body .= match ($job['status']) {
            'ready' => self::preview($r, $id),
            'done', 'failed', 'rolled_back' => self::result($r, $job),
            default => '',
        };
        return ModuleResponse::html('Импорт: ' . Runner::statusText($job['status']), $body);
    }

    /**
     * The part of the job page that changes while the job works: the progress (and the stop button of the analysis). Once
     * the job is no longer active it carries data-ag-done with the job address: admin.js stops and opens the report.
     *
     * @param array<string,mixed> $job @param array<string,mixed> $r
     */
    private static function live(int $id, array $job, array $r): string
    {
        if (!in_array($job['status'], Jobs::ACTIVE, true)) {
            return '<section class="panel"><div class="panel-b"><p>' . ag_e(Runner::statusText((string)$job['status'])) . ': открываю отчёт.</p></div></section>'
                . '<span data-ag-done="' . ag_e(self::BASE . '/job?id=' . $id) . '" hidden></span>';
        }
        $html = self::progress($r, $job);
        if ($job['status'] === 'analyzing') {
            $html .= '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" class="inline">' . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="stop"><button class="btn sm" type="submit">Остановить анализ</button></form>';
        }
        return $html;
    }

    private static function jobPost(int $id, ModuleRequest $request, Actor $actor): ModuleResponse
    {
        $job = Jobs::get($id) ?? throw new NotFoundException('Задание не найдено');
        $action = (string)($request->body['action'] ?? $request->query['action'] ?? '');
        if ($action === 'step') {
            // One portion of work for data-ag-step (admin.js); a short budget keeps the progress moving on the screen.
            if (in_array($job['status'], Jobs::ACTIVE, true)) {
                $limit = (int)ini_get('max_execution_time');
                $budget = $limit > 0 ? max(3, min(8, $limit - 8)) : 8;
                $job = (new Runner())->step($id, microtime(true) + $budget);
            }
            if ($request->poll) {
                return ModuleResponse::html('Импорт: ' . Runner::statusText((string)$job['status']), self::live($id, $job, Report::job($id)));
            }
            return ModuleResponse::redirect(self::BASE . '/job?id=' . $id);
        }
        try {
            switch ($action) {
                case 'apply':
                    $pages = array_values(array_map('intval', (array)($request->body['pages'] ?? [])));
                    Runner::startApply($id, $pages, $actor);
                    break;
                case 'rollback':
                    Runner::startRollback($id, $actor);
                    break;
                case 'stop':
                    if ($job['status'] === 'analyzing') {
                        Jobs::update($id, ['status' => 'failed', 'error' => 'Анализ остановлен.']);
                    }
                    break;
                case 'delete':
                    if (!in_array($job['status'], ['ready', 'failed', 'rolled_back'], true)) {
                        throw new \AgeCMS\Modules\Importer\ImportException('Удалить можно задание, которое не применено или уже отменено.');
                    }
                    Jobs::delete($id);
                    self::removeDir(Runner::dir($id));
                    return ModuleResponse::redirect(self::BASE . '/');
                default:
                    throw new NotFoundException('Неизвестное действие');
            }
        } catch (\AgeCMS\Modules\Importer\ImportException $e) {
            return ModuleResponse::html('Импорт', '<div class="notice error">' . ag_e($e->getMessage()) . '</div><p><a href="' . self::BASE . '/job?id=' . $id . '">Вернуться к заданию</a></p>', 422);
        }
        return ModuleResponse::redirect(self::BASE . '/job?id=' . $id . (in_array($action, ['apply', 'rollback'], true) ? '&run=' . (Jobs::get($id)['token'] ?? '') : ''));
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $job */
    private static function summary(array $r, array $job): string
    {
        $e = 'ag_e';
        $p = $r['platform'];
        $html = '<section class="panel"><div class="panel-h"><h2>' . $e(Runner::sourceLabel($job)) . '</h2>' . self::statusTag((string)$job['status']) . '</div><div class="panel-b">';
        if ($p['id'] !== '') {
            $html .= '<p><b>' . $e($p['name']) . '</b> <span class="muted">(уверенность ' . (int)round($p['confidence'] * 100) . '%)</span></p>';
            if ($p['evidence'] !== []) {
                $html .= '<p class="muted">Признаки: ' . $e(implode('; ', $p['evidence'])) . '</p>';
            }
        }
        if ($r['site']['title'] !== '') {
            $html .= '<p>Сайт: ' . $e($r['site']['title']) . '</p>';
        }
        $c = $r['counts'];
        $html .= '<p>Найдено страниц: <b>' . $c['pages'] . '</b>' . ($c['posts'] > 0 ? ', из них записей блога: ' . $c['posts'] : '') . '.';
        if ($job['status'] !== 'analyzing') {
            $html .= ' Будет создано: <b>' . $c['create'] . '</b>' . ($c['update'] > 0 ? ', главная будет заменена' : '') . ', пропущено: ' . $c['skip'] . ', переходов со старых адресов: ' . $c['redirects'] . '.';
        }
        $html .= '</p></div></section>';
        if ($r['warnings'] !== []) {
            $html .= '<div class="notice warn"><div>' . implode('<br>', array_map($e, $r['warnings'])) . '</div></div>';
        }
        return $html;
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $job */
    private static function progress(array $r, array $job): string
    {
        $c = $r['counts'];
        if ($job['status'] === 'analyzing') {
            return '<section class="panel"><div class="panel-b"><p>Читаю сайт: найдено страниц ' . $r['found'] . ' из не больше чем ' . (int)($job['options']['limit'] ?? 200) . '.</p><progress max="' . (int)($job['options']['limit'] ?? 200) . '" value="' . $r['found'] . '"></progress></div></section>';
        }
        if ($job['status'] === 'applying') {
            $total = $c['done'] + $c['pending'] + $c['failed'];
            return '<section class="panel"><div class="panel-b"><p>Создаю страницы и загружаю картинки: ' . $c['done'] . ' из ' . $total . ($c['failed'] > 0 ? ', с ошибкой: ' . $c['failed'] : '') . '.</p><progress max="' . max(1, $total) . '" value="' . ($c['done'] + $c['failed']) . '"></progress></div></section>';
        }
        $total = $c['done'] + $c['rolled_back'] + $c['kept'];
        return '<section class="panel"><div class="panel-b"><p>Отменяю импорт: убрано страниц ' . $c['rolled_back'] . ' из ' . $total . '.</p><progress max="' . max(1, $total) . '" value="' . ($c['rolled_back'] + $c['kept']) . '"></progress></div></section>';
    }

    /** @param array<string,mixed> $r */
    private static function preview(array $r, int $id): string
    {
        $e = 'ag_e';
        $html = '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" data-confirm="Перенести выбранные страницы?" data-confirm-text="Страницы появятся на сайте сразу. Импорт можно отменить кнопкой «Отменить импорт»." data-confirm-ok="Импортировать">'
            . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . (int)($job['id'] ?? $id) . '"><input type="hidden" name="action" value="apply">';
        $html .= '<section class="panel"><div class="panel-h"><h2>Что будет перенесено</h2><span class="muted">снимите галочку, чтобы не переносить страницу</span></div><div class="tbl-scroll"><table class="tbl"><thead><tr><th></th><th>Было</th><th>Станет</th><th>Страница</th><th>Блоки</th><th>Потери</th></tr></thead><tbody>';
        $rows = $r['pages'];
        usort($rows, static fn(array $a, array $b): int => [$a['slug'] === 'index' ? 0 : 1, $a['slug']] <=> [$b['slug'] === 'index' ? 0 : 1, $b['slug']]);
        foreach ($rows as $row) {
            $depth = substr_count($row['slug'], '/');
            $indent = $depth > 0 ? str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . '└ ' : '';
            $box = $row['new'] ? '<span class="muted" title="Раздел для записей создаётся сам">+</span>'
                : '<input type="checkbox" name="pages[]" value="' . (int)$row['id'] . '"' . ($row['selected'] ? ' checked' : '') . ' aria-label="Переносить ' . $e($row['title']) . '">';
            $new = $row['action'] === 'skip' || !$row['selected'] ? '<span class="muted">не переносится</span>' : '<span class="mono">' . $indent . $e($row['slug'] === 'index' ? '/' : '/' . $row['slug']) . '</span>';
            $note = $row['reason'] !== '' ? '<br><small class="muted">' . $e($row['reason']) . '</small>' : '';
            $blocks = '';
            if ($row['blocks'] !== []) {
                $blocks = '<details><summary>' . count($row['blocks']) . ' ' . self::plural(count($row['blocks']), 'блок', 'блока', 'блоков') . '</summary><small>'
                    . implode('<br>', array_map(static fn(array $b): string => ag_e($b['type'] . ($b['summary'] !== '' ? ': ' . $b['summary'] : '')), $row['blocks'])) . '</small></details>';
            }
            $losses = $row['losses'] === [] ? '<span class="muted">нет</span>' : '<details><summary>' . count($row['losses']) . '</summary><small>'
                . implode('<br>', array_map(static fn(array $l): string => ag_e($l['what'] . ': ' . $l['why']), $row['losses'])) . '</small></details>';
            $html .= '<tr><td>' . $box . '</td><td class="mono">' . ($row['new'] ? '<span class="muted">новый раздел</span>' : $e(mb_substr($row['old_path'], 0, 70))) . '</td><td>' . $new . $note . '</td><td>' . $e(mb_substr($row['title'], 0, 80))
                . ($row['kind'] === 'post' ? '<br><small class="muted">запись' . ($row['published_at'] !== '' ? ' от ' . $e($row['published_at']) : '') . '</small>' : '') . '</td><td>' . $blocks . '</td><td>' . $losses . '</td></tr>';
        }
        $html .= '</tbody></table></div></section>';
        $html .= self::menusAndRedirects($r);
        $html .= '<p class="actions-row"><button class="btn primary" type="submit">Импортировать</button></p></form>';
        $html .= '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" class="inline" data-confirm="Удалить задание?" data-confirm-ok="Удалить">' . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . (int)($job['id'] ?? $id) . '"><input type="hidden" name="action" value="delete"><button class="btn sm ghost" type="submit">Удалить задание</button></form>';
        return $html;
    }

    /** @param array<string,mixed> $r */
    private static function menusAndRedirects(array $r): string
    {
        $e = 'ag_e';
        $html = '';
        foreach (['header' => 'Меню шапки', 'footer' => 'Меню подвала'] as $name => $label) {
            $items = (array)($r['menus'][$name] ?? []);
            if ($items !== []) {
                $html .= '<p><b>' . $label . ':</b> ' . implode(', ', array_map(static fn(array $i): string => ag_e($i['title']) . ' <span class="muted mono">' . ag_e($i['url']) . '</span>', $items)) . '</p>';
            }
        }
        if ($r['redirects'] !== []) {
            $html .= '<details><summary>Переходы со старых адресов (' . count($r['redirects']) . ')</summary><div class="tbl-scroll"><table class="tbl"><tbody>';
            foreach (array_slice($r['redirects'], 0, 1000) as $rd) {
                $html .= '<tr><td class="mono">' . $e($rd['from']) . '</td><td class="mono">' . $e($rd['to']) . '</td></tr>';
            }
            $html .= '</tbody></table></div></details>';
        }
        return $html === '' ? '' : '<section class="panel"><div class="panel-b">' . $html . '</div></section>';
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $job */
    private static function result(array $r, array $job): string
    {
        $e = 'ag_e';
        $c = $r['counts'];
        $res = $r['result'];
        $html = '<section class="panel"><div class="panel-h"><h2>Отчёт</h2></div><div class="panel-b">';
        if ($job['status'] === 'rolled_back') {
            $html .= '<div class="notice ok">Импорт отменён: убрано страниц ' . $c['rolled_back'] . '.' . ($c['kept'] > 0 ? ' Оставлено ' . $c['kept'] . ': их меняли после импорта.' : '') . ' Удалённые страницы лежат в корзине.</div>';
        } else {
            $html .= '<p>Создано страниц: <b>' . $c['done'] . '</b>' . ($c['failed'] > 0 ? ', не получилось: <b>' . $c['failed'] . '</b>' : '') . '. Потерь при переносе: ' . $c['losses'] . '.</p>';
        }
        if (isset($res['redirects'])) {
            $html .= '<p>Переходов со старых адресов добавлено: ' . count($res['redirects']['added'] ?? []) . (($res['redirects']['refused'] ?? []) !== [] ? ', не принято: ' . count($res['redirects']['refused']) : '') . '.</p>';
            if (($res['redirects']['note'] ?? '') !== '') {
                $html .= '<p class="muted">' . $e($res['redirects']['note']) . '</p>';
            }
            if (($res['redirects']['refused'] ?? []) !== []) {
                $html .= '<details><summary>Не принятые переходы</summary><small>' . implode('<br>', array_map(static fn(array $x): string => ag_e($x['from'] . ' -> ' . $x['to'] . ': ' . $x['why']), array_slice($res['redirects']['refused'], 0, 300))) . '</small></details>';
            }
        }
        foreach ((array)($res['menus'] ?? []) as $name => $m) {
            $html .= '<p>Меню ' . ($name === 'header' ? 'шапки' : 'подвала') . ': ' . (isset($m['error']) ? 'не заменено (' . $e($m['error']) . ')' : 'заменено, пунктов ' . count($m['after'])) . '.</p>';
        }
        foreach (array_merge((array)($res['notes'] ?? []), (array)($res['rollback_notes'] ?? [])) as $n) {
            $html .= '<p class="muted">' . $e($n) . '</p>';
        }
        $html .= '</div></section>';

        $html .= '<section class="panel"><div class="panel-h"><h2>Страницы</h2></div><div class="tbl-scroll"><table class="tbl"><thead><tr><th>Было</th><th>Стало</th><th>Состояние</th><th>Потери</th></tr></thead><tbody>';
        foreach ($r['pages'] as $row) {
            if (!$row['selected'] && $row['status'] === 'skipped') {
                continue;
            }
            $state = match ($row['status']) {
                'done' => '<span class="tag ok">создана</span>',
                'failed' => '<span class="tag err">ошибка</span><br><small>' . $e(mb_substr($row['error'], 0, 300)) . '</small>',
                'skipped' => '<span class="tag plain">пропущена</span>' . ($row['reason'] !== '' ? '<br><small class="muted">' . $e($row['reason']) . '</small>' : ''),
                'rolled_back' => '<span class="tag plain">убрана</span>',
                'kept' => '<span class="tag warn">оставлена</span><br><small>' . $e($row['error']) . '</small>',
                default => $e($row['status']),
            };
            $link = $row['status'] === 'done' ? '<a class="mono" href="/' . $e($row['slug'] === 'index' ? '' : $row['slug']) . '" target="_blank" rel="noopener">/' . $e($row['slug'] === 'index' ? '' : $row['slug']) . '</a>' : '<span class="mono">/' . $e($row['slug'] === 'index' ? '' : $row['slug']) . '</span>';
            $losses = $row['losses'] === [] ? '<span class="muted">нет</span>' : '<details><summary>' . count($row['losses']) . '</summary><small>' . implode('<br>', array_map(static fn(array $l): string => ag_e($l['what'] . ': ' . $l['why']), $row['losses'])) . '</small></details>';
            $html .= '<tr><td class="mono">' . ($row['new'] ? '<span class="muted">новый раздел</span>' : $e(mb_substr($row['old_path'], 0, 70))) . '</td><td>' . $link . '</td><td>' . $state . '</td><td>' . $losses . '</td></tr>';
        }
        $html .= '</tbody></table></div></section>';
        if (in_array($job['status'], ['done', 'failed'], true) && $c['done'] > 0) {
            $html .= '<form method="post" action="' . self::BASE . '/job?id=' . (int)$job['id'] . '" data-confirm="Отменить импорт?" data-confirm-text="Созданные импортом страницы уйдут в корзину (кроме изменённых после импорта), переходы со старых адресов удалятся, меню вернётся прежнее. Предохранитель это не останавливает: удаляются только страницы этого импорта." data-confirm-ok="Отменить импорт" data-danger>'
                . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . (int)($job['id'] ?? $id) . '"><input type="hidden" name="action" value="rollback"><button class="btn danger" type="submit">Отменить импорт</button></form>';
        }
        if (in_array($job['status'], ['failed', 'rolled_back'], true)) {
            $html .= '<form method="post" action="' . self::BASE . '/job?id=' . (int)$job['id'] . '" class="inline" data-confirm="Удалить задание?" data-confirm-ok="Удалить">' . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . (int)($job['id'] ?? $id) . '"><input type="hidden" name="action" value="delete"><button class="btn sm ghost" type="submit">Удалить задание</button></form>';
        }
        return $html;
    }

    private static function statusTag(string $status): string
    {
        $kind = match ($status) {
            'done', 'ready' => 'ok',
            'failed' => 'err',
            'analyzing', 'applying', 'rolling_back' => 'warn',
            default => 'plain',
        };
        return '<span class="tag ' . $kind . '">' . ag_e(Runner::statusText($status)) . '</span>';
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        return $n > 10 && $n < 20 ? $many : ($n1 > 1 && $n1 < 5 ? $few : ($n1 === 1 ? $one : $many));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
