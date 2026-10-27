<?php
declare(strict_types=1);

use AgeCMS\{Auth, Config, Database, ForbiddenException, Logger, Mailer, ValidationException, AgeCMSException};

/** Password recovery by e-mail: links, limits, session epoch, the mail transports. */
final class RecoveryTest extends TestCase
{
    private function outbox(): array
    {
        return glob(AGECMS_DATA . '/outbox/*.eml') ?: [];
    }

    private function useLogMail(): void
    {
        Config::set('mail', ['transport' => 'log', 'from' => 'noreply@example.test', 'from_name' => 'Тест', 'smtp' => ['host' => '', 'port' => 465, 'secure' => 'ssl', 'user' => '', 'pass' => '']]);
    }

    /** @return array{headers:string,text:string,link:string} */
    private function lastMail(): array
    {
        $files = $this->outbox();
        sort($files);
        $raw = (string)file_get_contents((string)end($files));
        [$headers, $body] = explode("\r\n\r\n", $raw, 2);
        $text = (string)base64_decode(str_replace("\r\n", '', $body));
        preg_match('~https?://\S+token=([A-Za-z0-9_-]+)~', $text, $m);
        return ['headers' => $headers, 'text' => $text, 'link' => $m[0] ?? '', 'token' => $m[1] ?? ''];
    }

    public function testEmailsAreValidatedAndUnique(): void
    {
        $this->freshSite();
        $this->assertSame('', Auth::normalizeEmail('  '));
        $this->assertSame('a@b.example', Auth::normalizeEmail(' A@B.example '));
        $this->assertThrows(ValidationException::class, fn() => Auth::normalizeEmail('not-an-email'), 'not valid');
        $id = Auth::createUser('anna', 'long-enough-pass', 'editor', 'Anna@Example.test');
        $this->assertSame('anna@example.test', Database::value('SELECT email FROM users WHERE id = ?', [$id]));
        $this->assertThrows(ValidationException::class, fn() => Auth::createUser('bob', 'long-enough-pass', 'editor', 'anna@example.test'), 'already belongs');
        $two = Auth::createUser('bob', 'long-enough-pass', 'editor');
        $this->assertThrows(ValidationException::class, fn() => Auth::setEmail($two, 'ANNA@example.test'), 'already belongs');
        Auth::setEmail($two, '');
        Auth::createUser('carl', 'long-enough-pass', 'editor');
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM users WHERE email = 'x'"), 'several users may have no address');
        $this->assertThrows(ValidationException::class, fn() => Auth::changeOwnEmail($id, 'wrong-password', 'new@example.test'), 'wrong');
        Auth::changeOwnEmail($id, 'long-enough-pass', 'new@example.test');
        $this->assertSame('new@example.test', Database::value('SELECT email FROM users WHERE id = ?', [$id]));
    }

    public function testResetLinkWorksOnceAndEndsOtherSessions(): void
    {
        $this->freshSite();
        $this->useLogMail();
        $id = Auth::createUser('anna', 'long-enough-pass', 'editor', 'anna@example.test');

        Auth::requestReset('ANNA');
        $this->assertSame(1, count($this->outbox()));
        $mail = $this->lastMail();
        $this->assertContains('To: <anna@example.test>', $mail['headers']);
        $this->assertContains('=?UTF-8?B?', $mail['headers'], 'a Cyrillic subject is encoded');
        $this->assertContains('https://example.test/admin/?s=reset&token=', $mail['link']);
        $this->assertContains('Здравствуйте, anna', $mail['text']);
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$mail['token']]), 'only the hash is stored');
        $this->assertTrue(Auth::resetTokenValid($mail['token']));

        $epoch = (int)Database::value('SELECT pwd_epoch FROM users WHERE id = ?', [$id]);
        $this->assertThrows(ValidationException::class, fn() => Auth::completeReset($mail['token'], 'short'), 'at least 10');
        $this->assertTrue(Auth::resetTokenValid($mail['token']), 'a weak password does not burn the link');
        $this->assertSame('anna', Auth::completeReset($mail['token'], 'a-brand-new-password'));
        $this->assertTrue(password_verify('a-brand-new-password', (string)Database::value('SELECT password_hash FROM users WHERE id = ?', [$id])));
        $this->assertSame($epoch + 1, (int)Database::value('SELECT pwd_epoch FROM users WHERE id = ?', [$id]), 'other sessions end');
        $this->assertSame(false, Auth::resetTokenValid($mail['token']));
        $this->assertThrows(ValidationException::class, fn() => Auth::completeReset($mail['token'], 'another-new-password'), 'not valid');
    }

    public function testExpiredAndReplacedLinksAreRefused(): void
    {
        $this->freshSite();
        $this->useLogMail();
        Auth::createUser('anna', 'long-enough-pass', 'editor', 'anna@example.test');
        Auth::requestReset('anna@example.test');
        $first = $this->lastMail();
        Database::write(static fn(PDO $pdo) => $pdo->exec('UPDATE password_resets SET expires_at = ' . (time() - 1)));
        $this->assertSame(false, Auth::resetTokenValid($first['token']), 'an hour is the limit');
        $this->assertThrows(ValidationException::class, fn() => Auth::completeReset($first['token'], 'a-brand-new-password'), 'expired');

        Auth::requestReset('anna');
        $second = $this->lastMail();
        $this->assertTrue($first['token'] !== $second['token']);
        $this->assertSame(1, (int)Database::value('SELECT COUNT(*) FROM password_resets'), 'a new link replaces the old ones');
    }

    public function testUnknownUsersAndMissingAddressesGetNoMailAndNoHint(): void
    {
        $this->freshSite();
        $this->useLogMail();
        Auth::createUser('nomail', 'long-enough-pass', 'editor');
        Auth::requestReset('ghost');
        Auth::requestReset('nomail');
        Auth::requestReset('');
        $this->assertSame([], $this->outbox(), 'no mail for unknown users or users without an address');
    }

    public function testRequestsAreRateLimited(): void
    {
        $this->freshSite();
        $this->useLogMail();
        Auth::createUser('anna', 'long-enough-pass', 'editor', 'anna@example.test');
        for ($i = 0; $i < 5; $i++) {
            Auth::requestReset('anna');
        }
        $this->assertSame(3, count($this->outbox()), 'three links per account per hour');
        $this->assertThrows(ForbiddenException::class, fn() => Auth::requestReset('anna'), 'Too many requests');
    }

    public function testPasswordChangeEndsOtherSessionsButNotTheCurrentOne(): void
    {
        $this->freshSite();
        $id = (int)Database::value("SELECT id FROM users WHERE username = 'admin'");
        Auth::resetPassword($id, 'reset-by-admin-1');
        $this->assertSame(1, (int)Database::value('SELECT pwd_epoch FROM users WHERE id = ?', [$id]));
        Auth::changeOwnPassword($id, 'reset-by-admin-1', 'new-long-password');
        $this->assertSame(2, (int)Database::value('SELECT pwd_epoch FROM users WHERE id = ?', [$id]));
    }

    public function testMailerRefusesBadInput(): void
    {
        $this->freshSite();
        $this->useLogMail();
        $this->assertThrows(AgeCMSException::class, fn() => Mailer::send("a@b.test\r\nBcc: x@y.test", 'Subject', 'Body'), 'not valid');
        Config::set('mail', ['transport' => 'smtp', 'from' => 'noreply@example.test', 'from_name' => '', 'smtp' => ['host' => '', 'port' => 465, 'secure' => 'ssl', 'user' => '', 'pass' => '']]);
        $this->assertSame(false, Mailer::configured(), 'SMTP without a host is not configured');
        $this->assertThrows(AgeCMSException::class, fn() => Mailer::send('a@b.test', 'Subject', 'Body'), 'not configured');
        $this->assertSame('a***@example.test', Mailer::mask('anna@example.test'));
    }

    public function testSmtpTransportTalksToAServer(): void
    {
        $this->freshSite();
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $out = TEST_TMP . '/smtp-out.txt';
        $proc = proc_open([PHP_BINARY, __DIR__ . '/support/smtp-sink.php', (string)$port, $out], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50 && !is_file($out . '.ready'); $i++) {
                usleep(100000);
            }
            Config::set('mail', ['transport' => 'smtp', 'from' => 'noreply@example.test', 'from_name' => 'Тест', 'smtp' => ['host' => '127.0.0.1', 'port' => $port, 'secure' => '', 'user' => 'robot', 'pass' => 'secret']]);
            Mailer::send('anna@example.test', 'Смена пароля', "Строка один\n.точка в начале\nhttps://example.test/x\n");
            for ($i = 0; $i < 50 && !is_file($out); $i++) {
                usleep(100000);
            }
            $got = (string)file_get_contents($out);
            $this->assertContains('MAIL FROM:<noreply@example.test>', $got);
            $this->assertContains('RCPT TO:<anna@example.test>', $got);
            $this->assertContains('AUTH LOGIN', $got);
            $this->assertContains('Subject: =?UTF-8?B?', $got);
            $this->assertContains('Content-Transfer-Encoding: base64', $got);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testAnOlderDatabaseIsUpgradedOnFirstUse(): void
    {
        $this->freshSite();
        $id = Auth::createUser('anna', 'long-enough-pass', 'editor');
        // Put the database back into the shape it had before recovery by e-mail existed.
        Database::write(static function (PDO $pdo): void {
            $pdo->exec('DROP INDEX users_email');
            $pdo->exec('DROP TABLE password_resets');
            $pdo->exec('ALTER TABLE users DROP COLUMN email');
            $pdo->exec('ALTER TABLE users DROP COLUMN pwd_epoch');
        });
        Database::pdo()->exec('PRAGMA user_version = 1');
        Database::reset();

        $this->assertSame(AgeCMS\Schema::latest(), (int)Database::value('PRAGMA user_version'), 'the first query upgrades the schema');
        $this->assertSame('anna', Database::value('SELECT username FROM users WHERE id = ?', [$id]), 'existing data survives');
        $this->assertSame('', Database::value('SELECT email FROM users WHERE id = ?', [$id]));
        $this->assertSame(0, (int)Database::value('SELECT pwd_epoch FROM users WHERE id = ?', [$id]));
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM password_resets'));
        // a second open changes nothing
        Database::reset();
        $this->assertSame(AgeCMS\Schema::latest(), (int)Database::value('PRAGMA user_version'));
    }
}
