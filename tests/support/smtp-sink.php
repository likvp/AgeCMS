<?php
declare(strict_types=1);

// Minimal SMTP server for tests: php smtp-sink.php <port> <file>. Accepts one message, writes it to <file>, exits.
[$script, $port, $file] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
    fwrite(STDERR, "cannot listen: {$error}\n");
    exit(1);
}
touch($file . '.ready');
$conn = stream_socket_accept($server, 15);
if ($conn === false) {
    exit(2);
}
$say = static fn(string $line) => fwrite($conn, $line . "\r\n");
$log = [];
$say('220 sink ready');
$data = false;
$body = '';
while (($line = fgets($conn)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($data) {
        if ($line === '.') {
            $data = false;
            $say('250 queued');
            continue;
        }
        $body .= (str_starts_with($line, '..') ? substr($line, 1) : $line) . "\n";
        continue;
    }
    $log[] = $line;
    $cmd = strtoupper(substr($line, 0, 4));
    if ($cmd === 'EHLO') {
        fwrite($conn, "250-sink\r\n250 AUTH LOGIN\r\n");
    } elseif ($line === 'AUTH LOGIN') {
        $say('334 VXNlcm5hbWU6');
    } elseif ($cmd === 'DATA') {
        $data = true;
        $say('354 go on');
    } elseif ($cmd === 'QUIT') {
        $say('221 bye');
        break;
    } elseif (preg_match('/^[A-Za-z0-9+\/=]+$/', $line) === 1 && count($log) >= 3 && str_starts_with($log[count($log) - 2] ?? '', 'AUTH LOGIN')) {
        $say('334 UGFzc3dvcmQ6');
    } elseif (preg_match('/^[A-Za-z0-9+\/=]+$/', $line) === 1) {
        $say('235 ok');
    } else {
        $say('250 ok');
    }
}
file_put_contents($file . '.part', implode("\n", $log) . "\n---\n" . $body);
rename($file . '.part', $file); // appears whole or not at all
