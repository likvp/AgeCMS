<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\Actor;
use AgeCMS\AdminModuleInterface;
use AgeCMS\AgeCMSException;
use AgeCMS\Background;
use AgeCMS\EventBus;
use AgeCMS\ModuleRequest;
use AgeCMS\ModuleResponse;
use AgeCMS\ModuleSettings;
use AgeCMS\Security;
use AgeCMS\Modules\Importer\Admin\Page;

require_once __DIR__ . '/src/autoload.php';

/**
 * Moves the content of an existing site (WordPress, Tilda, 1C-Bitrix, Joomla, Wix, MODX, HostCMS, plain HTML and others)
 * into pages of this site: texts, pictures, structure, menus, SEO fields and 301 redirects from the old addresses.
 * The design is not moved. Admin page /admin/m/importer/, skills import_*, command line bin/import.php.
 */
final class Module implements AdminModuleInterface
{
    public function register(): void
    {
        EventBus::listen(Background::TICK_EVENT, static function (array $tick): void {
            Runner::tick((float)($tick['deadline'] ?? microtime(true) + 5));
        });
    }

    public function handleAdmin(ModuleRequest $request, Actor $actor): ModuleResponse
    {
        return Page::handle($request, $actor);
    }

    /** Options of a new job: the module settings, then what the caller passed. @param array<string,mixed> $args */
    public static function options(array $args = []): array
    {
        $settings = [];
        try {
            $settings = ModuleSettings::all('importer');
        } catch (\Throwable) {
            $settings = [];
        }
        $o = [
            'limit' => (int)($settings['page_limit'] ?? Importer::DEFAULTS['limit']),
            'delay_ms' => (int)($settings['delay_ms'] ?? Importer::DEFAULTS['delay_ms']),
            'chunk' => (int)($settings['chunk'] ?? Importer::DEFAULTS['chunk']),
            'robots' => (bool)($settings['respect_robots'] ?? true),
            'capture_mb' => (int)($settings['capture_mb'] ?? 500),
            'capture_minutes' => (int)($settings['capture_minutes'] ?? 30),
            'capture_memory_mb' => (int)($settings['capture_memory_mb'] ?? 2048),
        ];
        // An exact copy takes fewer pages by default: every page is a browser visit, and the limit is the owner's protection.
        if (($args['mode'] ?? '') === 'exact') {
            $o['limit'] = (int)($settings['capture_pages'] ?? 50);
        }
        foreach (['limit', 'conflict', 'replace_home', 'menus', 'section', 'mode', 'auto_apply'] as $k) {
            if (array_key_exists($k, $args)) {
                $o[$k] = $args[$k];
            }
        }
        return Importer::options($o);
    }

    public function callSkill(string $skill, array $args, Actor $actor): array
    {
        Security::requirePermission($actor, 'system.manage');
        $budget = microtime(true) + 20;
        return match ($skill) {
            'import_detect' => (new Importer(self::options()))->detect(self::str($args, 'url')),
            'import_preview' => $this->preview($args, $actor, $budget),
            'import_apply' => $this->apply($args, $actor, $budget),
            'import_status' => $this->status((int)($args['job_id'] ?? 0), $budget, (bool)($args['details'] ?? false)),
            'import_rollback' => $this->rollback((int)($args['job_id'] ?? 0), $actor, $budget),
            default => throw new AgeCMSException("Unknown skill {$skill}"),
        };
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function preview(array $args, Actor $actor, float $budget): array
    {
        $input = trim((string)($args['url'] ?? ''));
        $tmp = null;
        $name = null;
        if ($input === '' && is_string($args['file_base64'] ?? null)) {
            $bytes = base64_decode($args['file_base64'], true);
            if ($bytes === false || $bytes === '') {
                throw new ImportException('file_base64 не похож на base64.');
            }
            if (strlen($bytes) > 52428800) {
                throw new ImportException('Файл больше 50 МБ: положите его в папку data/import на сервере и передайте путь через админку.');
            }
            $ext = match ((string)($args['type'] ?? '')) {
                'tilda_zip', 'capture_zip', 'zip' => 'zip',
                'html' => 'html',
                default => 'xml',
            };
            $base = (string)tempnam(sys_get_temp_dir(), 'agimport');
            $tmp = $base . '.' . $ext;
            @unlink($base);
            $name = 'файл агента.' . $ext;
            file_put_contents($tmp, $bytes);
            $input = $tmp;
        }
        $uploaded = $tmp !== null;
        if ($input === '') {
            throw new ImportException('Передайте url или file_base64 с type.');
        }
        try {
            $options = self::options($args);
            $id = Runner::create($input, $options, $actor, $name, $uploaded);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
        return $this->status($id, $budget, (bool)($args['details'] ?? false));
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function apply(array $args, Actor $actor, float $budget): array
    {
        $id = (int)($args['job_id'] ?? 0);
        $pages = $args['pages'] ?? null;
        Runner::startApply($id, is_array($pages) ? array_values($pages) : null, $actor);
        return $this->status($id, $budget, false);
    }

    /** @return array<string,mixed> */
    private function rollback(int $id, Actor $actor, float $budget): array
    {
        Runner::startRollback($id, $actor);
        return $this->status($id, $budget, false);
    }

    /** Advances the job within the budget and tells where it is. @return array<string,mixed> */
    private function status(int $id, float $budget, bool $details): array
    {
        if (Jobs::get($id) === null) {
            throw new ImportException('Задание импорта ' . $id . ' не найдено.');
        }
        (new Runner())->step($id, $budget);
        $r = Report::job($id);
        $next = match ($r['status']) {
            'scouting' => 'Сервер осматривает сайт в Chrome: вызовите import_status снова через несколько секунд.',
            'scouted' => 'Сайт осмотрен (поле scout: платформа, число страниц, оценка объёма). Покажите это владельцу и после согласия вызовите import_apply: начнётся захват в Chrome, он идёт в фоне и сам переходит к переносу; вызывайте import_status.',
            'capturing' => 'Захват идёт в фоне (поле capture: страница, число страниц, объём). Вызывайте import_status, пока статус не станет applying или done.',
            'cancelled' => 'Захват остановлен: ' . $r['error'],
            'analyzing' => 'Анализ ещё идёт: вызовите import_status снова.',
            'ready' => 'Покажите владельцу план (pages: адреса, блоки, потери) и после согласия вызовите import_apply (pages: список old_path или id, чтобы взять не все).',
            'applying' => 'Перенос идёт порциями: вызывайте import_status, пока status не станет done.',
            'done' => 'Перенесено. Покажите владельцу отчёт; отменить можно через import_rollback (любой размер, если перенос запускал этот же токен) или кнопкой «Отменить импорт» в админке.',
            'rolling_back' => 'Отмена идёт: вызывайте import_status.',
            'rolled_back' => 'Импорт отменён.',
            default => 'Ошибка: ' . $r['error'],
        };
        if (!$details) {
            foreach ($r['pages'] as &$row) {
                $row['blocks'] = array_map(static fn(array $b): string => $b['type'] . ($b['summary'] !== '' ? ' ' . $b['summary'] : ''), $row['blocks']);
                unset($row['aliases']);
            }
            unset($row);
            if (count($r['redirects']) > 50) {
                $r['redirects'] = array_slice($r['redirects'], 0, 50);
                $r['redirects_cut'] = true;
            }
        }
        return ['job_id' => $id, 'status' => $r['status'], 'next' => $next] + $r + ['admin_url' => '/admin/m/importer/job?id=' . $id];
    }

    /** @param array<string,mixed> $args */
    private static function str(array $args, string $key): string
    {
        $v = $args[$key] ?? null;
        if (!is_string($v) || trim($v) === '') {
            throw new ImportException("Нужен параметр {$key}.");
        }
        return $v;
    }
}
