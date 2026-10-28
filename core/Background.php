<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Housekeeping that cron would otherwise do: finish stuck jobs, send IndexNow pings, re-render pages
 * dropped from the cache, ask once a day whether a newer core is out. It runs after the response has gone out,
 * when someone saves in the admin or through the API, and on ordinary visits that reach PHP (at most once
 * every 30 seconds). No cron needed.
 */
final class Background
{
    private const TICK_EVERY = 30;
    /** Modules subscribe to this event to do scheduled work (mailings, syncs). The payload holds "time" and "deadline" (microtime to stop at). */
    public const TICK_EVENT = 'background.tick';

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
     * @return array{jobs:int,pings:int,rebuilt:int,ticked:bool}|null null when nothing was due
     */
    public static function run(bool $always = false, float $budgetSeconds = 8.0): ?array
    {
        $ticked = self::tick($budgetSeconds);
        try {
            Updates::checkIfDue();
        } catch (Throwable $e) {
            Logger::log('error', 'updates.failed', ['error' => $e->getMessage()]);
        }
        if (!is_dir(Queue::dir()) || !self::pending()) {
            return $ticked ? ['jobs' => 0, 'pings' => 0, 'rebuilt' => 0, 'ticked' => true] : null;
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
        return ['jobs' => $jobs, 'pings' => $pings, 'rebuilt' => $rebuilt, 'ticked' => $ticked];
    }

    /**
     * Raises background.tick for modules, at most once every 30 seconds and never twice at the same time
     * (an exclusive lock on the stamp file, so a slow listener is not started again by the next visit).
     * Declarative webhooks are not called for this event. Returns true when the event was raised.
     */
    public static function tick(float $budgetSeconds = 8.0): bool
    {
        $dir = Queue::dir();
        if (!is_dir($dir)) {
            return false;
        }
        $file = $dir . '/.tick-modules';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $last = (int)stream_get_contents($handle);
            if ($last > 0 && time() - $last < self::TICK_EVERY) {
                return false;
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string)time());
            fflush($handle);
            EventBus::trigger(self::TICK_EVENT, ['time' => time(), 'deadline' => microtime(true) + $budgetSeconds]);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
