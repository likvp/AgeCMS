<?php
declare(strict_types=1);

// Lifts the write freeze set by the circuit breaker. Look at the history first: php bin/verify-chain.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\CircuitBreaker;

$info = CircuitBreaker::frozenInfo();
if ($info === null) {
    echo "Writes are not frozen.\n";
    exit(0);
}
echo "Frozen since {$info['since']}: {$info['reason']}\n";
CircuitBreaker::unfreeze();
echo "Unfrozen.\n";
