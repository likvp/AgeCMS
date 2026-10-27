<?php
declare(strict_types=1);

// Recomputes the hash chain of the revisions ledger. Exit code 1 when it was tampered with.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

$result = AgeCMS\Revisions::verifyChain();
if ($result['ok']) {
    echo "Ledger intact: {$result['checked']} revisions checked.\n";
    exit(0);
}
echo "LEDGER BROKEN at revision #{$result['broken_at']} after {$result['checked']} valid ones.\n";
exit(1);
