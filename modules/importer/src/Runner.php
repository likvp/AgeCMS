<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\Actor;
use AgeCMS\AgeCMSException;
use AgeCMS\CircuitBreaker;
use AgeCMS\Content;
use AgeCMS\Database;
use AgeCMS\Logger;
use AgeCMS\CopyPage;
use AgeCMS\Pages;
use AgeCMS\Theme;
use AgeCMS\ValidationException;
use AgeCMS\Modules\Importer\Copy\CaptureFlow;
use AgeCMS\Modules\Importer\Copy\CopyBuilder;
use AgeCMS\Modules\Importer\Copy\CopyFiles;
use AgeCMS\Modules\Importer\Html\Sanitizer;
use AgeCMS\Modules\Importer\Net\Fetcher;

/**
 * The import job step by step, so that it fits shared hosting (a request lives 30 seconds): analysis (pages are read
 * from the source), then the plan (preview), then application in portions of a few pages, then redirects and menus.
 * Every step stops at the deadline and the next one (the admin page asking for the next step, an agent asking
 * import_status, or the background tick) goes on. Writes go through Content as the person who started the job, so they
 * are in the history with that name; rollback puts the pages created by the job into the trash (the explicit undo of the
 * job session, outside the circuit breaker share), removes its redirects and puts the menus back.
 */
final class Runner
{
    /** @var array<string,mixed> */
    private array $job;

    public function __construct(private ?Fetcher $fetcher = null)
    {
    }

    /** Folder of a job: unpacked archives, uploaded files. */
    public static function dir(int $jobId): string
    {
        return AGECMS_DATA . '/importer/job-' . $jobId;
    }

    /** Folder the owner can put export files into (by FTP) to pick them in the admin. */
    public static function inbox(): string
    {
        return AGECMS_DATA . '/import';
    }

    /**
     * Creates a job for an address or a file. Nothing is read yet; step() does that.
     *
     * @param array<string,mixed> $options
     * @param string|null $name what to call a file in the admin and the history (the name it was uploaded with); the
     *                          job keeps its own copy, so the path says nothing to a person
     * @param bool $uploaded the file was brought by the caller itself (uploaded in the admin, picked from data/import, decoded from
     *                       an agent's base64): only then a path is accepted from anyone but the command line, so a token
     *                       cannot make the server read a file of its own choice
     */
    public static function create(string $input, array $options, Actor $actor, ?string $name = null, bool $uploaded = false): int
    {
        if (!$uploaded && $actor->type !== 'cli' && trim($input) !== '' && (is_file(trim($input)) || is_dir(trim($input)))) {
            throw new ImportException('Файл по пути на сервере принимает только консоль. Загрузите файл в админке или положите его в папку data/import.');
        }
        $src = Importer::source($input);
        $options = Importer::options($options);
        if ($options['mode'] === 'exact' && $src['type'] === 'url') {
            // The server captures the site in Chrome in the background; the first step is a quick look.
            return CaptureFlow::create($src['source'], $options, $actor);
        }
        $note = '';
        if (in_array($src['type'], ['capture_zip', 'capture_dir'], true)) {
            // A capture package holds finished pages, not content to put into blocks.
            $options['mode'] = 'exact';
        }
        if ($options['mode'] === 'exact' && !in_array($src['type'], ['capture_zip', 'capture_dir'], true)) {
            if ($src['type'] === 'wxr') {
                // An export of WordPress holds only texts: there is nothing to copy exactly.
                $options['mode'] = 'blocks';
                $note = 'Выгрузка WordPress (XML) содержит только тексты и картинки без оформления, поэтому импорт идёт в режиме «В блоки темы».';
            } else {
                throw new ImportException(Importer::captureHelp($src['type']));
            }
        }
        $id = Jobs::create($src['source'], $src['type'], $options, $actor);
        if ($src['type'] !== 'url') {
            Jobs::update($id, ['result' => ['source_name' => mb_substr(basename(str_replace('\\', '/', $name ?? $src['source'])), 0, 120)] + ($note !== '' ? ['mode_note' => $note] : [])]);
        }
        if ($src['type'] !== 'url' && $src['type'] !== 'capture_dir') {
            // The job keeps its own copy: the file in the inbox or the temporary upload may go away.
            $dir = self::dir($id);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new ImportException('Не получилось создать папку задания: ' . $dir);
            }
            $copy = $dir . '/source.' . (in_array($src['type'], ['tilda_zip', 'capture_zip'], true) ? 'zip' : ($src['type'] === 'wxr' ? 'xml' : 'html'));
            if (!@copy($src['source'], $copy)) {
                throw new ImportException('Не получилось сохранить файл задания.');
            }
            Jobs::update($id, ['source' => $copy]);
        }
        return $id;
    }

    /** The actor of the job: the person who started it, with the job's own circuit breaker session. */
    public static function actor(array $job): Actor
    {
        $a = $job['actor'];
        return new Actor((string)($a['type'] ?? 'system'), (string)($a['name'] ?? 'importer'), (string)($a['role'] ?? 'admin'), self::session((int)$job['id']));
    }

    /**
     * Works on the job until the deadline. Returns the job after the step.
     *
     * @return array<string,mixed>
     */
    public function step(int $jobId, float $deadline): array
    {
        $job = Jobs::get($jobId) ?? throw new ImportException('Задание импорта не найдено.');
        if (!in_array($job['status'], Jobs::ACTIVE, true)) {
            return $job;
        }
        if (!Jobs::lock($jobId, max(30, (int)ceil($deadline - microtime(true)) + 30))) {
            return $job;
        }
        $this->job = $job;
        try {
            match ($job['status']) {
                'analyzing' => $this->analyze($deadline),
                'scouting', 'capturing' => CaptureFlow::poll($job),
                'applying' => $this->apply($deadline),
                'rolling_back' => $this->rollback($deadline),
                default => null,
            };
        } catch (ImportException $e) {
            Jobs::update($jobId, ['status' => 'failed', 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Logger::log('error', 'importer.step_failed', ['job' => $jobId, 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            Jobs::update($jobId, ['status' => 'failed', 'error' => 'Сбой: ' . mb_substr($e->getMessage(), 0, 500)]);
        } finally {
            Jobs::unlock($jobId);
        }
        return Jobs::get($jobId) ?? $job;
    }

    /** Runs steps until the job is ready, done or failed (command line, tests). @return array<string,mixed> */
    public function runToEnd(int $jobId, ?callable $progress = null): array
    {
        for ($i = 0; $i < 100000; $i++) {
            $job = $this->step($jobId, microtime(true) + 20);
            if ($progress !== null) {
                $progress($job);
            }
            if (!in_array($job['status'], Jobs::ACTIVE, true)) {
                return $job;
            }
        }
        return Jobs::get($jobId) ?? [];
    }

    // ---- analysis ------------------------------------------------------------------------------------------------

    private function analyze(float $deadline): void
    {
        $job = $this->job;
        $importer = new Importer($job['options'], $this->fetcher);
        $state = $job['state'];
        $src = ['type' => $job['source_type'], 'source' => $job['source']];
        if (!isset($state['detect']) && $src['type'] === 'url') {
            $state['detect'] = $importer->detect($src['source']);
            Jobs::update($job['id'], ['platform' => (string)$state['detect']['platform'], 'detect' => $state['detect'], 'state' => $state]);
            $job['platform'] = (string)$state['detect']['platform'];
        }
        $extractor = $importer->extractor($src, (string)$job['platform'], self::dir($job['id']));
        $done = $extractor->step($state, $deadline, static function (array $page) use ($job): void {
            Jobs::addItem($job['id'], $page);
        });
        $site = Neutral::site((array)($state['site'] ?? []));
        $site['local_base'] = (string)($state['local_base'] ?? '');
        Jobs::update($job['id'], ['state' => $state, 'site' => $site, 'detect' => (array)($state['detect'] ?? []), 'platform' => (string)($state['detect']['platform'] ?? $job['platform'])]);
        if (!$done) {
            return;
        }
        if (Jobs::countItems($job['id']) === 0) {
            throw new ImportException('Не найдено ни одной страницы: проверьте адрес или файл. ' . implode(' ', array_slice((array)($state['warnings'] ?? []), 0, 3)));
        }
        $this->job = Jobs::get($job['id']) ?? $job;
        self::replan($this->job['id']);
        Jobs::update($job['id'], ['status' => 'ready']);
        if (!empty($job['options']['auto_apply'])) {
            // One-button import: nobody waits to look at the plan, the report and the rollback are there afterwards.
            self::startApply((int)$job['id'], null, self::actor($this->job));
        }
    }

    /**
     * Builds the plan from the found pages and the current selection: new addresses, blocks, redirects, menus.
     * Called after the analysis and again before applying (the selection or the site may have changed).
     */
    public static function replan(int $jobId): void
    {
        $job = Jobs::get($jobId) ?? throw new ImportException('Задание импорта не найдено.');
        Jobs::deleteItems($jobId, '#section:%');
        $pages = [];
        $unselected = [];
        foreach (Jobs::items($jobId) as $item) {
            $page = $item['page'];
            $page['id'] = $item['id'];
            if ($item['selected'] === 1) {
                $pages[] = $page;
            } else {
                $unselected[] = $item['id'];
            }
        }
        $importer = new Importer($job['options']);
        $plan = $importer->plan($pages, $job['site']);
        foreach ($plan['sections'] as $section) {
            Jobs::addItem($jobId, $section);
        }
        $ids = [];
        foreach (Jobs::items($jobId) as $item) {
            $ids[$item['path_key']] = $item['id'];
        }
        // Order of creation: parents first.
        $order = [];
        foreach ($plan['items'] as $key => $entry) {
            $itemId = is_string($key) && str_starts_with($key, '#section:') ? ($ids[$key] ?? null) : $key;
            if ($itemId !== null) {
                $order[] = [$entry['slug'] === 'index' ? 0 : 1, substr_count($entry['slug'], '/'), $itemId, $entry];
            }
        }
        usort($order, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        foreach ($order as $seq => [, , $itemId, $entry]) {
            Jobs::updateItem((int)$itemId, [
                'seq' => $seq + 1,
                'action' => $entry['action'],
                'new_slug' => $entry['slug'],
                'plan' => ['parent_slug' => $entry['parent_slug'], 'reason' => $entry['reason'], 'redirects' => $entry['redirects'], 'children_of' => $entry['children_of'], 'section' => $entry['section']],
                'doc' => $entry['doc'],
                'losses' => $entry['losses'],
                'status' => $entry['action'] === 'skip' ? 'skipped' : 'pending',
                'error' => '',
            ]);
        }
        foreach ($unselected as $itemId) {
            Jobs::updateItem($itemId, ['action' => 'skip', 'status' => 'skipped', 'plan' => ['reason' => 'не выбрана для переноса', 'redirects' => []], 'doc' => [], 'losses' => []]);
        }
        Jobs::update($jobId, ['plan' => ['menus' => $plan['menus'], 'redirects' => $plan['redirects'], 'warnings' => $plan['warnings'], 'roles' => $plan['roles'], 'links' => $plan['links'] ?? []]]);
    }

    // ---- apply ---------------------------------------------------------------------------------------------------

    /**
     * Marks the chosen pages and starts the application. $selection: item ids or old paths; null keeps the current choice.
     *
     * @param list<int|string>|null $selection
     */
    public static function startApply(int $jobId, ?array $selection, Actor $actor): array
    {
        $job = Jobs::get($jobId) ?? throw new ImportException('Задание импорта не найдено.');
        if ($job['status'] === 'scouted') {
            // A site that was only looked at: the transfer starts with the capture, and goes on by itself when it ends.
            CaptureFlow::start($jobId, $actor, ['auto_apply' => true]);
            return Jobs::get($jobId) ?? $job;
        }
        if ($job['status'] !== 'ready') {
            throw new ImportException('Задание сейчас в состоянии «' . self::statusText($job['status']) . '», импортировать можно только после анализа.');
        }
        if ($selection !== null) {
            $wanted = [];
            foreach ($selection as $s) {
                $wanted[is_int($s) || ctype_digit((string)$s) ? 'id:' . (int)$s : 'path:' . Url::key((string)$s)] = true;
            }
            foreach (Jobs::items($jobId) as $item) {
                if (str_starts_with($item['path_key'], '#section:')) {
                    continue;
                }
                $on = isset($wanted['id:' . $item['id']]) || isset($wanted['path:' . $item['path_key']]);
                Jobs::updateItem($item['id'], ['selected' => $on ? 1 : 0]);
            }
        }
        self::replan($jobId);
        $creates = Jobs::countItems($jobId, ['pending']);
        if ($creates === 0) {
            throw new ImportException('Нечего переносить: все страницы пропущены или не выбраны.');
        }
        $runActor = self::actor(['id' => $jobId, 'actor' => ['type' => $actor->type, 'name' => $actor->name, 'role' => $actor->role]]);
        $note = '';
        // The job session belongs to whoever started the import: later the same agent token may undo it (allowRollback).
        if (method_exists(CircuitBreaker::class, 'claimSession')) {
            try {
                CircuitBreaker::claimSession($actor, self::session($jobId));
            } catch (AgeCMSException) {
                // Not fatal: without the claim only an administrator can undo a large import.
            }
        }
        try {
            if (!Compat::allowBulkCreate($runActor, min(5000, $creates + 5), 'Импорт сайта (задание ' . $jobId . '): ' . mb_substr(self::sourceLabel($job), 0, 120))) {
                $note = 'Ядро без разрешения массового создания: действует обычный лимит предохранителя.';
            }
        } catch (AgeCMSException $e) {
            $note = 'Массовое создание не разрешено (' . $e->getMessage() . '): действует обычный лимит предохранителя.';
        }
        $result = $job['result'];
        $result['started_at'] = ag_now();
        $result['notes'] = $note === '' ? [] : [$note];
        Jobs::update($jobId, ['status' => 'applying', 'actor' => ['type' => $actor->type, 'name' => $actor->name, 'role' => $actor->role], 'result' => $result, 'error' => '']);
        return Jobs::get($jobId) ?? $job;
    }

    private function apply(float $deadline): void
    {
        $job = $this->job;
        $actor = self::actor($job);
        $chunk = max(1, (int)($job['options']['chunk'] ?? 5));
        $done = 0;
        if (($job['options']['mode'] ?? '') === 'exact' && empty($job['result']['copy']['installed']) && !$this->installCopy($deadline)) {
            return;
        }
        foreach (Jobs::items($job['id'], ['pending']) as $item) {
            if (microtime(true) >= $deadline || $done >= $chunk) {
                return;
            }
            try {
                $this->applyItem($item, $actor, $deadline);
                $done++;
            } catch (Pause) {
                return;
            }
        }
        if (Jobs::countItems($job['id'], ['pending']) > 0) {
            return;
        }
        $this->finish($actor);
    }

    /** The folder name of the files of the copies made by a job. */
    public static function copyKey(int $jobId): string
    {
        return 'j' . $jobId;
    }

    /** Root folder of the capture package of a job (a folder given by the owner or the unpacked archive). @param array<string,mixed> $job */
    private static function packageRoot(array $job): string
    {
        $root = (string)($job['state']['root'] ?? $job['state']['local_base'] ?? $job['site']['local_base'] ?? '');
        if ($root === '' || !is_dir($root)) {
            throw new ImportException('Папка пакета захвата не найдена: ' . $root);
        }
        return $root;
    }

    /** One portion of the files of the package into the folder of the copy. @return bool true when everything is in place */
    private function installCopy(float $deadline): bool
    {
        $job = $this->job;
        $result = $job['result'];
        $root = self::packageRoot($job);
        $key = self::copyKey((int)$job['id']);
        $list = self::dir((int)$job['id']) . '/copy-files.lst';
        if (!isset($result['copy'])) {
            if (!is_dir(dirname($list)) && !@mkdir(dirname($list), 0700, true) && !is_dir(dirname($list))) {
                throw new ImportException('Не получилось создать папку задания.');
            }
            $names = CopyFiles::list($root);
            file_put_contents($list, implode("\n", $names));
            $result['copy'] = ['key' => $key, 'total' => count($names), 'done' => 0, 'bytes' => 0, 'skipped' => 0, 'installed' => false];
            Jobs::update($job['id'], ['result' => $result]);
        }
        $names = is_file($list) ? array_values(array_filter(explode("\n", (string)file_get_contents($list)), static fn(string $n): bool => $n !== '')) : [];
        $progress = ['done' => (int)$result['copy']['done'], 'bytes' => (int)$result['copy']['bytes'], 'skipped' => (int)$result['copy']['skipped'], 'notes' => []];
        $move = str_starts_with(realpath($root) ?: $root, (string)realpath(self::dir((int)$job['id'])));
        $ready = CopyFiles::install($root, $key, $names, $progress, $deadline, $move);
        $result['copy'] = ['done' => $progress['done'], 'bytes' => $progress['bytes'], 'skipped' => $progress['skipped'], 'installed' => $ready] + $result['copy'];
        Jobs::update($job['id'], ['result' => $result]);
        $this->job['result'] = $result;
        return $ready;
    }

    /** The document of an exact copy: the block "site_copy" built from the page file of the package. @param array<string,mixed> $item @param array<string,mixed> $doc @return array<string,mixed> */
    private function copyDoc(array $item, array $doc): array
    {
        $job = $this->job;
        $copy = (array)($item['page']['copy'] ?? []);
        $root = self::packageRoot($job);
        $key = self::copyKey((int)$job['id']);
        $file = $root . '/' . (string)($copy['file'] ?? '');
        $mobile = (string)($copy['mobile_file'] ?? '') !== '' ? $root . '/' . $copy['mobile_file'] : '';
        if (!is_file($file)) {
            throw new ImportException('Файла страницы нет в пакете: ' . ($copy['file'] ?? '?'));
        }
        $links = (array)($job['plan']['links'] ?? []);
        $source = (string)$item['old_path'] . ' ← ' . (string)($item['page']['source_url'] ?? '');
        $data = CopyBuilder::data($file, $key, $links, $source, (string)($copy['mode'] ?? 'source'), $mobile);
        $doc['blocks'] = [['id' => CopyPage::BLOCK_ID, 'type' => Theme::COPY_BLOCK, 'data' => $data]];
        if (str_starts_with((string)($doc['og_image'] ?? ''), CopyBuilder::SENTINEL)) {
            $doc['og_image'] = CopyPage::url($key) . substr((string)$doc['og_image'], strlen(CopyBuilder::SENTINEL));
        }
        return $doc;
    }

    /** @param array<string,mixed> $item */
    private function applyItem(array $item, Actor $actor, float $deadline): void
    {
        $losses = $item['losses'];
        if (($this->job['options']['mode'] ?? '') === 'exact') {
            try {
                $doc = $this->copyDoc($item, $item['doc']);
            } catch (ImportException $e) {
                Jobs::updateItem($item['id'], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                return;
            }
        } else {
            $doc = $this->images($item['doc'], $losses, $deadline);
            $doc = $this->finalRichText($doc);
        }
        $slug = (string)$item['new_slug'];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                if ($item['action'] === 'update_home') {
                    $before = Pages::doc('index');
                    $fields = $doc;
                    unset($fields['parent_slug']);
                    $r = Content::updatePage($actor, 'index', $fields);
                    Jobs::updateItem($item['id'], ['status' => 'done', 'revision_id' => $r['revision_id'], 'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 'losses' => $losses, 'error' => '']);
                } else {
                    if (($doc['parent_slug'] ?? '') !== '' && Pages::row((string)$doc['parent_slug']) === null) {
                        $doc['parent_slug'] = '';
                    }
                    $r = Content::createPage($actor, $slug, $doc);
                    Jobs::updateItem($item['id'], ['status' => 'done', 'revision_id' => $r['revision_id'], 'losses' => $losses, 'error' => '']);
                }
                return;
            } catch (ValidationException $e) {
                // Drop the blocks the core refused and try again; the page itself is worth more than one bad block.
                $bad = [];
                foreach ($e->errors as $err) {
                    if (preg_match('~^blocks\[(\d+)\]~', $err, $m) === 1) {
                        $bad[(int)$m[1]] = $err;
                    }
                }
                if ($bad === [] || $attempt === 2) {
                    Jobs::updateItem($item['id'], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'losses' => $losses]);
                    return;
                }
                foreach ($bad as $i => $err) {
                    $losses[] = ['what' => 'Блок ' . ($doc['blocks'][$i]['type'] ?? '?'), 'why' => 'не принят ядром: ' . mb_substr($err, 0, 200), 'source' => ''];
                    unset($doc['blocks'][$i]);
                }
                $doc['blocks'] = array_values($doc['blocks']);
            } catch (AgeCMSException $e) {
                Jobs::updateItem($item['id'], ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'losses' => $losses]);
                if ($e instanceof \AgeCMS\CircuitBreakerException || $e instanceof \AgeCMS\FrozenException) {
                    throw new ImportException('Запись остановлена предохранителем: ' . $e->getMessage());
                }
                return;
            }
        }
    }

    /**
     * Pictures into the media library: block fields marked "media", pictures inside rich text, og_image. A picture that
     * could not be taken stays at its old https address (the page still shows it) or is removed, with a note.
     *
     * @param array<string,mixed> $doc
     * @param list<array<string,string>> $losses
     * @return array<string,mixed>
     */
    private function images(array $doc, array &$losses, float $deadline): array
    {
        $blocks = Importer::themeBlocks();
        $get = function (string $url) use (&$losses, $deadline): ?string {
            return $this->image($url, $losses, $deadline);
        };
        if (($doc['og_image'] ?? '') !== '') {
            $doc['og_image'] = $get((string)$doc['og_image']) ?? '';
        }
        foreach ($doc['blocks'] ?? [] as $i => $block) {
            $fields = (array)($blocks[$block['type']]['fields'] ?? []);
            foreach ($block['data'] as $name => $value) {
                $spec = $fields[$name] ?? [];
                if (($spec['type'] ?? '') === 'url' && !empty($spec['media']) && is_string($value) && $value !== '') {
                    $new = $get($value);
                    if ($new === null) {
                        if (!empty($spec['required'])) {
                            unset($doc['blocks'][$i]);
                            continue 2;
                        }
                        unset($doc['blocks'][$i]['data'][$name]);
                    } else {
                        $doc['blocks'][$i]['data'][$name] = $new;
                    }
                } elseif (in_array($spec['type'] ?? '', ['richtext'], true) && is_string($value) && str_contains($value, '<img')) {
                    $doc['blocks'][$i]['data'][$name] = preg_replace_callback('~<img\s[^>]*src="([^"]+)"[^>]*>~i', static function (array $m) use ($get): string {
                        $new = $get(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
                        return $new === null ? '' : str_replace($m[1], htmlspecialchars($new, ENT_QUOTES | ENT_HTML5), $m[0]);
                    }, $value) ?? $value;
                }
                if (($spec['type'] ?? '') === 'richtext' && is_string($doc['blocks'][$i]['data'][$name] ?? null) && str_contains((string)$doc['blocks'][$i]['data'][$name], 'href=')) {
                    // A link to the picture file (a thumbnail that opens the full picture): to the copy in the media library.
                    $doc['blocks'][$i]['data'][$name] = preg_replace_callback('~href="([^"]+)"~i', static function (array $m): string {
                        $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
                        $local = Url::isImage($url) ? Jobs::mediaPath($url) : null;
                        return $local === null ? $m[0] : 'href="' . htmlspecialchars($local, ENT_QUOTES | ENT_HTML5) . '"';
                    }, (string)$doc['blocks'][$i]['data'][$name]) ?? $doc['blocks'][$i]['data'][$name];
                }
            }
        }
        $doc['blocks'] = array_values($doc['blocks'] ?? []);
        return $doc;
    }

    /** One picture: from the cache, from the job folder (local:...) or from the network. Null when it cannot be kept. */
    private function image(string $url, array &$losses, float $deadline): ?string
    {
        if (str_starts_with($url, '/assets/uploads/')) {
            return $url;
        }
        $cached = Jobs::mediaPath($url);
        if ($cached !== null && is_file(\AgeCMS\Media::dir() . '/' . basename($cached))) {
            return $cached;
        }
        if (microtime(true) >= $deadline) {
            throw new Pause();
        }
        $fallback = str_starts_with($url, 'https://') ? $url : null;
        if (!\AgeCMS\Media::available()) {
            $losses[] = ['what' => 'Картинка ' . $url, 'why' => 'на сервере нет GD с WebP: картинка не загружена' . ($fallback !== null ? ', осталась ссылкой на старый сайт' : ''), 'source' => ''];
            return $fallback;
        }
        $tmp = null;
        try {
            if (str_starts_with($url, 'local:')) {
                $base = (string)($this->job['state']['local_base'] ?? $this->job['site']['local_base'] ?? '');
                $file = realpath($base . '/' . substr($url, 6));
                if ($base === '' || $file === false || !str_starts_with($file, (string)realpath($base))) {
                    throw new ImportException('файла нет в архиве');
                }
                $path = Compat::importFile($file);
            } else {
                if (preg_match('~^https?://~i', $url) !== 1) {
                    throw new ImportException('адрес не подходит');
                }
                $fetcher = $this->fetcher ?? Net\Network::fetcher((int)max(100, ($this->job['options']['delay_ms'] ?? 500) / 2));
                $r = $fetcher->get($url, 10485760);
                if ($r === null || !$r->ok()) {
                    throw new ImportException($r === null ? 'адрес недоступен' : 'ответ ' . $r->status);
                }
                if (self::isAvif($r->body) && !(method_exists(\AgeCMS\Media::class, 'avifReadable') && \AgeCMS\Media::avifReadable())) {
                    throw new ImportException('формат AVIF: GD на сервере не читает AVIF (нужен PHP 8.1+ с libavif)');
                }
                $tmp = tempnam(sys_get_temp_dir(), 'agimp');
                file_put_contents($tmp, $r->body);
                $path = Compat::importFile($tmp);
            }
            Jobs::rememberMedia($url, $path);
            return $path;
        } catch (Pause $p) {
            throw $p;
        } catch (\Throwable $e) {
            $losses[] = ['what' => 'Картинка ' . mb_substr($url, 0, 200), 'why' => 'не загружена (' . mb_substr($e->getMessage(), 0, 120) . ')' . ($fallback !== null ? ', осталась ссылкой на старый сайт' : ', убрана'), 'source' => ''];
            return $fallback;
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /** An AVIF file by its signature (ISO box "ftyp" with brand avif or avis). */
    public static function isAvif(string $bytes): bool
    {
        return strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['avif', 'avis'], true);
    }

    /** Rich text fields through the core sanitizer, now that the pictures have their final addresses. @param array<string,mixed> $doc */
    private function finalRichText(array $doc): array
    {
        $blocks = Importer::themeBlocks();
        foreach ($doc['blocks'] ?? [] as $i => $block) {
            foreach ((array)($blocks[$block['type']]['fields'] ?? []) as $name => $spec) {
                if (($spec['type'] ?? '') === 'richtext' && is_string($block['data'][$name] ?? null)) {
                    $doc['blocks'][$i]['data'][$name] = Compat::richText($block['data'][$name]);
                }
            }
        }
        return $doc;
    }

    /** Redirects and menus once all pages are there. */
    private function finish(Actor $actor): void
    {
        $job = $this->job;
        $result = $job['result'];
        $result['redirects'] = ['added' => [], 'refused' => [], 'note' => ''];
        $wanted = [];
        foreach (Jobs::items($job['id'], ['done']) as $item) {
            foreach ((array)($item['plan']['redirects'] ?? []) as $r) {
                $wanted[] = $r;
            }
        }
        if ($wanted !== [] && !Compat::redirects()) {
            $result['redirects']['note'] = 'Ядро без редиректов со старых адресов: ' . count($wanted) . ' редиректов не созданы, их список в отчёте.';
            $result['redirects']['refused'] = array_map(static fn(array $r): array => $r + ['why' => 'ядро не умеет'], $wanted);
        } elseif ($wanted !== []) {
            $good = [];
            $keys = [];
            foreach ($wanted as $r) {
                $check = Compat::checkRedirect($r['from'], $r['to']);
                if (is_string($check)) {
                    $result['redirects']['refused'][] = $r + ['why' => Compat::redirectWhy($check)];
                    continue;
                }
                $key = is_array($check) ? $check[0] : $r['from'];
                if (isset($keys[$key])) {
                    continue;
                }
                $keys[$key] = true;
                $good[] = $r;
            }
            foreach (array_chunk($good, 1000) as $batch) {
                try {
                    Content::addRedirects($actor, $batch);
                    foreach ($batch as $r) {
                        $result['redirects']['added'][] = $r['from'];
                    }
                } catch (AgeCMSException $e) {
                    foreach ($batch as $r) {
                        $result['redirects']['refused'][] = $r + ['why' => Compat::redirectWhy(mb_substr($e->getMessage(), 0, 200))];
                    }
                }
            }
        }
        $result['menus'] = [];
        foreach ((array)($job['plan']['menus'] ?? []) as $name => $items) {
            if ($items === [] || !($job['options']['menus'] ?? true)) {
                continue;
            }
            $before = Database::value('SELECT items_json FROM menus WHERE name = ?', [$name]);
            try {
                Content::setMenu($actor, (string)$name, $items);
                $result['menus'][$name] = ['before' => $before === null ? null : json_decode((string)$before, true), 'after' => $items];
            } catch (AgeCMSException $e) {
                $result['menus'][$name] = ['error' => $e->getMessage()];
            }
        }
        $result['finished_at'] = ag_now();
        Jobs::update($job['id'], ['status' => 'done', 'result' => $result]);
    }

    // ---- rollback ------------------------------------------------------------------------------------------------

    /** The source as a person knows it: the site address or the name of the file. @param array<string,mixed> $job */
    public static function sourceLabel(array $job): string
    {
        if ($job['source_type'] === 'url') {
            return (string)$job['source'];
        }
        return (string)($job['result']['source_name'] ?? basename((string)$job['source']));
    }

    /** Circuit breaker session of a job: every page of the import is created under it. */
    public static function session(int $jobId): string
    {
        return 'imp-' . $jobId;
    }

    /**
     * Starts the rollback. On a core with the explicit undo of a session (CircuitBreaker::allowRollback) the pages created by
     * the job go to the trash outside the 15% limit of the circuit breaker, however old the import is: allowed to an
     * administrator, and to an agent only when the import was started by the same agent token. Otherwise (an older core,
     * or an agent undoing somebody else's import) the deletions count as usual, so an agent may undo only a tiny import.
     */
    public static function startRollback(int $jobId, Actor $actor): array
    {
        $job = Jobs::get($jobId) ?? throw new ImportException('Задание импорта не найдено.');
        if (!in_array($job['status'], ['done', 'failed', 'applying'], true)) {
            throw new ImportException('Отменить можно только применённый импорт (сейчас «' . self::statusText($job['status']) . '»).');
        }
        $count = Jobs::countItems($jobId, ['done']);
        $mode = 'counted';
        $refused = '';
        if (method_exists(CircuitBreaker::class, 'allowRollback') && method_exists(Content::class, 'rollbackSession')) {
            try {
                CircuitBreaker::allowRollback($actor, self::session($jobId), 'Отмена импорта сайта (задание ' . $jobId . '): ' . mb_substr(self::sourceLabel($job), 0, 120));
                $mode = 'session';
            } catch (AgeCMSException $e) {
                $refused = $e->getMessage();
            }
        }
        if ($mode === 'counted' && $actor->type === 'agent' && $count >= 3) {
            throw new ImportException('Отмену импорта из ' . $count . ' страниц делает администратор (в админке «Импорт сайта», задание ' . $jobId . ', кнопка «Отменить импорт») или тот же токен агента, который запускал перенос. '
                . ($refused !== '' ? 'Этот импорт запущен не этим токеном.' : 'Ядро сайта не умеет отменять сессию целиком, массовое удаление агенту закрыто предохранителем.'));
        }
        if ($mode === 'counted') {
            try {
                Compat::allowBulkCreate(self::actor($job), 1, 'Отмена импорта сайта (задание ' . $jobId . ')');
            } catch (AgeCMSException) {
                // Without it the usual limits apply; small imports still go through.
            }
        }
        $result = $job['result'];
        $result['rollback_mode'] = $mode;
        $result['rollback_by'] = $actor->name;
        Jobs::update($jobId, ['status' => 'rolling_back', 'error' => '', 'result' => $result]);
        return Jobs::get($jobId) ?? $job;
    }

    private function rollback(float $deadline): void
    {
        $job = $this->job;
        $actor = self::actor($job);
        foreach (Jobs::items($job['id'], ['done', 'failed'], 'seq_desc') as $item) {
            if (microtime(true) >= $deadline) {
                return;
            }
            if ($item['status'] === 'failed') {
                Jobs::updateItem($item['id'], ['status' => 'skipped']);
                continue;
            }
            $slug = $item['action'] === 'update_home' ? 'index' : (string)$item['new_slug'];
            $last = Database::value('SELECT MAX(id) FROM revisions WHERE page_slug = ?', [$slug]);
            if (Pages::row($slug) === null) {
                Jobs::updateItem($item['id'], ['status' => 'rolled_back']);
                continue;
            }
            if ($item['revision_id'] !== null && (int)$last !== (int)$item['revision_id']) {
                Jobs::updateItem($item['id'], ['status' => 'kept', 'error' => 'страница изменена после импорта, оставлена как есть']);
                continue;
            }
            try {
                if ($item['action'] === 'update_home') {
                    $before = json_decode((string)$item['before_json'], true);
                    if (is_array($before)) {
                        unset($before['parent_slug']);
                        Content::updatePage($actor, 'index', $before);
                    }
                } elseif (($job['result']['rollback_mode'] ?? '') === 'session') {
                    $r = Content::rollbackSession($actor, self::session((int)$job['id']), [$slug]);
                    if (isset($r['kept'][$slug])) {
                        Jobs::updateItem($item['id'], ['status' => 'kept', 'error' => (string)$r['kept'][$slug]]);
                        continue;
                    }
                } else {
                    Content::deletePage($actor, $slug);
                }
                Jobs::updateItem($item['id'], ['status' => 'rolled_back']);
            } catch (AgeCMSException $e) {
                if ($e instanceof \AgeCMS\ForbiddenException) {
                    // The permission to undo the session ran out: the page stays "done", pressing the button again goes on.
                    throw new ImportException('Отмена остановлена: ' . $e->getMessage() . '. Нажмите «Отменить импорт» ещё раз.');
                }
                Jobs::updateItem($item['id'], ['status' => 'kept', 'error' => mb_substr($e->getMessage(), 0, 300)]);
                if ($e instanceof \AgeCMS\CircuitBreakerException || $e instanceof \AgeCMS\FrozenException) {
                    throw new ImportException('Отмена остановлена предохранителем: ' . $e->getMessage());
                }
            }
        }
        $result = $job['result'];
        if (!empty($result['copy']['key']) && CopyPage::keyValid((string)$result['copy']['key'])) {
            $key = (string)$result['copy']['key'];
            if (CopyPage::keyLive($key)) {
                $result['rollback_notes'][] = 'Файлы копии оставлены: страница, которая их использует, изменена после импорта и не отменена.';
            } else {
                $n = CopyPage::removeFiles($key);
                $result['rollback_notes'][] = 'Файлы копии удалены (' . $n . ').';
                $result['copy']['removed'] = true;
            }
        }
        $added = (array)($result['redirects']['added'] ?? []);
        if ($added !== [] && Compat::redirects()) {
            foreach (array_chunk($added, 1000) as $batch) {
                try {
                    Content::removeRedirects($actor, $batch);
                } catch (AgeCMSException $e) {
                    $result['rollback_notes'][] = 'Редиректы не удалены: ' . $e->getMessage();
                }
            }
        }
        foreach ((array)($result['menus'] ?? []) as $name => $m) {
            if (!isset($m['after'])) {
                continue;
            }
            $current = json_decode((string)Database::value('SELECT items_json FROM menus WHERE name = ?', [$name]), true);
            if ($current !== $m['after']) {
                $result['rollback_notes'][] = 'Меню «' . $name . '» изменено после импорта и оставлено как есть.';
                continue;
            }
            try {
                Content::setMenu($actor, (string)$name, is_array($m['before']) ? $m['before'] : []);
            } catch (AgeCMSException $e) {
                $result['rollback_notes'][] = 'Меню «' . $name . '» не возвращено: ' . $e->getMessage();
            }
        }
        $result['rolled_back_at'] = ag_now();
        Jobs::update($job['id'], ['status' => 'rolled_back', 'result' => $result]);
    }

    public static function statusText(string $status): string
    {
        return [
            'scouting' => 'смотрю сайт', 'scouted' => 'сайт осмотрен', 'capturing' => 'идёт захват в Chrome', 'cancelled' => 'остановлено',
            'analyzing' => 'идёт анализ', 'ready' => 'готово к переносу', 'applying' => 'идёт перенос', 'done' => 'перенесено',
            'rolling_back' => 'идёт отмена', 'rolled_back' => 'отменено', 'failed' => 'ошибка',
        ][$status] ?? $status;
    }

    /** The background tick: one active job, until the deadline of the tick. */
    public static function tick(float $deadline): void
    {
        $job = Jobs::active();
        if ($job === null || $deadline - microtime(true) < 2) {
            return;
        }
        (new self())->step($job['id'], $deadline - 1);
    }
}

