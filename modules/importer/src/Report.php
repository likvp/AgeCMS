<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\Modules\Importer\Detect\Detector;

/**
 * One shape of the preview and of the result for every front door (admin, skills, command line), and its text form
 * for the terminal: the tree of pages with old and new addresses, blocks of each page and what was lost.
 */
final class Report
{
    /**
     * Preview from an in-memory analysis and plan (command line --dry-run, tests).
     *
     * @param array<string,mixed> $analysis Importer::analyze()
     * @param array<string,mixed> $plan Importer::plan()
     * @param list<array<string,mixed>> $pages pages with "id"
     * @return array<string,mixed>
     */
    public static function fromPlan(array $analysis, array $plan, array $pages): array
    {
        $rows = [];
        foreach (array_merge($pages, $plan['sections']) as $p) {
            $e = $plan['items'][$p['id']];
            $rows[] = self::row($p, $e, $e['doc'], $e['losses'], 1, $e['action'] === 'skip' ? 'skipped' : 'pending', '');
        }
        $out = self::wrap((string)$analysis['source']['source'], (array)$analysis['detect'], (array)$analysis['site'], $rows, $plan['menus'], $plan['redirects'], array_values(array_unique(array_merge($analysis['warnings'], $plan['warnings']))), $plan['roles'], 'ready', []);
        $out['mode'] = (string)($plan['mode'] ?? 'blocks');
        if ($out['mode'] === 'exact') {
            $out['copy'] = self::copySummary($rows, (array)($analysis['capture'] ?? []), []);
        }
        return $out;
    }

    /**
     * What an exact copy brings: pages, files, size, what was left out.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $capture the package as the extractor saw it
     * @param array<string,mixed> $installed result["copy"] of a job
     * @return array<string,mixed>
     */
    private static function copySummary(array $rows, array $capture, array $installed): array
    {
        $chosen = array_filter($rows, static fn(array $r): bool => $r['selected'] && $r['action'] !== 'skip');
        $trackers = [];
        $forms = 0;
        $unconnected = 0;
        $html = 0;
        foreach ($chosen as $r) {
            $c = (array)($r['copy'] ?? []);
            $trackers = array_merge($trackers, (array)($c['trackers'] ?? []));
            $forms += (int)($c['forms'] ?? 0);
            $unconnected += (int)($c['forms_unconnected'] ?? 0);
            $html += (int)($c['size'] ?? 0);
        }
        return [
            'pages' => count($chosen),
            'files' => (int)($installed['total'] ?? $capture['files'] ?? 0),
            'bytes' => (int)($capture['bytes'] ?? 0) + $html,
            'installed_bytes' => (int)($installed['bytes'] ?? 0),
            'trackers' => array_values(array_unique($trackers)),
            'forms' => $forms,
            'forms_unconnected' => $unconnected,
            'skipped_files' => (int)($capture['skipped_files'] ?? 0),
            'installed' => !empty($installed['installed']),
            'key' => (string)($installed['key'] ?? ''),
        ];
    }

    /** Preview or result of a stored job. @return array<string,mixed> */
    public static function job(int $jobId): array
    {
        $job = Jobs::get($jobId) ?? throw new ImportException('Задание импорта не найдено.');
        $rows = [];
        $redirects = [];
        foreach (Jobs::items($jobId) as $item) {
            $p = $item['page'];
            $e = ['action' => $item['action'], 'slug' => $item['new_slug'], 'parent_slug' => (string)($item['plan']['parent_slug'] ?? ''), 'reason' => (string)($item['plan']['reason'] ?? ''), 'redirects' => (array)($item['plan']['redirects'] ?? [])];
            $row = self::row($p, $e, $item['doc'], $item['losses'], $item['selected'], (string)$item['status'], (string)$item['error']);
            $row['id'] = $item['id'];
            $rows[] = $row;
            if ($item['selected'] === 1 && $item['action'] !== 'skip') {
                $redirects = array_merge($redirects, $e['redirects']);
            }
        }
        $state = $job['state'];
        $out = self::wrap((string)$job['source'], (array)$job['detect'], (array)$job['site'], $rows, (array)($job['plan']['menus'] ?? []), $redirects, array_values(array_unique(array_merge((array)($state['warnings'] ?? []), (array)($job['plan']['warnings'] ?? [])))), (array)($job['plan']['roles'] ?? []), (string)$job['status'], $job['result']);
        $out['mode'] = (string)($job['options']['mode'] ?? 'blocks');
        if ($out['mode'] === 'exact') {
            $out['copy'] = self::copySummary($rows, (array)($state['capture'] ?? []), (array)($job['result']['copy'] ?? []));
        }
        if (($job['result']['mode_note'] ?? '') !== '') {
            $out['warnings'][] = (string)$job['result']['mode_note'];
        }
        $out['job_id'] = $jobId;
        $out['status_text'] = Runner::statusText((string)$job['status']);
        $out['error'] = (string)$job['error'];
        $out['found'] = count($rows);
        $out['source_type'] = (string)$job['source_type'];
        return $out;
    }

    /**
     * @param array<string,mixed> $p neutral page
     * @param array<string,mixed> $e plan entry
     * @param array<string,mixed> $doc
     * @param list<array<string,string>> $losses
     * @return array<string,mixed>
     */
    private static function row(array $p, array $e, array $doc, array $losses, int $selected, string $status, string $error): array
    {
        return [
            'id' => $p['id'] ?? null,
            'old_path' => (string)($p['old_path'] ?? ''),
            'aliases' => (array)($p['aliases'] ?? []),
            'title' => (string)($p['title'] ?? ''),
            'kind' => (string)($p['kind'] ?? 'page'),
            'published_at' => (string)($p['published_at'] ?? ''),
            'new' => !empty($p['section']),
            'selected' => $selected === 1,
            'action' => (string)$e['action'],
            'slug' => (string)$e['slug'],
            'parent_slug' => (string)($e['parent_slug'] ?? ''),
            'reason' => (string)($e['reason'] ?? ''),
            'status' => $status,
            'error' => $error,
            'blocks' => array_map([self::class, 'block'], (array)($doc['blocks'] ?? [])),
            'losses' => $losses,
            'redirects' => (array)($e['redirects'] ?? []),
            'elements' => count((array)($p['elements'] ?? [])),
            'copy' => self::copyRow((array)($p['copy'] ?? [])),
        ];
    }

    /** What the preview says about one copied page. @param array<string,mixed> $c @return array<string,mixed>|null */
    private static function copyRow(array $c): ?array
    {
        if ($c === []) {
            return null;
        }
        $forms = array_filter((array)($c['forms'] ?? []), static fn($f): bool => is_array($f) && empty($f['search']));
        return [
            'mode' => (string)($c['mode'] ?? 'source'),
            'size' => (int)($c['size'] ?? 0),
            'trackers' => array_values(array_map('strval', (array)($c['trackers'] ?? []))),
            'forms' => count($forms),
            'forms_unconnected' => count(array_filter($forms, static fn(array $f): bool => empty($f['rewired']))),
            'phone' => (string)($c['mobile_file'] ?? '') !== '',
            'similarity' => (array)($c['similarity'] ?? []),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function wrap(string $source, array $detect, array $site, array $rows, array $menus, array $redirects, array $warnings, array $roles, string $status, array $result): array
    {
        $count = static fn(callable $f): int => count(array_filter($rows, $f));
        return [
            'source' => $source,
            'status' => $status,
            'platform' => [
                'id' => (string)($detect['platform'] ?? 'generic'),
                'name' => (string)($detect['name'] ?? Detector::name((string)($detect['platform'] ?? 'generic'))),
                'confidence' => (float)($detect['confidence'] ?? 0),
                'evidence' => array_values((array)($detect['evidence'] ?? [])),
            ],
            'site' => ['title' => (string)($site['title'] ?? ''), 'source_url' => (string)($site['source_url'] ?? '')],
            'counts' => [
                'pages' => $count(static fn(array $r): bool => !$r['new']),
                'posts' => $count(static fn(array $r): bool => $r['kind'] === 'post'),
                'create' => $count(static fn(array $r): bool => $r['action'] === 'create' && $r['selected']),
                'update' => $count(static fn(array $r): bool => $r['action'] === 'update_home' && $r['selected']),
                'skip' => $count(static fn(array $r): bool => $r['action'] === 'skip' || !$r['selected']),
                'sections' => $count(static fn(array $r): bool => $r['new'] && $r['action'] === 'create' && $r['selected']),
                'sections_skipped' => $count(static fn(array $r): bool => $r['new'] && ($r['action'] === 'skip' || !$r['selected'])),
                'done' => $count(static fn(array $r): bool => $r['status'] === 'done'),
                'failed' => $count(static fn(array $r): bool => $r['status'] === 'failed'),
                'pending' => $count(static fn(array $r): bool => $r['status'] === 'pending'),
                'rolled_back' => $count(static fn(array $r): bool => $r['status'] === 'rolled_back'),
                'kept' => $count(static fn(array $r): bool => $r['status'] === 'kept'),
                'losses' => array_sum(array_map(static fn(array $r): int => count($r['losses']), $rows)),
                'redirects' => count($redirects),
            ],
            'roles' => $roles,
            'pages' => $rows,
            'menus' => $menus,
            'redirects' => $redirects,
            'warnings' => $warnings,
            'result' => $result,
        ];
    }

    /** Short description of a block for the preview. @param array<string,mixed> $b @return array{id:string,type:string,summary:string} */
    public static function block(array $b): array
    {
        $data = (array)($b['data'] ?? []);
        $cut = static fn(string $s, int $n = 50): string => mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
        $summary = '';
        foreach ($data as $v) {
            if (is_array($v)) {
                $summary = count($v) . ' шт.';
                break;
            }
        }
        foreach (['h1', 'heading', 'title', 'alt'] as $k) {
            if (($data[$k] ?? '') !== '' && is_string($data[$k])) {
                $summary = '«' . $cut($data[$k]) . '»' . ($summary !== '' ? ', ' . $summary : '');
                break;
            }
        }
        foreach ($data as $k => $v) {
            if (is_string($v) && mb_strlen($v) > 200) {
                $summary .= ($summary !== '' ? ', ' : '') . mb_strlen(Neutral::text($v)) . ' знаков';
                break;
            }
        }
        if ($summary === '' && isset($data['parent'])) {
            $summary = 'записи /' . $data['parent'];
        }
        return ['id' => (string)($b['id'] ?? ''), 'type' => (string)($b['type'] ?? ''), 'summary' => $summary];
    }

    /** Terminal text: platform, tree, blocks, losses, redirects, menus. @param array<string,mixed> $r */
    public static function text(array $r, bool $details = true): string
    {
        $out = [];
        $p = $r['platform'];
        $out[] = 'Источник: ' . $r['source'];
        $out[] = 'Платформа: ' . $p['name'] . ' (уверенность ' . (int)round($p['confidence'] * 100) . '%)';
        foreach ($p['evidence'] as $ev) {
            $out[] = '  - ' . $ev;
        }
        if ($r['site']['title'] !== '') {
            $out[] = 'Сайт: ' . $r['site']['title'];
        }
        $c = $r['counts'];
        $out[] = sprintf('Найдено страниц: %d (записей блога: %d). Будет создано страниц: %d%s, заменено: %d, не переносится: %d. Потерь: %d. Редиректов: %d.', $c['pages'], $c['posts'], $c['create'], $c['sections'] > 0 ? ' (из них новых разделов: ' . $c['sections'] . ')' : '', $c['update'], $c['skip'] - $c['sections_skipped'], $c['losses'], $c['redirects']);
        if (($r['mode'] ?? '') === 'exact' && isset($r['copy'])) {
            $k = $r['copy'];
            $out[] = sprintf('Режим: точная копия. Страниц %d, файлов %d, размер около %s. Счётчики и чаты не переносятся (%s). Форм: %d, не подключено: %d.',
                $k['pages'], $k['files'], self::mb((int)$k['bytes']), $k['trackers'] === [] ? 'их не было' : implode(', ', $k['trackers']), $k['forms'], $k['forms_unconnected']);
        }
        if (($r['roles'] ?? []) !== []) {
            $out[] = 'Блоки темы: ' . implode(', ', array_map(static fn(string $role, string $type): string => $role . '=' . $type, array_keys($r['roles']), $r['roles']));
        }
        $out[] = '';
        $out[] = 'Страницы (старый адрес -> новый адрес):';
        $rows = $r['pages'];
        usort($rows, static fn(array $a, array $b): int => [$a['slug'] === 'index' ? 0 : 1, $a['slug']] <=> [$b['slug'] === 'index' ? 0 : 1, $b['slug']]);
        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, mb_strlen(self::oldLabel($row)) + 2 * substr_count($row['slug'], '/'));
        }
        $width = min($width, 60);
        foreach ($rows as $row) {
            $indent = str_repeat('  ', substr_count($row['slug'], '/'));
            $old = self::oldLabel($row);
            $new = $row['slug'] === 'index' ? '/' : '/' . $row['slug'];
            $flag = match (true) {
                !$row['selected'] => ' [не выбрана]',
                $row['action'] === 'skip' => ' [пропуск: ' . $row['reason'] . ']',
                $row['action'] === 'update_home' => ' [заменит главную]',
                $row['status'] === 'failed' => ' [ошибка: ' . mb_substr($row['error'], 0, 120) . ']',
                $row['status'] === 'done' => ' [готово]',
                $row['reason'] !== '' => ' [' . $row['reason'] . ']',
                default => '',
            };
            $kind = $row['kind'] === 'post' ? ' (запись' . ($row['published_at'] !== '' ? ' ' . $row['published_at'] : '') . ')' : '';
            $out[] = '  ' . $indent . str_pad($old, max(0, $width - mb_strlen($indent) + (strlen($old) - mb_strlen($old)))) . ' -> ' . $new . '  «' . mb_substr($row['title'], 0, 60) . '»' . $kind . $flag;
            if (!$details || $row['action'] === 'skip' || !$row['selected']) {
                continue;
            }
            if (is_array($row['copy'] ?? null)) {
                $c = $row['copy'];
                $sim = $c['similarity'];
                $out[] = '  ' . $indent . '    копия: ' . ($c['mode'] === 'dom' ? 'итоговая страница' : 'исходный код') . ', ' . self::mb((int)$c['size'])
                    . (is_numeric($sim['desktop'] ?? null) ? ', сходство на компьютере ' . (int)round((float)$sim['desktop'] * 100) . '%' : '')
                    . (is_numeric($sim['mobile'] ?? null) ? ', на телефоне ' . (int)round((float)$sim['mobile'] * 100) . '%' : '')
                    . ($c['phone'] ? ', отдельная версия для телефона' : '');
            }
            $blocks = array_map(static fn(array $b): string => $b['type'] . ($b['summary'] !== '' ? ' ' . $b['summary'] : ''), $row['blocks']);
            if ($blocks !== []) {
                $out[] = '  ' . $indent . '    блоки: ' . implode('; ', $blocks);
            }
            foreach ($row['losses'] as $loss) {
                $out[] = '  ' . $indent . '    потеря: ' . $loss['what'] . ': ' . $loss['why'] . ($loss['source'] !== '' ? ' [' . mb_substr($loss['source'], 0, 80) . ']' : '');
            }
        }
        if ($r['redirects'] !== []) {
            $out[] = '';
            $out[] = 'Редиректы 301 (' . count($r['redirects']) . '):';
            foreach (array_slice($r['redirects'], 0, $details ? 200 : 20) as $rd) {
                $out[] = '  ' . $rd['from'] . ' -> ' . $rd['to'];
            }
            if (count($r['redirects']) > ($details ? 200 : 20)) {
                $out[] = '  ...';
            }
        }
        foreach (['header' => 'шапки', 'footer' => 'подвала'] as $name => $label) {
            $items = (array)($r['menus'][$name] ?? []);
            if ($items !== []) {
                $out[] = '';
                $out[] = 'Меню ' . $label . ': ' . implode(', ', array_map(static fn(array $i): string => $i['title'] . ' (' . $i['url'] . ')', $items));
            }
        }
        if ($r['warnings'] !== []) {
            $out[] = '';
            $out[] = 'Предупреждения:';
            foreach ($r['warnings'] as $w) {
                $out[] = '  - ' . $w;
            }
        }
        $res = $r['result'] ?? [];
        if (isset($res['redirects'])) {
            $out[] = '';
            $back = ($r['status'] ?? '') === 'rolled_back';
            $out[] = ($back ? 'Редиректов было добавлено: ' : 'Редиректов добавлено: ') . count($res['redirects']['added'] ?? []) . ($back ? ' (при отмене убраны)' : '') . ', не принято: ' . count($res['redirects']['refused'] ?? []);
            foreach (array_slice((array)($res['redirects']['refused'] ?? []), 0, 20) as $rf) {
                $out[] = '  - ' . $rf['from'] . ': ' . Compat::redirectWhy((string)$rf['why']);
            }
            if (($res['redirects']['note'] ?? '') !== '') {
                $out[] = '  ' . $res['redirects']['note'];
            }
        }
        foreach ((array)($res['notes'] ?? []) as $n) {
            $out[] = 'Примечание: ' . $n;
        }
        foreach ((array)($res['rollback_notes'] ?? []) as $n) {
            $out[] = 'Отмена: ' . $n;
        }
        return implode("\n", $out) . "\n";
    }

    private static function mb(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '') . ' МБ' : max(1, (int)round($bytes / 1024)) . ' КБ';
    }

    private static function oldLabel(array $row): string
    {
        if ($row['new']) {
            return '(новый раздел)';
        }
        $old = $row['old_path'];
        return mb_strlen($old) > 50 ? mb_substr($old, 0, 49) . '…' : $old;
    }
}
