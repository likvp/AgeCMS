<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Housekeeping that cron would otherwise do: finish stuck jobs, send IndexNow pings, re-render pages
 * dropped from the cache. It runs after the response has gone out, when someone saves in the admin or
 * through the API, and on ordinary visits that reach PHP (at most once every 30 seconds). No cron needed.
 */
final class Background
{
    private const TICK_EVERY = 30;

    /** True when something is waiting: queued jobs, pings or pages to re-render. */
    public static function pending(): bool
    {
        $dir = Queue::dir();
        return (glob($dir . '/*.json') ?: []) !== [] || (glob($dir . '/ping/*.json') ?: []) !== [] || (glob($dir . '/rebuild/*.json') ?: []) !== [];
    }

    /**
     * Registers the work for the end of the request. $always skips the 30 second throttle, used after a save
     * so the page that was just changed is cached before the next visitor asks for it.
     */
    public static function afterResponse(bool $always = false): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(static function () use ($always): void {
            Http::finishResponse();
            try {
                self::run($always);
            } catch (Throwable $e) {
                Logger::log('error', 'background.failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * @return array{jobs:int,pings:int,rebuilt:int}|null null when nothing was due
     */
    public static function run(bool $always = false, float $budgetSeconds = 8.0): ?array
    {
        if (!is_dir(Queue::dir()) || !self::pending()) {
            return null;
        }
        $stamp = Queue::dir() . '/.tick';
        if (!$always) {
            $last = @filemtime($stamp);
            if ($last !== false && time() - $last < self::TICK_EVERY) {
                return null;
            }
        }
        @touch($stamp);
        $deadline = microtime(true) + $budgetSeconds;
        $jobs = Queue::drain(0, 200);
        $pings = Seo::flushPings();
        $rebuilt = Cache::processRebuildQueue(200, $deadline);
        return ['jobs' => $jobs, 'pings' => $pings, 'rebuilt' => $rebuilt];
    }
}
