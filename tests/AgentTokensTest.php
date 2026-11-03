<?php
declare(strict_types=1);

use AgeCMS\{Auth, Database, Tokens, ValidationException};

require_once __DIR__ . '/support/HttpCase.php';

final class AgentTokensTest extends HttpCase
{
    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function rest(string $token, array $payload): array
    {
        return $this->http('POST', '/admin/api.php', json_encode($payload), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function mcp(string $token): array
    {
        return $this->http('POST', '/admin/mcp.php', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
    }

    private function idOf(string $name): int
    {
        return (int)Database::value('SELECT id FROM api_tokens WHERE name = ?', [$name]);
    }

    private function adminLogin(): string
    {
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        preg_match('/name="csrf" value="([^"]+)"/', $this->http('GET', '/admin/', null, [], true)['body'], $m);
        return $m[1] ?? '';
    }

    private function form(string $query, array $data, string $csrf): array
    {
        return $this->http('POST', '/admin/index.php' . $query, http_build_query($data + ['csrf' => $csrf]), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
    }

    public function testFreezeBlockAndRevokeStopTheAgentAndTellItWhy(): void
    {
        $this->boot();
        $token = Auth::createToken('bot-a', 'editor', 0);
        $id = $this->idOf('bot-a');
        $this->assertSame(200, $this->rest($token, ['action' => 'get_site_structure'])['status']);
        $this->assertSame('127.0.0.1', (string)Database::value('SELECT last_ip FROM api_tokens WHERE id = ?', [$id]), 'the owner sees where the agent calls from');

        Tokens::freeze($id, 1, 'проверяем правки');
        $frozen = $this->rest($token, ['action' => 'get_site_structure']);
        $this->assertSame(403, $frozen['status'], 'held on purpose: 403, not "log in again"');
        $this->assertContains('token_frozen', $frozen['body']);
        $this->assertContains('проверяем правки', $frozen['body'], 'the agent hears the reason');
        $wait = (int)($frozen['headers']['retry-after'] ?? 0);
        $this->assertTrue($wait > 3000 && $wait <= 3600, 'Retry-After says when to come back: ' . $wait);
        $this->assertSame(403, $this->mcp($token)['status'], 'MCP is closed too');
        $this->assertContains('token_frozen', $this->mcp($token)['body']);
        $this->assertSame('frozen', Tokens::find($id)['status']);

        Tokens::unfreeze($id);
        $this->assertSame(200, $this->rest($token, ['action' => 'get_site_structure'])['status']);

        // a freeze ends by itself
        Tokens::freeze($id, 24);
        Database::write(static fn(PDO $pdo) => $pdo->exec("UPDATE api_tokens SET frozen_until = '2020-01-01T00:00:00Z' WHERE name = 'bot-a'"));
        $this->assertSame(200, $this->rest($token, ['action' => 'get_site_structure'])['status']);
        $this->assertSame('active', Tokens::find($id)['status']);

        Tokens::block($id, 'ломал меню');
        $blocked = $this->rest($token, ['action' => 'get_site_structure']);
        $this->assertSame(403, $blocked['status']);
        $this->assertContains('token_blocked', $blocked['body']);
        $this->assertContains('ломал меню', $blocked['body']);
        $this->assertSame(false, isset($blocked['headers']['retry-after']), 'a block has no end');
        $this->assertThrows(ValidationException::class, static fn() => Tokens::freeze($id, 1), 'заблокирован');
        Tokens::unblock($id);
        $this->assertSame(200, $this->rest($token, ['action' => 'get_site_structure'])['status']);

        // the emergency switch stops every token, even one issued while it is on
        Tokens::freezeAll(2, 'авария');
        $late = Auth::createToken('bot-b', 'viewer', 0);
        foreach ([$token, $late] as $t) {
            $r = $this->rest($t, ['action' => 'get_site_structure']);
            $this->assertSame(403, $r['status']);
            $this->assertContains('agents_frozen', $r['body']);
            $this->assertContains('авария', $r['body']);
        }
        Tokens::unfreezeAll();
        $this->assertSame(null, Tokens::allFrozen());
        $this->assertSame(200, $this->rest($late, ['action' => 'get_site_structure'])['status']);

        // a lower role takes effect on the next request
        Tokens::setRole($id, 'viewer');
        $this->assertSame(403, $this->rest($token, ['action' => 'create_page', 'slug' => 'nope', 'fields' => ['title' => 'Нет']])['status']);

        Tokens::revoke($id);
        $gone = $this->rest($token, ['action' => 'get_site_structure']);
        $this->assertSame(401, $gone['status']);
        $this->assertContains('token_revoked', $gone['body']);
        $this->assertThrows(ValidationException::class, static fn() => Tokens::block($id), 'отозван');

        $journal = implode("\n", array_column(Database::rows("SELECT action_desc FROM revisions WHERE page_slug = '@tokens' ORDER BY id"), 'action_desc'));
        foreach (['Заморожен токен «bot-a»', 'проверяем правки', 'Разморожен токен «bot-a»', 'Заблокирован токен «bot-a»: ломал меню', 'Разблокирован токен', 'Заморожены все агенты', 'Все агенты разморожены', 'Роль токена «bot-a»: editor -> viewer', 'Отозван токен «bot-a»'] as $line) {
            $this->assertContains($line, $journal);
        }
        $this->assertNotContains($token, $journal, 'the token itself never goes into the journal');
    }

    public function testTheOwnerSeesAgentsAndHoldsThemWithButtons(): void
    {
        $this->boot();
        $token = Auth::createToken('seo-bot', 'editor', 30);
        $made = $this->rest($token, ['action' => 'create_page', 'slug' => 'from-bot', 'fields' => ['title' => 'От агента', 'blocks' => [['id' => 'intro', 'type' => 'text', 'data' => ['heading' => 'Привет', 'body' => 'Текст.']]]]]);
        $this->assertSame(200, $made['status'], $made['body']);
        $id = $this->idOf('seo-bot');
        $csrf = $this->adminLogin();

        $list = $this->http('GET', '/admin/?s=agents', null, [], true)['body'];
        $this->assertContains('seo-bot', $list);
        $this->assertContains('работает', $list);
        $this->assertContains('name="action" value="token_freeze"', $list);
        $this->assertContains('name="action" value="token_block"', $list);
        $this->assertContains('name="action" value="token_revoke"', $list);
        $this->assertContains('href="/admin/?s=agents"', $this->http('GET', '/admin/?s=pages', null, [], true)['body'], 'the section is in the menu');

        $card = $this->http('GET', '/admin/?s=agents&id=' . $id, null, [], true)['body'];
        $this->assertContains('Что делал агент', $card);
        $this->assertContains('/from-bot', $card, 'the card lists the pages the agent changed');
        $this->assertContains('127.0.0.1', $card);

        $r = $this->form('?s=agents&id=' . $id, ['action' => 'token_freeze', 'id' => $id, 'hours' => '168', 'reason' => 'отпуск'], $csrf);
        $this->assertContains('Токен заморожен', $r['body']);
        $this->assertContains('отпуск', $r['body']);
        $this->assertSame('frozen', Tokens::find($id)['status']);
        $days = (strtotime((string)Tokens::find($id)['frozen_until']) - time()) / 86400;
        $this->assertTrue($days > 6.9 && $days <= 7, 'frozen for 7 days');
        $this->assertSame(403, $this->rest($token, ['action' => 'get_site_structure'])['status']);
        $this->assertContains('Остановлены <span class="muted">1</span>', $this->http('GET', '/admin/?s=agents', null, [], true)['body']);

        $this->form('?s=agents', ['action' => 'token_unfreeze', 'id' => $id], $csrf);
        $this->assertSame('active', Tokens::find($id)['status']);

        $r = $this->form('?s=agents', ['action' => 'token_block', 'id' => $id, 'reason' => 'спам'], $csrf);
        $this->assertContains('Токен заблокирован', $r['body']);
        $this->assertSame('blocked', Tokens::find($id)['status']);
        $this->assertSame('admin', Tokens::find($id)['hold_by'], 'who stopped it is kept');
        $this->form('?s=agents', ['action' => 'token_unblock', 'id' => $id], $csrf);
        $this->assertSame('active', Tokens::find($id)['status']);

        $this->form('?s=agents', ['action' => 'token_role', 'id' => $id, 'role' => 'viewer'], $csrf);
        $this->assertSame('viewer', Tokens::find($id)['role']);

        $r = $this->form('?s=agents', ['action' => 'agents_freeze', 'hours' => '1', 'reason' => 'разбор'], $csrf);
        $this->assertContains('Все ИИ-агенты заморожены', $r['body']);
        $this->assertContains('Все ИИ-агенты заморожены', $this->http('GET', '/admin/?s=pages', null, [], true)['body'], 'the banner shows on every page');
        $this->assertContains('agents_frozen', $this->rest($token, ['action' => 'get_site_structure'])['body']);
        $this->form('?s=agents', ['action' => 'agents_unfreeze'], $csrf);
        $this->assertSame(200, $this->rest($token, ['action' => 'get_site_structure'])['status']);

        $r = $this->form('?s=agents', ['action' => 'token_revoke', 'id' => $id], $csrf);
        $this->assertContains('Токен отозван', $r['body']);
        $this->assertSame('revoked', Tokens::find($id)['status']);
        $this->assertSame(401, $this->rest($token, ['action' => 'get_site_structure'])['status']);
    }

    public function testAnEditorCannotHoldTokens(): void
    {
        $this->boot();
        Auth::createToken('bot-c', 'editor', 0);
        Auth::createUser('editor1', 'Passw0rd-editor-1', 'editor');
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'editor1', 'password' => 'Passw0rd-editor-1']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        preg_match('/name="csrf" value="([^"]+)"/', $this->http('GET', '/admin/', null, [], true)['body'], $m);
        $this->assertContains('Нужна роль администратора', $this->http('GET', '/admin/?s=agents', null, [], true)['body']);
        $this->form('?s=agents', ['action' => 'token_block', 'id' => $this->idOf('bot-c')], $m[1] ?? '');
        $this->assertSame('active', Tokens::find($this->idOf('bot-c'))['status']);
    }

    public function testCommandLineHoldsTokens(): void
    {
        $this->boot();
        Auth::createToken('cli-bot', 'editor', 0);
        $env = 'AGECMS_DATA_DIR=' . escapeshellarg(AGECMS_DATA) . ' AGECMS_CACHE_DIR=' . escapeshellarg(AGECMS_CACHE) . ' AGECMS_MODULES_DIR=' . escapeshellarg(AGECMS_MODULES) . ' AGECMS_UPLOADS_DIR=' . escapeshellarg(AGECMS_UPLOADS);
        $run = static fn(string $args): string => (string)shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/token.php') . ' ' . $args . ' 2>&1');
        $this->assertContains('Frozen for 2 h', $run('freeze cli-bot --hours=2 --reason=test'));
        $this->assertContains('FROZEN until', $run('list'));
        $this->assertContains('Blocked', $run('block cli-bot'));
        $this->assertContains('BLOCKED', $run('list'));
        $this->assertContains('Unblocked', $run('unblock cli-bot'));
        $this->assertContains('ALL AGENTS FROZEN', $run('freeze-all --hours=1') . $run('list'));
        $run('unfreeze-all');
        $this->assertContains('ACTIVE', $run('list'));
    }
}
