<?php
declare(strict_types=1);

use AgeCMS\{Auth, Config, Database, Totp};

require_once __DIR__ . '/support/HttpCase.php';

/** Two-step sign-in with an authenticator app (E9). */
final class TwoFactorTest extends HttpCase
{
    private const FORM = ['Content-Type' => 'application/x-www-form-urlencoded'];

    private function post(array $data): array
    {
        return $this->http('POST', '/admin/index.php', http_build_query($data), self::FORM, true);
    }

    private function csrfOf(string $body): string
    {
        preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }

    /** Signs in with the password only and returns the response (the code form when the user has a second step). */
    private function password(string $user = 'admin', string $password = 'Passw0rd-test-1'): array
    {
        $this->post(['action' => 'login', 'username' => $user, 'password' => $password]);
        return $this->http('GET', '/admin/', null, [], true);
    }

    /** @return array{secret:string,codes:list<string>,csrf:string} */
    private function enable(): array
    {
        $csrf = $this->csrfOf($this->password()['body']);
        $start = $this->post(['action' => 'totp_start', 'csrf' => $csrf])['body'];
        preg_match('~<code id="totp-secret">([A-Z2-7 ]+)</code>~', $start, $m);
        $secret = str_replace(' ', '', $m[1] ?? '');
        $done = $this->post(['action' => 'totp_enable', 'csrf' => $csrf, 'code' => Totp::code($secret, intdiv(time(), 30))])['body'];
        preg_match('~id="recovery-codes">([^<]+)</pre>~', $done, $c);
        return ['secret' => $secret, 'codes' => array_values(array_filter(explode("\n", trim($c[1] ?? '')))), 'csrf' => $csrf];
    }

    private function signOut(string $csrf): void
    {
        $this->post(['action' => 'logout', 'csrf' => $csrf]);
    }

    public function testTotpMatchesTheStandardAndRefusesReplay(): void
    {
        // RFC 6238 test vectors (SHA-1, secret "12345678901234567890").
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('94287082', Totp::code($secret, intdiv(59, 30), 8));
        $this->assertSame('07081804', Totp::code($secret, intdiv(1111111109, 30), 8));
        $this->assertSame('287082', Totp::code($secret, 1));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));

        $now = 1_700_000_000;
        $step = intdiv($now, 30);
        $good = Totp::code($secret, $step);
        $this->assertSame($step, Totp::verify($secret, $good, 0, $now));
        $this->assertSame($step, Totp::verify($secret, substr($good, 0, 3) . ' ' . substr($good, 3), 0, $now), 'spaces are ignored');
        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), 0, $now), 'one step of drift');
        $this->assertSame(null, Totp::verify($secret, Totp::code($secret, $step - 2), 0, $now), 'two steps back is too old');
        $this->assertSame(null, Totp::verify($secret, Totp::code($secret, $step + 2), 0, $now));
        $this->assertSame(null, Totp::verify($secret, $good, $step, $now), 'the same step cannot be used again');
        $this->assertSame(null, Totp::verify($secret, '12345', 0, $now));
        $this->assertSame(null, Totp::verify($secret, 'abcdef', 0, $now));
        $this->assertSame(null, Totp::verify($secret, '', 0, $now));
    }

    public function testSecretsAreEncryptedInTheDatabase(): void
    {
        $this->freshSite();
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret));
        $sealed = Totp::seal($secret);
        $this->assertNotContains($secret, $sealed);
        $this->assertSame($secret, Totp::open($sealed));
        $this->assertSame('', Totp::open('garbage'));
        $this->assertSame('', Totp::open(substr($sealed, 0, -4) . 'AAAA'), 'a changed value is not accepted');
        $this->assertTrue($sealed !== Totp::seal($secret), 'every sealing is different');
    }

    public function testEnablingNeedsAGoodCodeAndGivesRecoveryCodes(): void
    {
        $this->boot();
        $csrf = $this->csrfOf($this->password()['body']);
        $start = $this->post(['action' => 'totp_start', 'csrf' => $csrf])['body'];
        $this->assertContains('otpauth://totp/', $start);
        $this->assertContains('id="totp-secret"', $start);
        preg_match('~<code id="totp-secret">([A-Z2-7 ]+)</code>~', $start, $m);
        $secret = str_replace(' ', '', $m[1]);

        $wrong = $this->post(['action' => 'totp_enable', 'csrf' => $csrf, 'code' => '000000'])['body'];
        $this->assertContains('Код не подошёл', $wrong);
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM users WHERE totp_enabled_at IS NOT NULL"), 'a wrong code turns nothing on');

        $done = $this->post(['action' => 'totp_enable', 'csrf' => $csrf, 'code' => Totp::code($secret, intdiv(time(), 30))])['body'];
        $this->assertContains('Двухфакторный вход включён', $done);
        preg_match('~id="recovery-codes">([^<]+)</pre>~', $done, $c);
        $codes = array_values(array_filter(explode("\n", trim($c[1]))));
        $this->assertSame(8, count($codes));
        $this->assertTrue(preg_match('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $codes[0]) === 1);
        $stored = (string)Database::value("SELECT totp_secret FROM users WHERE username = 'admin'");
        $this->assertNotContains($secret, $stored, 'the secret is encrypted');
        $this->assertSame(8, (int)Database::value('SELECT COUNT(*) FROM totp_recovery'));
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM totp_recovery WHERE code_hash = ?', [$codes[0]]), 'recovery codes are stored as hashes');
    }

    public function testThePasswordAloneIsNotEnough(): void
    {
        $this->boot();
        $two = $this->enable();
        $this->signOut($two['csrf']);

        $page = $this->password();
        $this->assertContains('name="code"', $page['body'], 'the code form');
        $this->assertNotContains('class="side"', $page['body'], 'no admin yet');
        $this->assertNotContains('class="side"', $this->http('GET', '/admin/?s=users', null, [], true)['body'], 'no section opens');
        $api = $this->http('POST', '/admin/api.php', json_encode(['action' => 'get_site_structure']), ['Content-Type' => 'application/json'], true);
        $this->assertSame(401, $api['status'], 'the half-open session is not a session for the API either');

        $bad = $this->post(['action' => 'login_2fa', 'code' => '123456']);
        $this->assertContains('Код не подошёл', $bad['body']);
        $this->assertNotContains('class="side"', $this->http('GET', '/admin/', null, [], true)['body']);

        $ok = $this->post(['action' => 'login_2fa', 'code' => Totp::code($two['secret'], intdiv(time(), 30) + 1)]);
        $this->assertContains('class="side"', $this->http('GET', '/admin/', null, [], true)['body'], 'the right code opens the admin');
    }

    public function testACodeWorksOnlyOnce(): void
    {
        $this->boot();
        $two = $this->enable();
        $this->signOut($two['csrf']);
        $this->password();
        // The step used to turn the second step on is spent already; the next one is fresh.
        $code = Totp::code($two['secret'], intdiv(time(), 30) + 1);
        $this->post(['action' => 'login_2fa', 'code' => $code]);
        $csrf = $this->csrfOf($this->http('GET', '/admin/', null, [], true)['body']);
        $this->assertTrue($csrf !== '');
        $this->signOut($csrf);
        $this->password();
        $again = $this->post(['action' => 'login_2fa', 'code' => $code]);
        $this->assertContains('Код не подошёл', $again['body'], 'the same code a second time');
    }

    public function testRecoveryCodesWorkOnceEach(): void
    {
        $this->boot();
        $two = $this->enable();
        $this->signOut($two['csrf']);
        $this->password();
        $this->post(['action' => 'login_2fa', 'code' => strtoupper($two['codes'][0])]);
        $page = $this->http('GET', '/admin/', null, [], true)['body'];
        $this->assertContains('class="side"', $page, 'a recovery code signs in (case does not matter)');
        $this->signOut($this->csrfOf($page));
        $this->password();
        $this->assertContains('Код не подошёл', $this->post(['action' => 'login_2fa', 'code' => $two['codes'][0]])['body'], 'used once');
        $this->post(['action' => 'login_2fa', 'code' => $two['codes'][1]]);
        $this->assertContains('class="side"', $this->http('GET', '/admin/', null, [], true)['body'], 'the next one works');
        $this->assertSame(6, Auth::recoveryLeft((int)Database::value("SELECT id FROM users WHERE username = 'admin'")));
    }

    public function testWrongCodesAreLimitedAndEndTheAttempt(): void
    {
        $this->boot();
        $two = $this->enable();
        $this->signOut($two['csrf']);
        $this->password();
        for ($i = 0; $i < 5; $i++) {
            $this->assertContains('Код не подошёл', $this->post(['action' => 'login_2fa', 'code' => sprintf('%06d', 100000 + $i)])['body']);
        }
        $locked = $this->post(['action' => 'login_2fa', 'code' => Totp::code($two['secret'], intdiv(time(), 30) + 1)]);
        $this->assertNotContains('class="side"', $this->http('GET', '/admin/', null, [], true)['body'], 'even the right code does not work after five wrong ones');
        $this->assertContains('Too many wrong codes', $locked['body']);
    }

    public function testTheSecondFactorProtectsTokensDisablingAndReset(): void
    {
        $this->boot();
        $two = $this->enable();
        // an agent cannot turn the password into a token for a user with a second step
        $issue = $this->http('POST', '/admin/api.php', json_encode(['action' => 'issue_token', 'username' => 'admin', 'password' => 'Passw0rd-test-1', 'name' => 'agent-x']), ['Content-Type' => 'application/json']);
        $this->assertSame(403, $issue['status']);
        $this->assertContains('two_factor', $issue['body']);
        $this->assertSame(0, (int)Database::value("SELECT COUNT(*) FROM api_tokens WHERE name = 'agent-x'"));

        // turning it off needs the password
        $csrf = $this->csrfOf($this->http('GET', '/admin/?s=users', null, [], true)['body']);
        $this->assertContains('Неверный пароль', $this->post(['action' => 'totp_disable', 'csrf' => $csrf, 'current_password' => 'wrong'])['body']);
        $this->assertSame(1, (int)Database::value("SELECT COUNT(*) FROM users WHERE totp_enabled_at IS NOT NULL"));

        // an administrator resets someone else's second step and ends that person's sessions
        $id = Auth::createUser('anna', 'long-enough-pass-1', 'editor');
        Database::write(fn(PDO $pdo) => $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = 0 WHERE id = ?")->execute([Totp::seal(Totp::generateSecret()), gmdate('c'), $id]));
        $epoch = (int)Database::value('SELECT pwd_epoch FROM users WHERE id = ?', [$id]);
        $users = $this->http('GET', '/admin/?s=users', null, [], true)['body'];
        $this->assertContains('Сбросить 2 шага', $users);
        $this->post(['action' => 'user_totp_reset', 'csrf' => $csrf, 'id' => (string)$id]);
        $this->assertSame(0, (int)Database::value('SELECT COUNT(*) FROM users WHERE id = ? AND totp_enabled_at IS NOT NULL', [$id]));
        $this->assertSame($epoch + 1, (int)Database::value('SELECT pwd_epoch FROM users WHERE id = ?', [$id]));

        $this->assertContains('Двухфакторный вход выключен', $this->post(['action' => 'totp_disable', 'csrf' => $csrf, 'current_password' => 'Passw0rd-test-1'])['body']);
        $this->signOut($csrf);
        $this->assertContains('class="side"', $this->password()['body'], 'after turning it off the password is enough again');
    }

    public function testEditorsCannotResetTheSecondStepOfOthers(): void
    {
        $this->boot();
        $id = Auth::createUser('boss2', 'long-enough-pass-1', 'admin');
        Database::write(fn(PDO $pdo) => $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = 0 WHERE id = ?")->execute([Totp::seal(Totp::generateSecret()), gmdate('c'), $id]));
        Auth::createUser('editor3', 'long-enough-pass-1', 'editor');
        $csrf = $this->csrfOf($this->password('editor3', 'long-enough-pass-1')['body']);
        $this->post(['action' => 'user_totp_reset', 'csrf' => $csrf, 'id' => (string)$id]);
        $this->assertSame(1, (int)Database::value('SELECT COUNT(*) FROM users WHERE id = ? AND totp_enabled_at IS NOT NULL', [$id]));
    }
}
