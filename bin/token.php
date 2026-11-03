<?php
declare(strict_types=1);

// php bin/token.php create <name> [--role=editor|viewer|admin]   prints the token once
// php bin/token.php revoke <name>
// php bin/token.php freeze <name> [--hours=24] [--reason=...]      stops the token for a while
// php bin/token.php unfreeze <name>
// php bin/token.php block <name> [--reason=...]                    stops the token until unblocked
// php bin/token.php unblock <name>
// php bin/token.php role <name> <editor|viewer|admin>
// php bin/token.php freeze-all [--hours=24] [--reason=...] | unfreeze-all
// php bin/token.php list
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Auth, Database, Tokens};

$args = array_values(array_filter($argv, static fn($a) => !str_starts_with($a, '--')));
$opts = ['role' => 'editor', 'hours' => '24', 'reason' => ''];
foreach ($argv as $a) {
    if (preg_match('/^--(role|hours|reason)=(.*)$/s', $a, $m) === 1) {
        $opts[$m[1]] = $m[2];
    }
}
$idOf = static function (string $name): int {
    $id = Database::value('SELECT id FROM api_tokens WHERE name = ?', [$name]);
    if ($id === null) {
        throw new RuntimeException("No token named '{$name}'");
    }
    return (int)$id;
};
try {
    switch ($args[1] ?? '') {
        case 'create':
            $token = Auth::createToken($args[2] ?? '', $opts['role']);
            echo "Token for '{$args[2]}' (role {$opts['role']}), shown once:\n{$token}\n";
            break;
        case 'revoke':
            echo Auth::revokeToken($args[2] ?? '') ? "Revoked.\n" : "No active token with that name.\n";
            break;
        case 'freeze':
            Tokens::freeze($idOf($args[2] ?? ''), (int)$opts['hours'], $opts['reason']);
            echo "Frozen for {$opts['hours']} h.\n";
            break;
        case 'unfreeze':
            Tokens::unfreeze($idOf($args[2] ?? ''));
            echo "Unfrozen.\n";
            break;
        case 'block':
            Tokens::block($idOf($args[2] ?? ''), $opts['reason']);
            echo "Blocked until unblocked.\n";
            break;
        case 'unblock':
            Tokens::unblock($idOf($args[2] ?? ''));
            echo "Unblocked.\n";
            break;
        case 'role':
            Tokens::setRole($idOf($args[2] ?? ''), $args[3] ?? '');
            echo "Role is now {$args[3]}.\n";
            break;
        case 'freeze-all':
            Tokens::freezeAll((int)$opts['hours'], $opts['reason']);
            echo "Every agent token is frozen for {$opts['hours']} h.\n";
            break;
        case 'unfreeze-all':
            Tokens::unfreezeAll();
            echo "Agents unfrozen.\n";
            break;
        case 'list':
            if (($all = Tokens::allFrozen()) !== null) {
                echo "ALL AGENTS FROZEN until {$all['until']}" . ($all['reason'] !== '' ? ": {$all['reason']}" : '') . "\n";
            }
            foreach (Tokens::all() as $t) {
                $until = match ($t['status']) {
                    'frozen' => ' until ' . $t['frozen_until'],
                    'revoked' => ' ' . $t['revoked_at'],
                    default => '',
                };
                echo sprintf("%-24s %-7s %-13s created %s, last used %s%s, changes in 30 days %d%s\n", $t['name'], $t['role'], strtoupper($t['status']) . $until, $t['created_at'], $t['last_used_at'] ?? 'never', $t['last_ip'] !== '' ? ' from ' . $t['last_ip'] : '', $t['actions_30d'], $t['hold_reason'] !== '' && in_array($t['status'], ['frozen', 'blocked'], true) ? ', reason: ' . $t['hold_reason'] : '');
            }
            break;
        default:
            fwrite(STDERR, "Usage: php bin/token.php create <name> [--role=editor|viewer|admin] | revoke <name> | freeze <name> [--hours=24] [--reason=...] | unfreeze <name>\n"
                . "       | block <name> [--reason=...] | unblock <name> | role <name> <role> | freeze-all [--hours=24] [--reason=...] | unfreeze-all | list\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
