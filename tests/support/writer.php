<?php
declare(strict_types=1);

// Helper for QueueTest: one process that submits N updates through the write queue.
define('AGECMS_MODULES', getenv('AGECMS_MODULES_DIR'));
require __DIR__ . '/../../core/bootstrap.php';

use AgeCMS\{Actor, Queue};

[$_, $worker, $count] = $argv;
$actor = Actor::agent('worker' . $worker, 'editor', 'worker-' . $worker);
try {
    for ($i = 0; $i < (int)$count; $i++) {
        Queue::submit($actor, 'update_block_field', [
            'slug' => 'par' . (((int)$worker + $i) % 4),
            'block_id' => 'hero_1',
            'field_path' => 'h1',
            'value' => "worker {$worker} write {$i}",
        ]);
    }
    echo 'ok ' . $count;
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
    exit(1);
}
