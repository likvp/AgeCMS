<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Copy;

use AgeCMS\Actor;
use AgeCMS\Modules\Importer\Detect\Detector;
use AgeCMS\Modules\Importer\ImportException;
use AgeCMS\Modules\Importer\Importer;
use AgeCMS\Modules\Importer\Jobs;
use AgeCMS\Modules\Importer\Extract\CapturePackage;
use AgeCMS\Modules\Importer\Runner;

/**
 * "Exact copy" of a site by its address, done on the server. The steps of such a job:
 *
 *   scouting   a quick look: the capture tool opens the home page, reads the sitemap, says what the site is, how many pages it has
 *              and how heavy the copy will be (background process, nothing is saved)
 *   scouted    the owner sees that and presses "Начать перенос"
 *   capturing  the capture tool visits the pages (background process, progress in a status file), the limits of pages, size, time
 *              and memory are the owner's, the process can be stopped
 *   analyzing  the finished package is read like any uploaded package, then the usual preview, transfer and rollback follow
 *
 * Only one capture runs on a site at a time. Every request that looks at a job (the job page, the background tick, a new import)
 * calls poll(), which reads the status file, notices a dead process and moves the job on.
 */
final class CaptureFlow
{
    /** Name of the process in the status file and on the command line: not the secret of the job page. @param array<string,mixed> $job */
    public static function procToken(array $job): string
    {
        return substr(hash('sha256', 'capture|' . $job['id'] . '|' . $job['token']), 0, 16);
    }

    /** The fast checks that must pass before a browser is started. Throws ImportException with a message for a person. */
    private static function preflight(string $url, int $diskMb): void
    {
        $problem = CaptureRunner::problem();
        if ($problem !== null) {
            throw new ImportException(Importer::captureHelp('url', $problem));
        }
        $test = CaptureRunner::selfTest();
        if (!$test['ok']) {
            throw new ImportException(Importer::captureHelp('url', $test['message']));
        }
        $address = CaptureRunner::addressProblem($url);
        if ($address !== null) {
            throw new ImportException('Адрес не подходит: ' . $address . '.');
        }
        $disk = CaptureRunner::diskProblem($diskMb);
        if ($disk !== null) {
            throw new ImportException(ucfirst($disk));
        }
    }

    /** Starts the look at a site. Returns the id of the job. @param array<string,mixed> $options */
    public static function create(string $url, array $options, Actor $actor): int
    {
        self::reap();
        self::preflight($url, 60);
        $id = Jobs::create($url, 'url', $options, $actor, '', 'scouting');
        self::launch($id, 'scout');
        return $id;
    }

    /** Starts the process of a job in the given kind; a job whose process cannot start is removed (kind scout) or failed. */
    private static function launch(int $id, string $kind): void
    {
        $job = Jobs::get($id) ?? throw new ImportException('Задание импорта не найдено.');
        try {
            $r = CaptureJob::start($id, (string)($job['result']['source_name'] ?? $job['source']), $job['options'], $kind, self::procToken($job));
        } catch (ImportException $e) {
            if ($kind === 'scout') {
                Jobs::delete($id);
                self::removeDir(Runner::dir($id));
            } else {
                Jobs::update($id, ['status' => 'failed', 'error' => $e->getMessage()]);
            }
            throw $e;
        }
        $result = $job['result'];
        $result['capture'] = ['kind' => $kind, 'pid' => $r['pid'], 'started' => time(), 'phase' => 'start', 'message' => 'Запускаю браузер', 'pages' => 0, 'files' => 0, 'bytes' => 0, 'warnings' => [], 'blocked' => 0];
        Jobs::update($id, ['result' => $result]);
    }

    /**
     * The owner presses "Начать перенос" (or an agent applies a job that was only looked at). $overrides: limit, auto_apply.
     *
     * @param array<string,mixed> $overrides
     */
    public static function start(int $id, Actor $actor, array $overrides = []): void
    {
        self::reap();
        $job = Jobs::get($id) ?? throw new ImportException('Задание импорта не найдено.');
        if ($job['source_type'] !== 'url' || !in_array($job['status'], ['scouted', 'failed', 'cancelled'], true)) {
            throw new ImportException('Начать перенос можно после разведки сайта (сейчас задание «' . Runner::statusText((string)$job['status']) . '»).');
        }
        $options = $job['options'];
        if (isset($overrides['limit']) && (int)$overrides['limit'] > 0) {
            $options['limit'] = max(1, min(2000, (int)$overrides['limit']));
        }
        if (array_key_exists('auto_apply', $overrides)) {
            $options['auto_apply'] = (bool)$overrides['auto_apply'];
        }
        $lim = CaptureJob::limits($options);
        self::preflight((string)$job['source'], $lim['mb']);
        $other = Jobs::capturing($id);
        if ($other !== null) {
            throw new ImportException('Захват другого сайта уже идёт (задание ' . $other['id'] . '). Дождитесь его конца или остановите его.');
        }
        $result = $job['result'];
        $result['source_name'] = (string)$job['source'];
        $result['capture'] = ['kind' => 'capture', 'started' => time(), 'phase' => 'start', 'message' => 'Запускаю браузер', 'pages' => 0, 'files' => 0, 'bytes' => 0, 'warnings' => [], 'blocked' => 0];
        if (!Jobs::transition($id, ['scouted', 'failed', 'cancelled'], 'capturing', ['options' => $options, 'error' => '', 'result' => $result, 'actor' => ['type' => $actor->type, 'name' => $actor->name, 'role' => $actor->role]])) {
            throw new ImportException('Это задание уже запущено или другой захват занял место. Обновите страницу.');
        }
        self::removeDir(CaptureJob::dir($id, 'capture'));
        self::launch($id, 'capture');
    }

    /** Stops the background process of a job and marks the job cancelled. */
    public static function cancel(int $id): void
    {
        $job = Jobs::get($id) ?? throw new ImportException('Задание импорта не найдено.');
        if (!in_array($job['status'], Jobs::CAPTURING, true)) {
            return;
        }
        $kind = $job['status'] === 'scouting' ? 'scout' : 'capture';
        CaptureJob::stop($id, $kind, self::procToken($job), (int)($job['result']['capture']['pid'] ?? 0));
        $result = $job['result'];
        $result['capture']['message'] = 'Остановлено вами';
        $result['cancelled_at'] = ag_now();
        Jobs::transition($id, Jobs::CAPTURING, 'cancelled', ['error' => 'Захват остановлен.', 'result' => $result]);
        self::removeDir(CaptureJob::dir($id, 'capture') . '/pkg');
        self::removeDir(Runner::dir($id) . '/tmp');
    }

    /** Looks at every job whose process runs (or should): a dead one is marked interrupted, a finished one moves on. */
    public static function reap(): void
    {
        $job = Jobs::capturing();
        if ($job === null) {
            return;
        }
        (new Runner())->step((int)$job['id'], microtime(true) + 5);
    }

    /** One look at the process of a job in the status scouting or capturing. Called by Runner::step with the job locked. @param array<string,mixed> $job */
    public static function poll(array $job): void
    {
        $id = (int)$job['id'];
        $kind = $job['status'] === 'scouting' ? 'scout' : 'capture';
        $cap = (array)($job['result']['capture'] ?? []);
        $started = (int)($cap['started'] ?? time());
        $st = CaptureJob::status($id, $kind);
        if ($st !== null && (string)($st['token'] ?? '') !== self::procToken($job)) {
            $st = null; // the file of another run
        }
        if ($st === null) {
            if (time() - $started > CaptureJob::START_WAIT) {
                $log = trim((string)@file_get_contents(CaptureJob::dir($id, $kind) . '/run.log'));
                self::fail($job, 'Процесс захвата не запустился' . ($log !== '' ? ': ' . mb_substr(preg_replace('~\s+~', ' ', $log) ?? $log, -300) : '. Проверьте, что на сервере работают Node.js и Chrome.'), 'not_started');
            }
            return;
        }
        $summary = [
            'kind' => $kind, 'pid' => (int)($st['pid'] ?? 0), 'started' => $started, 'phase' => (string)($st['phase'] ?? ''), 'message' => mb_substr((string)($st['message'] ?? ''), 0, 300),
            'pages' => (int)($st['pages'] ?? 0), 'limit' => (int)($st['limit'] ?? 0), 'files' => (int)($st['files'] ?? 0), 'bytes' => (int)($st['bytes'] ?? 0), 'url' => mb_substr((string)($st['url'] ?? ''), 0, 300),
            'warnings' => array_slice(array_map(static fn($w): string => mb_substr((string)$w, 0, 400), (array)($st['warnings'] ?? [])), 0, 10), 'blocked' => (int)($st['blocked'] ?? 0),
            'queue' => (int)($st['queue'] ?? 0), 'built' => (int)($st['built'] ?? 0), 'verified' => (int)($st['verified'] ?? 0), 'updated' => (int)floor(((int)($st['updated'] ?? 0)) / 1000),
        ];
        $result = $job['result'];
        if (($result['capture'] ?? null) !== $summary) {
            $result['capture'] = $summary;
            Jobs::update($id, ['result' => $result]);
            $job['result'] = $result;
        }
        $state = (string)($st['state'] ?? 'running');
        if ($state === 'running') {
            if (!$st['alive']) {
                self::fail($job, 'Захват прервался: процесс остановился (возможно, сервер перезапускали или заканчивалась память). Нажмите «Повторить захват».', 'interrupted');
                return;
            }
            $lim = CaptureJob::limits($job['options']);
            if (time() - $started > ($kind === 'scout' ? 300 : $lim['minutes'] * 60 + 180)) {
                CaptureJob::stop($id, $kind, self::procToken($job), (int)($cap['pid'] ?? 0));
                self::fail($job, 'Захват не уложился во время и остановлен. Уменьшите число страниц или увеличьте время в настройках модуля.', 'timeout');
            }
            return;
        }
        if ($state === 'failed') {
            self::fail($job, (string)($st['error'] ?? $st['message'] ?? 'Захват не удался.'), (string)($st['code'] ?? 'error'));
            return;
        }
        if ($state === 'cancelled') {
            Jobs::transition($id, Jobs::CAPTURING, 'cancelled', ['error' => 'Захват остановлен.']);
            return;
        }
        if ($state !== 'done') {
            return;
        }
        if ($kind === 'scout') {
            self::scouted($job, (array)($st['result'] ?? []));
            return;
        }
        self::captured($job, $st);
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $scout */
    private static function scouted(array $job, array $scout): void
    {
        $platform = preg_match('~^[a-z0-9_]{1,20}$~', (string)($scout['platform'] ?? '')) === 1 && $scout['platform'] !== 'unknown' ? (string)$scout['platform'] : 'generic';
        $detect = ['platform' => $platform, 'name' => Detector::name($platform), 'confidence' => $platform === 'generic' ? 0.0 : 0.9, 'evidence' => ['главная страница открыта в Chrome'], 'scores' => []];
        $result = $job['result'];
        $result['scout'] = [
            'platform' => $platform, 'title' => mb_substr((string)($scout['title'] ?? ''), 0, 200), 'pages_found' => (int)($scout['pages_found'] ?? 0), 'pages_planned' => (int)($scout['pages_planned'] ?? 0),
            'home_files' => (int)($scout['home_files'] ?? 0), 'home_bytes' => (int)($scout['home_bytes'] ?? 0), 'estimate_bytes' => (int)($scout['estimate_bytes'] ?? 0),
            'estimate_seconds' => (int)($scout['estimate_seconds'] ?? 0), 'status' => (int)($scout['status'] ?? 0), 'final_url' => mb_substr((string)($scout['final_url'] ?? ''), 0, 500),
        ];
        $result['capture']['message'] = 'Разведка закончена';
        Jobs::transition((int)$job['id'], ['scouting'], 'scouted', ['platform' => $platform, 'detect' => $detect, 'result' => $result]);
        self::removeDir(Runner::dir((int)$job['id']) . '/tmp');
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $st */
    private static function captured(array $job, array $st): void
    {
        $id = (int)$job['id'];
        $pkg = CaptureJob::pkg($id);
        try {
            $check = CapturePackage::verifyDir($pkg);
        } catch (ImportException $e) {
            self::fail($job, 'Захват закончился, но пакет не принят: ' . $e->getMessage(), 'package');
            return;
        }
        $result = $job['result'];
        $result['captured_from'] = (string)$job['source'];
        $result['source_name'] = (string)$job['source'];
        $result['capture']['verified_files'] = $check['files'];
        $result['capture']['bytes'] = $check['bytes'];
        $result['capture']['message'] = 'Захват закончен';
        $result['capture']['done'] = true;
        $result['capture']['finished'] = time();
        if (!empty($st['leftover'])) {
            $result['capture']['leftover'] = (int)$st['leftover'];
        }
        // From here the job is an ordinary job over a package folder: analysis, preview, transfer, rollback.
        Jobs::transition($id, ['capturing'], 'analyzing', ['source_type' => 'capture_dir', 'source' => $pkg, 'state' => ['warnings' => $check['warnings']], 'result' => $result, 'error' => '']);
        self::removeDir(Runner::dir($id) . '/tmp');
    }

    /** @param array<string,mixed> $job */
    private static function fail(array $job, string $message, string $code): void
    {
        $result = $job['result'];
        $result['capture']['message'] = 'Остановлено';
        $result['capture']['failed_code'] = $code;
        Jobs::transition((int)$job['id'], Jobs::CAPTURING, 'failed', ['error' => mb_substr($message, 0, 1000), 'result' => $result]);
        self::removeDir(Runner::dir((int)$job['id']) . '/tmp');
        if ($code !== 'package') {
            self::removeDir(CaptureJob::dir((int)$job['id'], 'capture') . '/pkg');
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
