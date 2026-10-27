<?php
declare(strict_types=1);

// php bin/token.php create <name> [--role=editor|viewer|admin]   prints the token once
// php bin/token.php revoke <name>
// php bin/token.php list
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Auth, Database};

$args = array_values(array_filter($argv, static fn($a) => !str_starts_with($a, '--')));
$role = 'editor';
foreach ($argv as $a) {
    if (str_starts_with($a, '--role=')) {
        $role = substr($a, 7);
    }
}
try {
    switch ($args[1] ?? '') {
        case 'create':
            $token = Auth::createToken($args[2] ?? '', $role);
            echo "Token for '{$args[2]}' (role {$role}), shown once:\n{$token}\n";
            break;
        case 'revoke':
            echo Auth::revokeToken($args[2] ?? '') ? "Revoked.\n" : "No active token with that name.\n";
            break;
        case 'list':
            foreach (Database::rows('SELECT name, role, created_at, last_used_at, revoked_at FROM api_tokens ORDER BY id') as $t) {
                echo sprintf("%-24s %-8s created %s, last used %s%s\n", $t['name'], $t['role'], $t['created_at'], $t['last_used_at'] ?? 'never', $t['revoked_at'] ? ', REVOKED ' . $t['revoked_at'] : '');
            }
            break;
        default:
            fwrite(STDERR, "Usage: php bin/token.php create <name> [--role=editor|viewer|admin] | revoke <name> | list\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
