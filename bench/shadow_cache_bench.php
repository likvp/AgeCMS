<?php
declare(strict_types=1);

// php bench/shadow_cache_bench.php [requests=3000] [concurrency=32] [base-url]
// With a base URL (Apache, nginx or anything with this site installed) no server is started and only
// that server is measured: first "?n=" full render, then the plain URL that the web server should answer
// from assets/cache/ without PHP.
// Measures the home page on a throwaway site served by `php -S` (4 workers):
//   full render  - every request bypasses the cache (query string forces PHP + SQLite + templates)
//   cache hit    - GET / answered by the first lines of index.php from assets/cache/index.html
$requests = (int)($argv[1] ?? 3000);
$concurrency = (int)($argv[2] ?? 32);

$external = isset($argv[3]) ? rtrim($argv[3], '/') : null;
$tmp = sys_get_temp_dir() . '/agecms-bench-' . getmypid();
if ($external === null) {
    mkdir($tmp, 0700, true);
    $root = dirname(__DIR__);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);

    $env = [
        'AGECMS_DATA_DIR' => "$tmp/data", 'AGECMS_CACHE_DIR' => "$tmp/cache", 'AGECMS_MODULES_DIR' => "$tmp/modules",
        'AGECMS_UPLOADS_DIR' => "$tmp/uploads", 'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => (string)getenv('PATH'),
    ];
    mkdir("$tmp/modules");
    $install = proc_open([PHP_BINARY, "$root/bin/install.php", "--url=http://127.0.0.1:$port", '--password=Bench-pass-123456'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $p, $root, $env);
    if (proc_close($install) !== 0) {
        fwrite(STDERR, "install failed\n");
        exit(1);
    }
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, "$root/tests/support/router.php"], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $p, $root, $env);
    register_shutdown_function(static function () use ($server, $tmp): void {
        proc_terminate($server);
        proc_close($server);
        exec('rm -rf ' . escapeshellarg($tmp));
    });
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e, $s, 0.1); $i++) {
        usleep(100000);
    }
}

/** @return array{rps:float,p50:float,p95:float,p99:float,ok:int,bytes:int} */
function run(string $urlFor, int $n, int $c): array
{
    $mh = curl_multi_init();
    $lat = [];
    $ok = 0;
    $bytes = 0;
    $started = 0;
    $active = [];
    $start = microtime(true);
    $launch = function () use (&$started, &$active, $mh, $urlFor, $n): void {
        if ($started >= $n) {
            return;
        }
        $ch = curl_init(sprintf($urlFor, $started++));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_TCP_NODELAY => true]);
        curl_multi_add_handle($mh, $ch);
        $active[spl_object_id($ch)] = $ch;
    };
    for ($i = 0; $i < $c; $i++) {
        $launch();
    }
    while ($active) {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.01);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $lat[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            $ok += curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? 1 : 0;
            $bytes = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            curl_multi_remove_handle($mh, $ch);
            unset($active[spl_object_id($ch)]);
            $launch();
        }
    }
    $elapsed = microtime(true) - $start;
    sort($lat);
    $q = static fn(float $p): float => $lat[(int)min(count($lat) - 1, floor(count($lat) * $p))];
    return ['rps' => $n / $elapsed, 'p50' => $q(0.5), 'p95' => $q(0.95), 'p99' => $q(0.99), 'ok' => $ok, 'bytes' => $bytes];
}

$base = $external ?? "http://127.0.0.1:$port";
run("$base/?w=%d", 50, 4);            // warm opcache and SQLite
file_get_contents("$base/");           // store the cache file
$probe = get_headers("$base/", true);
if (($probe['X-AgeCMS-Cache'] ?? '') !== 'hit') {
    fwrite(STDERR, "the page is not served from the cache, nothing to compare\n");
    exit(1);
}
$full = run("$base/?n=%d", $requests, $concurrency);
$hit = run("$base/", $requests, $concurrency);

printf("%s, %d requests, concurrency %d, page %d bytes\n\n", $external ?? ('php -S with 4 workers, PHP ' . PHP_VERSION), $requests, $concurrency, $hit['bytes']);
printf("%-34s %10s %9s %9s %9s %6s\n", 'path', 'req/s', 'p50 ms', 'p95 ms', 'p99 ms', 'ok');
foreach (['full render (PHP + SQLite)' => $full, 'shadow cache hit' . ($external === null ? ' (PHP fast path)' : '') => $hit] as $name => $r) {
    printf("%-34s %10.0f %9.2f %9.2f %9.2f %6d\n", $name, $r['rps'], $r['p50'], $r['p95'], $r['p99'], $r['ok']);
}
printf("\nspeed-up on this setup: x%.1f requests/s, x%.1f median latency\n", $hit['rps'] / $full['rps'], $full['p50'] / $hit['p50']);
if ($external === null) {
    echo "Apache/nginx serving the file without PHP: run with a base URL to measure.\n";
}
