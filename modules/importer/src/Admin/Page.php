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
use AgeCMS\Modules\Importer\Copy\CaptureFlow;
use AgeCMS\Modules\Importer\Copy\CaptureJob;
use AgeCMS\Modules\Importer\Copy\CaptureRunner;
use AgeCMS\Modules\Importer\Detect\Detector;
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
        if (str_ends_with(rtrim($request->path, '/'), '/copy-edit')) {
            return TextEdit::handle($request, $actor);
        }
        if (str_ends_with(rtrim($request->path, '/'), '/copy')) {
            return CopyEditor::handle($request, $actor);
        }
        if ($request->method === 'POST') {
            if (($request->body['action'] ?? '') === 'selftest') {
                $t = CaptureRunner::selfTest(true);
                return $t['ok'] ? self::index('', [], 'Проверка пройдена: Chrome на сервере запускается и открывает страницы.') : self::index('Проверка не пройдена: ' . $t['message']);
            }
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

    private static function index(string $error = '', array $old = [], string $notice = ''): ModuleResponse
    {
        $e = 'ag_e';
        $caps = Compat::capabilities();
        $body = $error !== '' ? '<div class="notice error">' . $e($error) . '</div>' : '';
        $body .= $notice !== '' ? '<div class="notice ok">' . $e($notice) . '</div>' : '';
        $busy = Jobs::capturing();
        if ($busy !== null) {
            $body .= '<div class="notice warn">Сейчас идёт захват сайта ' . $e(Runner::sourceLabel($busy)) . ' (<a href="' . self::BASE . '/job?id=' . (int)$busy['id'] . '">задание ' . (int)$busy['id'] . '</a>). Пока он не закончится, новый захват не начнётся.</div>';
        }
        $capProblem = CaptureRunner::problem();
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
        $files = self::inboxFiles();
        $max = self::uploadMax();
        // The form goes to /upload, the page that takes files (the core checks them); without a file it works the same as /.
        $action = $max > 0 ? self::BASE . '/upload' : self::BASE . '/';
        $body .= '<section class="panel"><div class="panel-h"><h2>Перенести сайт</h2></div><form method="post" action="' . $action . '"' . ($max > 0 ? ' enctype="multipart/form-data"' : '') . ' class="panel-b">' . ag_admin_csrf_field()
            . '<p class="muted">Введите адрес и нажмите «Импорт»: сервер осмотрит сайт, покажет, сколько на нём страниц и сколько это займёт, и после вашего согласия перенесёт его. Старые адреса получат переход на новые, заголовки и описания для поисковиков переносятся в обоих режимах. Перенос можно отменить одной кнопкой.</p>'
            . '<fieldset class="f"><legend>Как переносить</legend>'
            . '<label class="chk"><input type="radio" name="mode" value="exact"' . (($old['mode'] ?? 'exact') !== 'blocks' ? ' checked' : '') . '> <span><b>Точная копия</b>: страница выглядит и работает как на старом сайте (и на компьютере, и на телефоне), но не собрана из блоков темы. Сервер открывает сайт в Chrome в фоновом режиме, это занимает несколько минут.' . ($capProblem === null ? '' : ' <span class="muted">Сейчас недоступно (см. ниже), можно загрузить готовый пакет захвата.</span>') . '</span></label>'
            . '<label class="chk"><input type="radio" name="mode" value="blocks"' . (($old['mode'] ?? '') === 'blocks' ? ' checked' : '') . '> <span><b>В блоки темы</b>: тексты и картинки собираются из блоков вашей темы, оформление берётся из темы. Подходит, когда сайт будет жить с новым дизайном.</span></label></fieldset>'
            . '<label class="f"><span>Адрес сайта</span><input class="in" type="text" name="url" placeholder="https://old-site.ru" value="' . $e($old['url'] ?? '') . '" autocomplete="off"><small class="hint">WordPress, Tilda, 1С-Битрикс, Joomla, Wix, MODX, HostCMS и любой другой сайт. Только публичные адреса: адреса внутренней сети и самого сервера не принимаются.</small></label>';
        if ($max > 0) {
            $body .= '<label class="f"><span>Или файл выгрузки</span><input class="in" type="file" name="upload" accept=".xml,.zip,application/xml,text/xml,application/zip">'
                . '<small class="hint">Пакет захвата (.zip из tools/capture), выгрузка WordPress («Инструменты», «Экспорт», «Всё содержимое», файл .xml) или архив экспорта проекта Tilda (.zip), до ' . $e(ModuleUpload::sizeText($max)) . '.'
                . ' Файл больше положите по FTP в папку <span class="mono">data/import/</span> сайта и выберите ниже.</small></label>';
        }
        $body .= '<label class="f"><span>' . ($max > 0 ? 'Или файл из папки data/import' : 'Или файл выгрузки') . '</span><select class="in" name="file"><option value="">не выбран</option>';
        foreach ($files as $f) {
            $body .= '<option value="' . $e($f['name']) . '"' . (($old['file'] ?? '') === $f['name'] ? ' selected' : '') . '>' . $e($f['name'] . ' (' . $f['size'] . ')') . '</option>';
        }
        $body .= '</select><small class="hint">' . ($max > 0 ? '' : 'Выгрузка WordPress («Инструменты», «Экспорт», файл .xml) или архив экспорта проекта Tilda (.zip). ')
            . 'Положите файл по FTP в папку <span class="mono">data/import/</span> сайта, он появится в списке.'
            . ($files === [] ? ' Сейчас там пусто.' : '') . '</small></label>';
        $body .= '<details class="f"><summary>' . ($capProblem === null ? 'Захват на сервере работает. Пакет захвата можно сделать и на своём компьютере' : 'Захват на сервере недоступен: ' . $e($capProblem)) . '</summary><div class="muted"><p>Страницы берутся такими, какими их рисует Chrome. На сервере для этого нужны Node.js 20+ и Chrome (' . ($capProblem === null ? 'они есть' : 'их нет или они не запускаются') . '); без них пакет делается на вашем компьютере.</p>'
            . '<pre class="mono">cd tools/capture\nnpm install\nnode capture.mjs https://old-site.ru --zip</pre>'
            . '<p>Для экспорта Tilda вместо адреса укажите архив или папку экспорта. Получится файл .zip: загрузите его выше (или положите по FTP в <span class="mono">data/import/</span>). В пакете нет чужих счётчиков, чатов и сторонних скриптов. С машины, где есть Node.js и Chrome, то же делает одна команда: <span class="mono">php modules/importer/bin/import.php https://old-site.ru --mode=exact --apply</span>.</p></div></details>';
        $body .= '<div class="row"><label class="f"><span>Сколько страниц брать</span><input class="in" type="number" name="limit" min="1" max="2000" value="' . ((int)($old['limit'] ?? 0) > 0 ? (int)$old['limit'] : '') . '" placeholder="' . (int)Module::options(['mode' => 'exact'])['limit'] . ' для точной копии, ' . (int)Module::options(['mode' => 'blocks'])['limit'] . ' для блоков"></label>'
            . '<label class="f"><span>Если адрес уже занят</span><select class="in" name="conflict"><option value="suffix">добавить к адресу -2</option><option value="skip"' . (($old['conflict'] ?? '') === 'skip' ? ' selected' : '') . '>не переносить страницу</option></select></label></div>'
            . '<label class="chk"><input type="checkbox" name="replace_home" value="1"' . (!empty($old['replace_home']) ? ' checked' : '') . '> Заменить главную страницу содержимым старой главной</label>'
            . '<label class="chk"><input type="checkbox" name="menus" value="1"' . (($old['menus'] ?? '1') !== '' ? ' checked' : '') . '> Заменить меню шапки и подвала меню старого сайта</label>'
            . '<p><button class="btn primary" type="submit">Импорт</button></p></form>'
            . '<form method="post" action="' . self::BASE . '/" class="panel-b inline">' . ag_admin_csrf_field() . '<input type="hidden" name="action" value="selftest"><button class="btn sm ghost" type="submit">Проверить захват на сервере</button> <span class="muted">запускает Chrome и открывает пустую страницу</span></form></section>';

        $jobs = Jobs::recent(20);
        if ($jobs !== []) {
            $body .= '<section class="panel"><div class="panel-h"><h2>Задания</h2></div><div class="tbl-scroll"><table class="tbl"><thead><tr><th>№</th><th>Источник</th><th>Платформа</th><th>Состояние</th><th>Когда</th></tr></thead><tbody>';
            foreach ($jobs as $j) {
                $src = $j['source_type'] === 'url' || (string)($j['result']['captured_from'] ?? '') !== '' ? (string)($j['result']['captured_from'] ?? $j['source']) : Runner::sourceLabel($j) . ' (' . ($j['source_type'] === 'wxr' ? 'выгрузка WordPress' : ($j['source_type'] === 'tilda_zip' ? 'архив Tilda' : (in_array($j['source_type'], ['capture_zip', 'capture_dir'], true) ? 'пакет захвата' : 'файл'))) . ')';
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
            'mode' => ($b['mode'] ?? 'exact') === 'blocks' ? 'blocks' : 'exact',
        ];
        if ((int)($b['limit'] ?? 0) > 0) {
            $args['limit'] = (int)$b['limit'];
        }
        $options = Module::options($args);
        try {
            $id = Runner::create($input, $options, $actor, $upload !== null ? $upload->name : ($input === $url ? null : $file), $input !== $url);
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
            $body .= '<div data-ag-step="' . $e(self::BASE . '/job?id=' . $id . '&action=step') . '" data-ag-step-interval="' . (in_array($job['status'], Jobs::CAPTURING, true) ? '1500' : '600') . '">' . self::live($id, $job, $r) . '</div>';
            $body .= '<noscript><meta http-equiv="refresh" content="1;url=' . $e($next) . '"></noscript>';
            $body .= in_array($job['status'], Jobs::CAPTURING, true)
                ? '<p class="muted">Захват идёт на сервере сам, страницу можно закрыть и вернуться позже: задание останется в списке. Если прогресс не двигается, <a href="' . $e($next) . '">обновить</a>.</p>'
                : '<p class="muted">Работа идёт порциями, пока страница открыта. Если прогресс остановился, <a href="' . $e($next) . '">продолжить</a>. Можно закрыть страницу: работа продолжится при заходах на сайт, но медленнее.</p>';
            return ModuleResponse::html('Импорт: ' . Runner::statusText($job['status']), $body);
        }
        if (in_array($job['status'], ['failed', 'cancelled'], true)) {
            $body .= '<div class="notice ' . ($job['status'] === 'failed' ? 'error' : 'warn') . '">' . $e($job['error'] !== '' ? (string)$job['error'] : 'Задание остановлено.') . '</div>';
            if ($job['status'] === 'failed' && $r['found'] === 0 && ($job['source_type'] === 'url' || isset($job['result']['capture']))) {
                $body .= self::captureNumbers($job);
            }
        }
        $body .= match (true) {
            $job['status'] === 'ready' => self::preview($r, $id),
            $job['status'] === 'scouted' => self::scouted($job),
            in_array($job['status'], ['failed', 'cancelled'], true) && $r['found'] === 0 => self::retry($job),
            in_array($job['status'], ['done', 'failed', 'rolled_back'], true) => self::result($r, $job),
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
        if (in_array($job['status'], Jobs::CAPTURING, true)) {
            $html .= '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" class="inline" data-confirm="Остановить захват?" data-confirm-text="Chrome будет закрыт, то, что успело скачаться, удалится. На вашем сайте ничего не изменилось." data-confirm-ok="Остановить" data-danger>' . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="cancel"><button class="btn sm" type="submit">Остановить захват</button></form>';
        }
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
                case 'capture':
                    CaptureFlow::start($id, $actor, [
                        'limit' => (int)($request->body['limit'] ?? 0),
                        'auto_apply' => !empty($request->body['auto_apply']),
                    ]);
                    break;
                case 'cancel':
                    CaptureFlow::cancel($id);
                    return ModuleResponse::redirect(self::BASE . '/job?id=' . $id);
                case 'stop':
                    if ($job['status'] === 'analyzing') {
                        Jobs::update($id, ['status' => 'failed', 'error' => 'Анализ остановлен.']);
                    }
                    break;
                case 'delete':
                    if (!in_array($job['status'], ['ready', 'failed', 'rolled_back', 'scouted', 'cancelled'], true)) {
                        throw new \AgeCMS\Modules\Importer\ImportException('Удалить можно задание, которое не применено или уже отменено.');
                    }
                    $copyKey = (string)($job['result']['copy']['key'] ?? '');
                    Jobs::delete($id);
                    self::removeDir(Runner::dir($id));
                    if ($copyKey !== '' && \AgeCMS\CopyPage::keyValid($copyKey) && !\AgeCMS\CopyPage::keyInUse($copyKey)) {
                        \AgeCMS\CopyPage::removeFiles($copyKey);
                    }
                    return ModuleResponse::redirect(self::BASE . '/');
                default:
                    throw new NotFoundException('Неизвестное действие');
            }
        } catch (\AgeCMS\Modules\Importer\ImportException $e) {
            return ModuleResponse::html('Импорт', '<div class="notice error">' . ag_e($e->getMessage()) . '</div><p><a href="' . self::BASE . '/job?id=' . $id . '">Вернуться к заданию</a></p>', 422);
        }
        return ModuleResponse::redirect(self::BASE . '/job?id=' . $id . (in_array($action, ['apply', 'rollback', 'capture'], true) ? '&run=' . (Jobs::get($id)['token'] ?? '') : ''));
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $job */
    private static function summary(array $r, array $job): string
    {
        $e = 'ag_e';
        $p = $r['platform'];
        $shown = (string)($job['result']['captured_from'] ?? '') !== '' ? (string)$job['result']['captured_from'] : Runner::sourceLabel($job);
        $html = '<section class="panel"><div class="panel-h"><h2 class="imp-cur">' . $e($shown) . '</h2>' . self::statusTag((string)$job['status']) . '</div><div class="panel-b">';
        // A job that failed before anything was read has no platform to name: "plain HTML, 0%" would only mislead.
        if ($p['id'] !== '' && ((string)($job['detect']['platform'] ?? '') !== '' || $r['found'] > 0)) {
            $html .= '<p><b>' . $e($p['name']) . '</b> <span class="muted">(уверенность ' . (int)round($p['confidence'] * 100) . '%)</span></p>';
            if ($p['evidence'] !== []) {
                $html .= '<p class="muted">Признаки: ' . $e(implode('; ', $p['evidence'])) . '</p>';
            }
        }
        if ($r['site']['title'] !== '') {
            $html .= '<p>Сайт: ' . $e($r['site']['title']) . '</p>';
        }
        $c = $r['counts'];
        if ($r['found'] > 0) {
            $html .= '<p>Найдено страниц: <b>' . $c['pages'] . '</b>' . ($c['posts'] > 0 ? ', из них записей блога: ' . $c['posts'] : '') . '.';
            if ($job['status'] !== 'analyzing') {
                // Sections the importer makes itself (the blog) are pages too: they are named, so found and created add up.
                $word = match ((string)$job['status']) {
                    'done' => ' Создано страниц: ', 'rolled_back' => ' Было создано страниц: ', 'applying', 'rolling_back' => ' Создаётся страниц: ', 'failed' => ' Планировалось создать страниц: ',
                    default => ' Будет создано страниц: ',
                };
                $html .= $word . '<b>' . ($job['status'] === 'done' || $job['status'] === 'rolled_back' ? max($c['create'], $c['done'] + $c['rolled_back'] + $c['kept']) : $c['create']) . '</b>' . ($c['sections'] > 0 ? ' (из них новых разделов: ' . $c['sections'] . ')' : '') . ($c['update'] > 0 ? ', главная будет заменена' : '') . (in_array((string)$job['status'], ['done', 'rolled_back'], true) ? ', не перенесено: ' : ', не переносится: ') . ($c['skip'] - $c['sections_skipped']) . ', переходов со старых адресов: ' . $c['redirects'] . '.';
            }
            $html .= '</p>';
        }
        if (isset($job['result']['capture']['done'])) {
            $html .= self::captureNumbers($job, true);
        }
        if (($r['mode'] ?? '') === 'exact' && isset($r['copy']) && $r['found'] > 0) {
            $k = $r['copy'];
            $html .= '<p><b>Точная копия.</b> Файлов: ' . (int)$k['files'] . ', размер около ' . self::mb((int)$k['bytes']) . '. '
                . ($k['trackers'] === [] ? 'Чужих счётчиков и чатов на страницах не было.' : 'Не перенесены счётчики и чаты: ' . $e(implode(', ', $k['trackers'])) . '.')
                . ' Форм: ' . (int)$k['forms'] . ($k['forms_unconnected'] > 0 ? ', не подключено: ' . (int)$k['forms_unconnected'] : ', заявки с них пойдут в раздел «Заявки»') . '.</p>';
        } elseif (($r['mode'] ?? '') === 'blocks') {
            $html .= '<p class="muted">Режим «В блоки темы».</p>';
        }
        $html .= '</div></section>';
        if ($r['warnings'] !== []) {
            $html .= '<div class="notice warn"><div>' . implode('<br>', array_map($e, $r['warnings'])) . '</div></div>';
        }
        return $html;
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $job */
    private static function progress(array $r, array $job): string
    {
        $c = $r['counts'];
        if (in_array($job['status'], Jobs::CAPTURING, true)) {
            return self::captureProgress($job);
        }
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

    /** Phases of the capture tool as a person reads them. */
    private const PHASES = [
        'start' => 'Запускаю инструмент захвата', 'browser' => 'Запускаю Chrome', 'discover' => 'Ищу страницы сайта', 'scout' => 'Осматриваю главную страницу',
        'crawl' => 'Открываю страницы и скачиваю файлы', 'build' => 'Собираю копию страниц', 'verify' => 'Сверяю копию с оригиналом', 'pack' => 'Упаковываю результат', 'done' => 'Готово',
    ];

    private static function elapsed(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return $seconds >= 3600 ? intdiv($seconds, 3600) . ' ч ' . intdiv($seconds % 3600, 60) . ' мин' : ($seconds >= 60 ? intdiv($seconds, 60) . ' мин ' . ($seconds % 60) . ' с' : $seconds . ' с');
    }

    /** The live panel of a running capture: phase, pages, volume, time, the page being opened. @param array<string,mixed> $job */
    private static function captureProgress(array $job): string
    {
        $e = 'ag_e';
        $c = (array)($job['result']['capture'] ?? []);
        $scout = $job['status'] === 'scouting';
        $phase = self::PHASES[(string)($c['phase'] ?? 'start')] ?? (string)($c['message'] ?? '');
        $lim = max(1, (int)($c['limit'] ?? 0) ?: CaptureJob::limits($job['options'])['pages']);
        $pages = (int)($c['pages'] ?? 0);
        $html = '<section class="panel"><div class="panel-h"><h2>' . ($scout ? 'Осматриваю сайт' : 'Копирую сайт в Chrome') . '</h2></div><div class="panel-b"><p><b>' . $e($phase) . '</b>'
            . (($c['message'] ?? '') !== '' && ($c['message'] ?? '') !== $phase ? ' <span class="muted">' . $e((string)$c['message']) . '</span>' : '') . '</p>';
        if (!$scout) {
            $html .= '<progress max="' . $lim . '" value="' . min($lim, $pages) . '"></progress><div class="imp-stats">'
                . '<div><b>' . $pages . '</b><span>страниц из не больше ' . $lim . '</span></div>'
                . '<div><b>' . (int)($c['files'] ?? 0) . '</b><span>файлов скачано</span></div>'
                . '<div><b>' . $e(self::mb((int)($c['bytes'] ?? 0))) . '</b><span>объём</span></div>'
                . '<div><b>' . $e(self::elapsed(time() - (int)($c['started'] ?? time()))) . '</b><span>прошло</span></div></div>';
            if (($c['url'] ?? '') !== '') {
                $html .= '<p class="muted imp-cur">Сейчас: ' . $e((string)$c['url']) . '</p>';
            }
        } else {
            $html .= '<p class="muted">Это быстрый осмотр: открываю главную и читаю карту сайта, ничего не сохраняется. Прошло ' . $e(self::elapsed(time() - (int)($c['started'] ?? time()))) . '.</p>';
        }
        if ((int)($c['blocked'] ?? 0) > 0) {
            $html .= '<p class="muted">Отклонено запросов страницы к внутренним адресам: ' . (int)$c['blocked'] . '.</p>';
        }
        foreach ((array)($c['warnings'] ?? []) as $w) {
            $html .= '<p class="muted">' . $e((string)$w) . '</p>';
        }
        return $html . '</div></section>';
    }

    /** What a capture brought, as stats; also for a failed one (what it managed to do). @param array<string,mixed> $job */
    private static function captureNumbers(array $job, bool $line = false): string
    {
        $e = 'ag_e';
        $c = (array)($job['result']['capture'] ?? []);
        if ($c === [] || ((int)($c['pages'] ?? 0) === 0 && (int)($c['files'] ?? 0) === 0)) {
            return '';
        }
        $took = (int)($c['finished'] ?? 0) > 0 ? ' за ' . self::elapsed((int)$c['finished'] - (int)($c['started'] ?? 0)) : '';
        $text = 'Захват в Chrome' . $took . ': страниц ' . (int)($c['pages'] ?? 0) . ', файлов ' . (int)($c['files'] ?? 0) . ', ' . self::mb((int)($c['bytes'] ?? 0)) . '.';
        if ((int)($c['blocked'] ?? 0) > 0) {
            $text .= ' Отклонено запросов к внутренним адресам: ' . (int)$c['blocked'] . '.';
        }
        return $line ? '<p class="muted">' . $e($text) . '</p>' : '<p class="muted">' . $e($text) . '</p>';
    }

    /** The owner has seen the site: what is there and a button to start. @param array<string,mixed> $job */
    private static function scouted(array $job): string
    {
        $e = 'ag_e';
        $id = (int)$job['id'];
        $s = (array)($job['result']['scout'] ?? []);
        $lim = CaptureJob::limits($job['options']);
        $planned = max(1, min($lim['pages'], (int)($s['pages_planned'] ?? $lim['pages']) ?: $lim['pages']));
        $found = (int)($s['pages_found'] ?? 0);
        $est = (int)($s['estimate_bytes'] ?? 0);
        $secs = (int)($s['estimate_seconds'] ?? 0);
        $html = '<section class="panel"><div class="panel-h"><h2>Сайт осмотрен</h2></div><div class="panel-b">';
        if (($s['title'] ?? '') !== '') {
            $html .= '<p>' . $e((string)$s['title']) . '</p>';
        }
        $html .= '<div class="imp-stats"><div><b>' . $e(Detector::name((string)($s['platform'] ?? 'generic'))) . '</b><span>на чём сделан</span></div>'
            . '<div><b>' . ($found > 0 ? $found : 'не меньше 1') . '</b><span>страниц нашлось</span></div>'
            . '<div><b>' . $planned . '</b><span>будет перенесено (предел ' . $lim['pages'] . ')</span></div>'
            . ($est > 0 ? '<div><b>около ' . $e(self::mb($est)) . '</b><span>объём копии</span></div>' : '')
            . ($secs > 0 ? '<div><b>около ' . $e(self::elapsed($secs)) . '</b><span>займёт захват</span></div>' : '') . '</div>';
        if ($found > $lim['pages']) {
            $html .= '<p class="muted">Страниц на сайте больше предела: перенесутся первые ' . $lim['pages'] . '. Предел можно поменять ниже или в настройках модуля.</p>';
        }
        $html .= '<p class="muted">Захват откроет страницы в Chrome на этом сервере: сайт-источник читается только запросами GET и с паузами. Предел объёма ' . $lim['mb'] . ' МБ, времени ' . $lim['minutes'] . ' мин: при превышении захват остановится. Ваш сайт до конца захвата не меняется.</p>';
        $html .= '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" class="panel-b">' . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="capture">'
            . '<label class="f"><span>Сколько страниц брать</span><input class="in" type="number" name="limit" min="1" max="2000" value="' . $planned . '"></label>'
            . '<label class="chk"><input type="checkbox" name="auto_apply" value="1" checked> Когда захват закончится, сразу перенести страницы на сайт (отчёт и кнопка «Отменить импорт» останутся)</label>'
            . '<p><button class="btn primary" type="submit">Начать перенос</button></p></form></div></section>';
        $html .= '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" class="inline" data-confirm="Удалить задание?" data-confirm-ok="Удалить">' . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="delete"><button class="btn sm ghost" type="submit">Удалить задание</button></form>';
        return $html;
    }

    /** A stopped or failed capture: start it again or delete it. @param array<string,mixed> $job */
    private static function retry(array $job): string
    {
        $id = (int)$job['id'];
        $html = '';
        if ($job['source_type'] === 'url') {
            $html .= '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" class="inline">' . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="capture"><input type="hidden" name="auto_apply" value="1"><button class="btn primary" type="submit">Повторить захват</button></form> ';
        }
        return $html . '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" class="inline" data-confirm="Удалить задание?" data-confirm-ok="Удалить">' . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="delete"><button class="btn sm ghost" type="submit">Удалить задание</button></form>';
    }

    /** @param array<string,mixed> $r */
    private static function preview(array $r, int $id): string
    {
        $e = 'ag_e';
        $html = '<form method="post" action="' . self::BASE . '/job?id=' . $id . '" data-confirm="Перенести выбранные страницы?" data-confirm-text="Страницы появятся на сайте сразу. Импорт можно отменить кнопкой «Отменить импорт»." data-confirm-ok="Импортировать">'
            . ag_admin_csrf_field() . '<input type="hidden" name="id" value="' . (int)($job['id'] ?? $id) . '"><input type="hidden" name="action" value="apply">';
        $html .= '<section class="panel"><div class="panel-h"><h2>Что будет перенесено</h2><span class="muted">снимите галочку, чтобы не переносить страницу</span></div><div class="tbl-scroll"><table class="tbl imp-tbl"><thead><tr><th></th><th>Было</th><th>Станет</th><th>Страница</th><th>' . (($r['mode'] ?? '') === 'exact' ? 'Копия' : 'Блоки') . '</th><th class="imp-loss">Потери</th></tr></thead><tbody>';
        $rows = $r['pages'];
        usort($rows, static fn(array $a, array $b): int => [$a['slug'] === 'index' ? 0 : 1, $a['slug']] <=> [$b['slug'] === 'index' ? 0 : 1, $b['slug']]);
        foreach ($rows as $row) {
            $depth = substr_count($row['slug'], '/');
            $indent = $depth > 0 ? str_repeat('&nbsp;&nbsp;&nbsp;', $depth) . '└ ' : '';
            // A page the plan skips (the home page while "replace" is off, a duplicate) cannot be turned on here: no tick for it.
            $skipped = $row['action'] === 'skip';
            $box = $row['new'] ? '<span class="muted" title="Раздел для записей создаётся сам">+</span>'
                : '<input type="checkbox" name="pages[]" value="' . (int)$row['id'] . '"' . ($row['selected'] && !$skipped ? ' checked' : '') . ($skipped ? ' disabled' : '') . ' aria-label="Переносить ' . $e($row['title']) . '">';
            $new = $row['action'] === 'skip' || !$row['selected'] ? '<span class="muted">не переносится</span>' : '<span class="mono">' . $indent . $e($row['slug'] === 'index' ? '/' : '/' . $row['slug']) . '</span>';
            $note = $row['reason'] !== '' ? '<br><small class="muted">' . $e($row['reason']) . '</small>' : '';
            $blocks = '';
            if ($row['blocks'] !== []) {
                $blocks = '<details><summary>' . count($row['blocks']) . ' ' . self::plural(count($row['blocks']), 'блок', 'блока', 'блоков') . '</summary><small>'
                    . implode('<br>', array_map(static fn(array $b): string => ag_e($b['type'] . ($b['summary'] !== '' ? ': ' . $b['summary'] : '')), $row['blocks'])) . '</small></details>';
            }
            if (is_array($row['copy'] ?? null)) {
                $c = $row['copy'];
                $sd = $c['similarity']['desktop'] ?? null;
                $sm = $c['similarity']['mobile'] ?? null;
                $blocks = self::mb((int)$c['size']) . ', ' . ($c['mode'] === 'dom' ? 'итоговая страница' : 'исходный код')
                    . (is_numeric($sd) ? '<br><small class="muted">сходство: компьютер ' . (int)round((float)$sd * 100) . '%' . (is_numeric($sm) ? ', телефон ' . (int)round((float)$sm * 100) . '%' : '') . '</small>' : '')
                    . ($c['phone'] ? '<br><small class="muted">отдельная версия для телефона</small>' : '');
            }
            $losses = $row['losses'] === [] ? '<span class="muted">нет</span>' : '<details><summary>' . count($row['losses']) . '</summary><small>'
                . implode('<br>', array_map(static fn(array $l): string => ag_e($l['what'] . ': ' . $l['why']), $row['losses'])) . '</small></details>';
            $html .= '<tr><td>' . $box . '</td><td class="mono imp-old">' . ($row['new'] ? '<span class="muted">новый раздел</span>' : $e(mb_substr($row['old_path'], 0, 70))) . '</td><td class="imp-old">' . $new . $note . '</td><td class="imp-title">' . $e(mb_substr($row['title'], 0, 80))
                . ($row['kind'] === 'post' ? '<br><small class="muted">запись' . ($row['published_at'] !== '' ? ' от ' . $e($row['published_at']) : '') . '</small>' : '') . '</td><td>' . $blocks . '</td><td class="imp-loss">' . $losses . '</td></tr>';
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
        $back = $job['status'] === 'rolled_back';
        if (isset($res['copy']['total'])) {
            $html .= '<p>' . ($back ? 'Файлов копии было записано: ' : 'Файлов копии: ') . (int)$res['copy']['total'] . ', ' . ($back ? 'объёмом ' : 'записано ') . self::mb((int)$res['copy']['bytes']) . (($res['copy']['skipped'] ?? 0) > 0 ? ', пропущено небезопасных или лишних: ' . (int)$res['copy']['skipped'] : '') . (!empty($res['copy']['removed']) ? ' (при отмене удалены)' : ($back ? ' (при отмене оставлены)' : '')) . '.</p>';
        }
        if (isset($res['redirects'])) {
            $html .= '<p>' . ($back ? 'Переходов со старых адресов было добавлено: ' : 'Переходов со старых адресов добавлено: ') . count($res['redirects']['added'] ?? []) . ($back ? ' (при отмене убраны)' : '') . (($res['redirects']['refused'] ?? []) !== [] ? ', не принято: ' . count($res['redirects']['refused']) : '') . '.</p>';
            if (($res['redirects']['note'] ?? '') !== '') {
                $html .= '<p class="muted">' . $e($res['redirects']['note']) . '</p>';
            }
            if (($res['redirects']['refused'] ?? []) !== []) {
                $html .= '<details><summary>Не принятые переходы</summary><small>' . implode('<br>', array_map(static fn(array $x): string => ag_e($x['from'] . ' -> ' . $x['to'] . ': ' . Compat::redirectWhy((string)$x['why'])), array_slice($res['redirects']['refused'], 0, 300))) . '</small></details>';
            }
        }
        foreach ((array)($res['menus'] ?? []) as $name => $m) {
            $html .= '<p>Меню ' . ($name === 'header' ? 'шапки' : 'подвала') . ': ' . (isset($m['error']) ? 'не заменено (' . $e($m['error']) . ')' : ($back ? 'было заменено (пунктов ' . count($m['after']) . '), при отмене возвращено прежнее' : 'заменено, пунктов ' . count($m['after']))) . '.</p>';
        }
        foreach (array_merge((array)($res['notes'] ?? []), (array)($res['rollback_notes'] ?? [])) as $n) {
            $html .= '<p class="muted">' . $e($n) . '</p>';
        }
        $html .= '</div></section>';

        $html .= '<section class="panel"><div class="panel-h"><h2>Страницы</h2></div><div class="tbl-scroll"><table class="tbl imp-tbl"><thead><tr><th>Было</th><th>Стало</th><th>Состояние</th><th class="imp-loss">Потери</th></tr></thead><tbody>';
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
            $edit = $row['status'] === 'done' && is_array($row['copy'] ?? null) ? ' <a class="muted" href="' . self::BASE . '/copy?slug=' . rawurlencode((string)$row['slug']) . '">править код</a>' : '';
            $link = $row['status'] === 'done' ? '<a class="mono" href="/' . $e($row['slug'] === 'index' ? '' : $row['slug']) . '" target="_blank" rel="noopener">/' . $e($row['slug'] === 'index' ? '' : $row['slug']) . '</a>' . $edit : '<span class="mono">/' . $e($row['slug'] === 'index' ? '' : $row['slug']) . '</span>';
            $losses = $row['losses'] === [] ? '<span class="muted">нет</span>' : '<details><summary>' . count($row['losses']) . '</summary><small>' . implode('<br>', array_map(static fn(array $l): string => ag_e($l['what'] . ': ' . $l['why']), $row['losses'])) . '</small></details>';
            $html .= '<tr><td class="mono imp-old">' . ($row['new'] ? '<span class="muted">новый раздел</span>' : $e(mb_substr($row['old_path'], 0, 70))) . '</td><td class="imp-old">' . $link . '</td><td>' . $state . '</td><td class="imp-loss">' . $losses . '</td></tr>';
        }
        $html .= '</tbody></table></div></section>';
        if (in_array($job['status'], ['done', 'failed'], true) && $c['done'] > 0) {
            $html .= '<form method="post" action="' . self::BASE . '/job?id=' . (int)$job['id'] . '" data-confirm="Отменить импорт?" data-confirm-text="Созданные импортом страницы уйдут в корзину (кроме изменённых после импорта), переходы со старых адресов удалятся, меню вернётся прежнее. Удаляются только страницы этого импорта, остальной сайт не затрагивается." data-confirm-ok="Отменить импорт" data-danger>'
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
            'analyzing', 'applying', 'rolling_back', 'scouting', 'capturing', 'scouted', 'cancelled' => 'warn',
            default => 'plain',
        };
        return '<span class="tag ' . $kind . '">' . ag_e(Runner::statusText($status)) . '</span>';
    }

    private static function mb(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '') . ' МБ' : max(1, (int)round($bytes / 1024)) . ' КБ';
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
