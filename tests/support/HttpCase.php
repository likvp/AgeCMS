<?php
declare(strict_types=1);

/** Starts the real site on `php -S` against the test sandbox and talks to it over HTTP. */
abstract class HttpCase extends TestCase
{
    /** @var resource|null */
    protected $proc = null;
    protected int $port = 0;
    protected string $jar = '';

    public function __destruct()
    {
        $this->stop();
    }

    protected function stop(): void
    {
        if (is_resource($this->proc)) {
            // With PHP_CLI_SERVER_WORKERS the server forks workers that outlive a terminated parent; stop them first,
            // or every run leaves processes behind until the system runs out of them.
            $pid = (int)(proc_get_status($this->proc)['pid'] ?? 0);
            if ($pid > 0) {
                exec('pkill -TERM -P ' . $pid . ' 2>/dev/null');
            }
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
    }

    protected function boot(array $options = []): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);

        $this->freshSite($options + ['site_url' => "http://127.0.0.1:{$this->port}", 'demo' => true]);
        $this->jar = TEST_TMP . '/cookies.txt';

        $root = dirname(__DIR__, 2);
        $env = [
            'AGECMS_DATA_DIR' => AGECMS_DATA, 'AGECMS_CACHE_DIR' => AGECMS_CACHE,
            'AGECMS_MODULES_DIR' => AGECMS_MODULES, 'AGECMS_UPLOADS_DIR' => AGECMS_UPLOADS,
            'PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => (string)getenv('PATH'),
        ];
        $this->proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $root, $root . '/tests/support/router.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $root, $env);
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1)) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('php -S did not start');
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    protected function http(string $method, string $path, ?string $body = null, array $headers = [], bool $cookies = false): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $out = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$out): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $out[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($cookies) {
            curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar]);
        }
        $text = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return ['status' => $status, 'headers' => $out, 'body' => $text];
    }
}
