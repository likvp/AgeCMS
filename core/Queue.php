<?php
declare(strict_types=1);

namespace AgeCMS;

use Throwable;

/**
 * Single-writer queue. Callers drop a signed job file into data/queue/ and whoever holds the
 * flock drains the directory in order, so parallel agents never contend for the SQLite write lock.
 * submit() hides the queue from callers: it enqueues, drains, and returns the job's result.
 */
final class Queue
{
    /** Operation name => [handler, permission]. */
    private const OPERATIONS = [
        'update_block_field' => ['updateBlockField', 'content.write'],
        'update_block_data' => ['updateBlockData', 'content.write'],
        'add_block' => ['addBlock', 'content.write'],
        'move_block' => ['moveBlock', 'content.write'],
        'duplicate_block' => ['duplicateBlock', 'content.write'],
        'delete_block' => ['deleteBlock', 'content.write'],
        'create_page' => ['createPage', 'content.write'],
        'update_page' => ['updatePage', 'content.write'],
        'rollback' => ['rollback', 'content.write'],
        'rename_page' => ['renamePage', 'content.write'],
        'delete_page' => ['deletePage', 'content.write'],
        'restore_page' => ['restorePage', 'content.write'],
        'purge_page' => ['purgePage', 'system.manage'],
        'set_menu' => ['setMenu', 'content.write'],
        'set_settings' => ['setSettings', 'system.manage'],
    ];

    public static function dir(): string
    {
        return AGECMS_DATA . '/queue';
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed> the operation result
     * @throws AgeCMSException
     */
    public static function submit(Actor $actor, string $op, array $args, int $timeoutMs = 10000): array
    {
        $id = self::enqueue($actor, $op, $args);
        self::drain($timeoutMs);

        $resultFile = self::dir() . "/done/{$id}.json";
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (!is_file($resultFile)) {
            if (microtime(true) > $deadline) {
                throw new AgeCMSException("Job {$id} is queued but was not processed in time");
            }
            usleep(20000);
            self::drain(200);
        }
        $result = json_decode((string)file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
        @unlink($resultFile);
        if (!$result['ok']) {
            throw Exceptions::rebuild((string)$result['kind'], (string)$result['message']);
        }
        return $result['result'];
    }

    /** @param array<string,mixed> $args */
    public static function enqueue(Actor $actor, string $op, array $args): string
    {
        if (!isset(self::OPERATIONS[$op])) {
            throw new ValidationException(["Unknown operation '{$op}'"]);
        }
        $dir = self::dir();
        foreach ([$dir, $dir . '/done', $dir . '/failed'] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
                throw new AgeCMSException("Cannot create {$d}");
            }
        }
        $id = sprintf('%d-%s', (int)(microtime(true) * 1000000), bin2hex(random_bytes(4)));
        $job = ['id' => $id, 'op' => $op, 'args' => $args, 'actor' => $actor->toArray(), 'created_at' => ag_now()];
        $job['sig'] = self::signature($job);

        $tmp = "{$dir}/.tmp-{$id}";
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        rename($tmp, "{$dir}/{$id}.json");
        return $id;
    }

    /**
     * Processes pending jobs in order. Returns how many ran, 0 when another process holds the lock.
     * Waits up to $waitMs for the lock.
     */
    public static function drain(int $waitMs = 0, int $limit = 100): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return 0;
        }
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false) {
            return 0;
        }
        $deadline = microtime(true) + $waitMs / 1000;
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);
                return 0;
            }
            usleep(10000);
        }

        $ran = 0;
        try {
            $files = glob($dir . '/*.json') ?: [];
            sort($files, SORT_STRING);
            foreach (array_slice($files, 0, $limit) as $file) {
                self::process($file);
                $ran++;
            }
            self::cleanup();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $ran;
    }

    private static function process(string $file): void
    {
        $dir = self::dir();
        $job = json_decode((string)@file_get_contents($file), true);
        if (!is_array($job) || !isset($job['id'], $job['op'], $job['sig'])) {
            @rename($file, $dir . '/failed/' . basename($file));
            Logger::log('error', 'queue.malformed', ['file' => basename($file)]);
            return;
        }
        $id = (string)$job['id'];

        $outcome = ['ok' => false, 'kind' => 'error', 'message' => 'Unknown failure'];
        try {
            $sig = (string)$job['sig'];
            unset($job['sig']);
            if (!hash_equals(self::signature($job), $sig)) {
                throw new ForbiddenException('Job signature mismatch, the file was not created by this site');
            }
            $outcome = ['ok' => true, 'result' => self::execute($job)];
        } catch (AgeCMSException $e) {
            $outcome = ['ok' => false, 'kind' => $e->kind(), 'message' => $e->getMessage()];
            Logger::log('warning', 'queue.job_rejected', ['id' => $id, 'op' => $job['op'] ?? '', 'kind' => $e->kind(), 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $outcome = ['ok' => false, 'kind' => 'error', 'message' => 'Internal error'];
            Logger::log('error', 'queue.job_failed', ['id' => $id, 'op' => $job['op'] ?? '', 'error' => $e->getMessage()]);
        }

        file_put_contents("{$dir}/done/{$id}.json", json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), LOCK_EX);
        @unlink($file);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function execute(array $job): array
    {
        [$method, $permission] = self::OPERATIONS[$job['op']] ?? throw new ValidationException(["Unknown operation '{$job['op']}'"]);
        $actor = Actor::fromArray($job['actor']);
        Security::requirePermission($actor, $permission);
        $a = $job['args'];

        return match ($job['op']) {
            'update_block_field' => Content::updateBlockField($actor, (string)$a['slug'], (string)$a['block_id'], (string)$a['field_path'], $a['value'] ?? ''),
            'update_block_data' => Content::updateBlockData($actor, (string)$a['slug'], (string)$a['block_id'], (array)($a['payload'] ?? [])),
            'add_block' => Content::addBlock($actor, (string)$a['slug'], (string)$a['type'], isset($a['after']) ? (string)$a['after'] : null, isset($a['data']) ? (array)$a['data'] : null),
            'move_block' => Content::moveBlock($actor, (string)$a['slug'], (string)$a['block_id'], (string)($a['after'] ?? '')),
            'duplicate_block' => Content::duplicateBlock($actor, (string)$a['slug'], (string)$a['block_id']),
            'delete_block' => Content::deleteBlock($actor, (string)$a['slug'], (string)$a['block_id']),
            'create_page' => Content::createPage($actor, (string)$a['slug'], (array)($a['fields'] ?? [])),
            'update_page' => Content::updatePage($actor, (string)$a['slug'], (array)($a['fields'] ?? [])),
            'rename_page' => Content::renamePage($actor, (string)$a['slug'], (string)$a['new_slug']),
            'delete_page' => Content::deletePage($actor, (string)$a['slug']),
            'restore_page' => Content::restorePage($actor, (string)$a['slug']),
            'purge_page' => Content::purgePage($actor, (string)$a['slug']),
            'rollback' => Content::rollback($actor, (string)$a['slug'], (int)$a['revision_id']),
            'set_menu' => Content::setMenu($actor, (string)$a['name'], (array)($a['items'] ?? [])),
            'set_settings' => Content::setSettings($actor, (array)($a['values'] ?? [])),
        };
    }

    /** @param array<string,mixed> $job */
    private static function signature(array $job): string
    {
        unset($job['sig']);
        return Security::sign('queue', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Drops result files nobody collected for a day. */
    private static function cleanup(): void
    {
        $cutoff = time() - 86400;
        foreach (glob(self::dir() . '/done/*.json') ?: [] as $f) {
            if (filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
