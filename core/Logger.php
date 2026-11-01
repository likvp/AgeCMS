<?php
declare(strict_types=1);

namespace AgeCMS;

/** Structured JSON lines in data/logs/mcp_agent.json, rotated at 5 MB. */
final class Logger
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @param array<string,mixed> $context */
    public static function log(string $level, string $event, array $context = []): void
    {
        $dir = AGECMS_DATA . '/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . '/mcp_agent.json';
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.1');
        }
        $line = json_encode(['ts' => ag_now(), 'level' => $level, 'event' => $event] + $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Like log(), but one and the same event (same name, same $key) is written at most once per $every seconds. A condition that
     * repeats on every request (a blocked module) would otherwise fill the log. The line that does get written carries
     * "skipped": how many identical events were held back since the previous one. A different $key is written at once.
     *
     * @param array<string,mixed> $context
     */
    public static function throttled(string $key, string $level, string $event, array $context = [], int $every = 3600, ?int $now = null): void
    {
        $now ??= time();
        $dir = AGECMS_DATA . '/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $handle = @fopen($dir . '/throttle.json', 'c+');
        if ($handle === false) {
            self::log($level, $event, $context);
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $state = json_decode((string)stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];
            $id = $event . ':' . $key;
            $entry = is_array($state[$id] ?? null) ? $state[$id] : ['at' => 0, 'skipped' => 0];
            if ($now - (int)$entry['at'] < $every && $now >= (int)$entry['at']) {
                $entry['skipped'] = (int)$entry['skipped'] + 1;
                $state[$id] = $entry;
            } else {
                self::log($level, $event, $context + ((int)$entry['skipped'] > 0 ? ['skipped' => (int)$entry['skipped']] : []));
                $state[$id] = ['at' => $now, 'skipped' => 0];
                if (count($state) > 200) {
                    uasort($state, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
                    $state = array_slice($state, 0, 100, true);
                }
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE) ?: '{}');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** A module that does not load: written once an hour per module and reason, with the number of requests that hit it meanwhile. */
    public static function moduleBlocked(string $module, string $reason): void
    {
        self::throttled($module . ':' . substr(sha1($reason), 0, 8), 'warning', 'module.blocked', ['module' => $module, 'reason' => $reason]);
    }
}
