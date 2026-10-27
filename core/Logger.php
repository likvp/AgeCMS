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
}
