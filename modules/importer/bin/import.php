<?php
declare(strict_types=1);

// Import of a site from the command line, run from the site root:
//
//   php modules/importer/bin/import.php <address|file> [options]
//
//   <address|file>      https://old-site.ru, a WordPress export (.xml), a Tilda project export (.zip or folder), a saved .html page
//                       or a capture package made by tools/capture (folder or .zip)
//   --mode=exact        (default) exact copy: every page looks and works like the source; the source is captured by Chrome
//                       (tools/capture, needs Node.js and Chrome on this machine) unless it already is a capture package
//   --mode=blocks       the content goes into blocks of the active theme (the older way, editable block by block)
//   --capture-dir=DIR   exact mode: keep the package made from an address or a folder in DIR (default: a temporary folder, removed after)
//   --no-mobile         exact mode, capturing: do not open the phone version (faster, less exact on phones)
//   --no-verify         exact mode, capturing: do not compare the copy with the original
//   --dry-run           (default) read the source and print the plan: tree of pages, blocks, losses, redirects; nothing is written
//   --apply             create the pages (needs an installed site), then redirects and menus
//   --json              the plan or the result as JSON instead of text
//   --full              with --json: also the neutral pages and the page documents (blocks with all fields)
//   --limit=N           most pages to take (default 200)
//   --delay=MS          pause between requests to the source site (default 500)
//   --no-robots         do not follow Disallow of robots.txt
//   --allow-private     let the source be on localhost or the local network, any port (tests of the importer only)
//   --conflict=skip     leave out pages whose address is taken (default: add -2)
//   --replace-home      replace the content of the existing home page
//   --no-menus          keep the menus of this site
//   --section=blog      address of the section for blog posts
//   --pages=/a,/b       with --apply: only these old addresses
//   --status=ID         state of a stored job (with --json for JSON)
//   --rollback=ID       undo an applied job
//   --quiet             no progress lines on stderr
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 3);
require $root . '/core/bootstrap.php';
require dirname(__DIR__) . '/src/autoload.php';

use AgeCMS\Actor;
use AgeCMS\Database;
use AgeCMS\SkillRegistry;
use AgeCMS\Modules\Importer\Importer;
use AgeCMS\Modules\Importer\Jobs;
use AgeCMS\Modules\Importer\Report;
use AgeCMS\Modules\Importer\Runner;

$opts = [];
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $opts[$k] = $v;
    } else {
        $positional[] = $arg;
    }
}
$json = isset($opts['json']);
if (isset($opts['allow-private'])) {
    \AgeCMS\Modules\Importer\Net\HttpFetcher::allowPrivate(true);
}
$quiet = isset($opts['quiet']) || $json;
$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDERR, $line . "\n");
    }
};
$print = static function (array $data) use ($json): void {
    echo $json ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n" : Report::text($data);
};
$needSite = static function (): void {
    if (!Database::exists()) {
        fwrite(STDERR, "Сайт не установлен (нет data/data.sqlite): --apply, --status и --rollback работают только на установленном сайте.\n");
        exit(1);
    }
    SkillRegistry::boot();
    $info = SkillRegistry::modules()['importer'] ?? null;
    if ($info === null || $info['status'] !== 'ok') {
        fwrite(STDERR, 'Модуль importer не работает на этом сайте: ' . ($info['reason'] ?? ($info['status'] ?? 'не найден в modules/')) . "\n");
        exit(1);
    }
};
$options = [
    'limit' => (int)($opts['limit'] ?? 200),
    'delay_ms' => (int)($opts['delay'] ?? 500),
    'robots' => !isset($opts['no-robots']),
    'conflict' => ($opts['conflict'] ?? '') === 'skip' ? 'skip' : 'suffix',
    'replace_home' => isset($opts['replace-home']),
    'menus' => !isset($opts['no-menus']),
    'section' => is_string($opts['section'] ?? null) ? $opts['section'] : '',
    'mode' => ($opts['mode'] ?? 'exact') === 'blocks' ? 'blocks' : 'exact',
];
if (isset($opts['mode']) && !in_array($opts['mode'], Importer::MODES, true)) {
    fwrite(STDERR, "--mode: exact или blocks\n");
    exit(1);
}
$progress = static function (array $job) use ($say): void {
    static $last = '';
    $line = Runner::statusText((string)$job['status']) . ': страниц ' . Jobs::countItems((int)$job['id']) . ', готово ' . Jobs::countItems((int)$job['id'], ['done', 'rolled_back']);
    if ($line !== $last) {
        $say($line);
        $last = $line;
    }
};

try {
    if (isset($opts['status'])) {
        $needSite();
        $print(Report::job((int)$opts['status']));
        exit(0);
    }
    if (isset($opts['rollback'])) {
        $needSite();
        $id = (int)$opts['rollback'];
        Runner::startRollback($id, Actor::cli('importer'));
        $job = (new Runner())->runToEnd($id, $progress);
        $print(Report::job($id));
        exit($job['status'] === 'rolled_back' ? 0 : 1);
    }
    $input = $positional[0] ?? '';
    if ($input === '') {
        fwrite(STDERR, "Usage: php modules/importer/bin/import.php <https://site|export.xml|tilda.zip|package> [--mode=exact|blocks] [--dry-run|--apply] [--json] [--limit=N]\n");
        exit(1);
    }
    // Exact copy of an address, a Tilda export or a saved folder: Chrome captures it first, the importer reads the package.
    $madePackage = '';
    if ($options['mode'] === 'exact') {
        $src = is_dir($input) && !\AgeCMS\Modules\Importer\Extract\CapturePackage::looksDir($input) ? ['type' => 'folder'] : Importer::source($input);
        if (in_array($src['type'], ['url', 'tilda_zip', 'folder'], true)) {
            $keep = is_string($opts['capture-dir'] ?? null) ? $opts['capture-dir'] : '';
            $say('Захват в Chrome: ' . $input);
            $input = \AgeCMS\Modules\Importer\Copy\CaptureRunner::run($input, ['limit' => min($options['limit'], 2000), 'delay_ms' => $options['delay_ms'], 'robots' => $options['robots'], 'mobile' => !isset($opts['no-mobile']), 'verify' => !isset($opts['no-verify'])], static function (string $line) use ($say): void {
                $say('  ' . $line);
            }, $keep);
            $madePackage = $keep === '' ? $input : '';
        }
    }
    $removePackage = static function () use (&$madePackage): void {
        if ($madePackage !== '' && is_dir($madePackage)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($madePackage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($madePackage);
        }
    };
    if (isset($opts['apply'])) {
        $needSite();
        $actor = Actor::cli('importer');
        $id = Runner::create($input, $options, $actor);
        $say('Задание ' . $id . ': анализ...');
        $runner = new Runner();
        $job = $runner->runToEnd($id, $progress);
        if ($job['status'] !== 'ready') {
            $removePackage();
            fwrite(STDERR, 'Анализ не удался: ' . $job['error'] . "\n");
            exit(1);
        }
        $pages = null;
        if (is_string($opts['pages'] ?? null)) {
            $pages = array_values(array_filter(array_map('trim', explode(',', $opts['pages']))));
        }
        Runner::startApply($id, $pages, $actor);
        $say('Перенос...');
        $job = $runner->runToEnd($id, $progress);
        $removePackage();
        $print(Report::job($id));
        $say('Задание ' . $id . ': ' . Runner::statusText((string)$job['status']) . '. Отменить: php modules/importer/bin/import.php --rollback=' . $id);
        exit($job['status'] === 'done' ? 0 : 1);
    }

    // Dry run: everything in memory, nothing is written.
    $importer = new Importer($options);
    $n = 0;
    $dir = sys_get_temp_dir() . '/agecms-import-' . getmypid();
    $analysis = $importer->analyze($input, static function (array $page) use (&$n, $say): void {
        $n++;
        $say(sprintf('%4d  %s  «%s»', $n, $page['old_path'], mb_substr((string)$page['title'], 0, 60)));
    }, $dir);
    $pages = [];
    foreach ($analysis['pages'] as $i => $page) {
        $page['id'] = $i + 1;
        $pages[] = $page;
    }
    $plan = $importer->plan($pages, $analysis['site']);
    $report = Report::fromPlan($analysis, $plan, $pages);
    $report['requests'] = $importer->fetcher()->count();
    $removePackage();
    if ($json && isset($opts['full'])) {
        $report['neutral'] = $analysis['pages'];
        $report['documents'] = array_map(static fn(array $e): array => ['slug' => $e['slug'], 'action' => $e['action']] + $e['doc'], $plan['items']);
    }
    $print($report);
    if (!$json) {
        echo "\nЭто предпросмотр, на сайте ничего не изменено. Перенести: добавьте --apply.\n";
    }
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
} catch (\AgeCMS\AgeCMSException $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
