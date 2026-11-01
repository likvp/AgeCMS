<?php
declare(strict_types=1);

use AgeCMS\{Actor, Content, Database, ForbiddenException, Pages, Queue, Redirects, Revisions, ValidationException};

require_once __DIR__ . '/support/HttpCase.php';

final class RedirectsTest extends HttpCase
{
    /** @param list<array{from:string,to:string}> $items */
    private function add(array $items, ?Actor $actor = null): array
    {
        return Queue::submit($actor ?? $this->admin(), 'add_redirects', ['items' => $items]);
    }

    public function testAddressesAreNormalized(): void
    {
        $this->assertSame('/old/path?p=12', Redirects::normalizeFrom('https://old.example.ru/old/path/?p=12#comments'));
        $this->assertSame('/old/path?p=12', Redirects::normalizeFrom('old//path?utm_source=x&p=12&fbclid=1'));
        $this->assertSame('/?p=12', Redirects::normalizeFrom('/?p=12'));
        $this->assertSame('/a?b=2&c=1&c=3', Redirects::normalizeFrom('/a?c=3&b=2&c=1'), 'query keys in a fixed order');
        $this->assertSame('/привет/мир', Redirects::normalizeFrom('/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82/%d0%bc%d0%b8%d1%80/'), 'percent-encoding is decoded, case does not matter');
        $this->assertSame('/привет/мир', Redirects::normalizeFrom('/привет/мир'), 'Cyrillic as typed');
        $this->assertSame('/search?q=a%20b', Redirects::normalizeFrom('/search?q=a+b'));
        $this->assertSame('/search?q=a%20b', Redirects::normalizeFrom('/search?q=a%20b'));
        $this->assertSame('/a%3Fb', Redirects::normalizeFrom('/a%3Fb'), 'a question mark inside the path stays encoded');
        $this->assertSame('/catalog/item.php?ID=5', Redirects::normalizeFrom('//old.example/catalog/item.php?ID=5'));
        $this->assertSame('/', Redirects::normalizeFrom('https://old.example'));
        $this->assertSame(null, Redirects::normalizeFrom("/bad\x01"));
        $this->assertSame(null, Redirects::normalizeFrom('/%FF%FE'), 'not UTF-8');
    }

    public function testAddListRemove(): void
    {
        $this->freshSite(['demo' => true]);
        $this->createPage('blog/post');
        $r = $this->add([
            ['from' => '/?p=12', 'to' => '/blog/post'],
            ['from' => 'https://old.example.ru/2019/05/post/', 'to' => 'https://example.test/blog/post'],
            ['from' => '/staroe/', 'to' => '/'],
            ['from' => '/partner', 'to' => 'https://partner.example/x?y=1'],
        ]);
        $this->assertSame(4, $r['added']);
        $this->assertSame(true, $r['changed']);
        $this->assertTrue(is_int($r['revision_id']));
        $this->assertContains('Редиректы: добавлено 4', (string)Database::value('SELECT action_desc FROM revisions WHERE id = ?', [$r['revision_id']]));
        $this->assertSame('blog/post', Database::value('SELECT to_slug FROM redirects WHERE from_slug = ?', ['/2019/05/post']), 'an address of this site is stored as a page');
        $this->assertSame('index', Database::value('SELECT to_slug FROM redirects WHERE from_slug = ?', ['/staroe']));

        $again = $this->add([['from' => '/?p=12', 'to' => '/blog/post'], ['from' => '/staroe', 'to' => '/blog/post']]);
        $this->assertSame([0, 1, 1], [$again['added'], $again['updated'], $again['unchanged']], 'a repeated import is idempotent, a new target replaces the old');
        $same = $this->add([['from' => '/?p=12', 'to' => '/blog/post']]);
        $this->assertSame(false, $same['changed']);

        $this->assertSame('/blog/post', Redirects::target('/', 'p=12'));
        $this->assertSame('/blog/post', Redirects::target('/', 'utm_source=x&p=12'), 'tracking keys are ignored');
        $this->assertSame(null, Redirects::target('/', 'p=13'));
        $this->assertSame('/blog/post?page=2', Redirects::target('/2019/05/post', 'page=2'), 'a match by the path alone passes the query on');
        $this->assertSame('https://partner.example/x?y=1', Redirects::target('/partner', ''));

        $list = Queue::submit($this->admin(), 'remove_redirects', ['from' => ['https://old.example.ru/2019/05/post', '/nothing']]);
        $this->assertSame(1, $list['removed']);
        $this->assertSame(['/nothing'], $list['missing']);
        $this->assertSame(null, Redirects::target('/2019/05/post', ''));

        // Listing shows the imported ones and the one a moved page left.
        Queue::submit($this->admin(), 'rename_page', ['slug' => 'about', 'new_slug' => 'o-nas']);
        $rows = Redirects::listing();
        $kinds = array_column($rows, 'kind', 'from');
        $this->assertSame('rename', $kinds['/about']);
        $this->assertSame('import', $kinds['/?p=12']);
        $this->assertSame('/o-nas', array_column($rows, 'to', 'from')['/about']);
        $this->assertSame(1, count(Redirects::listing(10, 0, 'partner')));
        Queue::submit($this->admin(), 'remove_redirects', ['from' => ['/about']]);
        $this->assertSame(null, Pages::redirectFor('about'), 'the redirect of a moved page can be removed too');
        $this->assertSame(true, Revisions::verifyChain()['ok']);
    }

    public function testConflictsChainsAndLoopsAreRefused(): void
    {
        $this->freshSite(['demo' => true]);
        $this->createPage('blog/post');
        $refuse = function (array $item, string $part): void {
            $this->assertThrows(ValidationException::class, fn() => $this->add([$item]), $part);
        };
        $refuse(['from' => '/blog/post', 'to' => '/about'], 'existing page');
        $refuse(['from' => '/about/', 'to' => '/blog/post'], 'existing page');
        $refuse(['from' => '/', 'to' => '/about'], 'home page');
        $refuse(['from' => '/old', 'to' => '/no-such-page'], 'not an existing page');
        $refuse(['from' => '/old', 'to' => '/about?x=1'], 'without ?query');
        $refuse(['from' => '/old', 'to' => 'http://other.example/'], 'https');
        $refuse(['from' => '/old', 'to' => 'https://other.example/a b'], 'https');
        $refuse(['from' => '/admin/old', 'to' => '/about'], 'system');
        $refuse(['from' => '/sitemap.xml', 'to' => '/about'], 'system');
        $refuse(['from' => '/_/lead', 'to' => '/about'], 'system');
        $refuse(['from' => '/index', 'to' => '/about'], 'system');
        $refuse(['from' => '', 'to' => '/about'], 'non-empty');
        // A login or password in the target hides the real host; ".." and backslashes in a path never lead anywhere sensible.
        $refuse(['from' => '/old', 'to' => 'https://user:pw@evil.example/'], 'login or password');
        $refuse(['from' => '/old', 'to' => 'https://example.test@evil.example/'], 'login or password');
        $refuse(['from' => '/old', 'to' => 'https://evil.example@example.test/about'], 'login or password');
        $refuse(['from' => '/old/../x', 'to' => '/about'], "'..'");
        $refuse(['from' => '/old/%2e%2e/x', 'to' => '/about'], "'..'");
        $refuse(['from' => '/old', 'to' => '/blog/../about'], "'..'");
        $refuse(['from' => '/old', 'to' => '/blog\\post'], 'backslash');
        $refuse(['from' => '/x'], 'non-empty');

        // A source may never be a target page, so a chain cannot form: /a -> /about, then /about would have to be a source.
        $this->add([['from' => '/a', 'to' => '/about']]);
        $refuse(['from' => '/about', 'to' => '/blog/post'], 'existing page');
        // The query variant of a page address is allowed: it is how /?p=12 of WordPress works, and it cannot loop.
        $this->add([['from' => '/about?lang=en', 'to' => '/blog/post']]);
        $this->assertSame('/blog/post', Redirects::target('/about', 'lang=en'));
        $this->assertSame(null, Redirects::target('/about', ''), 'the page itself is not shadowed');

        // All or nothing.
        $e = $this->assertThrows(ValidationException::class, fn() => $this->add([['from' => '/ok-1', 'to' => '/about'], ['from' => '/ok-1/', 'to' => '/about'], ['from' => '/blog/post', 'to' => '/about']]));
        $this->assertContains('listed twice', $e->getMessage());
        $this->assertContains('items[2]', $e->getMessage());
        $this->assertSame(null, Database::value("SELECT 1 FROM redirects WHERE from_slug = '/ok-1'"));

        $this->assertThrows(ValidationException::class, fn() => $this->add([]), '1-1000');
        $many = array_map(static fn(int $i): array => ['from' => '/old-' . $i, 'to' => '/about'], range(1, 1001));
        $this->assertThrows(ValidationException::class, fn() => $this->add($many), '1-1000');
        $this->assertSame(1000, $this->add(array_slice($many, 0, 1000))['added'], 'a thousand in one call');

        // The redirect of a moved page is not taken over.
        Queue::submit($this->admin(), 'rename_page', ['slug' => 'blog/post', 'new_slug' => 'blog/post-2']);
        $refuse(['from' => '/blog/post', 'to' => '/about'], 'moved');

        // A page created later at a source address wins, its redirect goes away.
        $this->createPage('a');
        $this->assertSame(null, Database::value("SELECT 1 FROM redirects WHERE from_slug = '/a'"));

        $viewer = new Actor('agent', 'v', 'viewer', 's');
        $this->assertThrows(ForbiddenException::class, fn() => Content::addRedirects($viewer, [['from' => '/z', 'to' => '/about']]));
    }

    public function testTargetsFollowAMovedPageAndVanishWithADeletedOne(): void
    {
        $this->freshSite(['demo' => true]);
        $this->createPage('post');
        $this->add([['from' => '/?p=1', 'to' => '/post'], ['from' => '/old-post', 'to' => '/post']]);
        Queue::submit($this->admin(), 'rename_page', ['slug' => 'post', 'new_slug' => 'blog-post']);
        $this->assertSame('/blog-post', Redirects::target('/', 'p=1'), 'no chain: the redirect points at the new address');
        $this->assertSame('/blog-post', Redirects::target('/old-post', ''));
        Queue::submit($this->admin(), 'delete_page', ['slug' => 'blog-post']);
        $this->assertSame(null, Redirects::target('/old-post', ''), 'a redirect to a deleted page is not followed');
    }

    public function testTheRouterAnswersWith301(): void
    {
        $this->boot();
        $this->createPage('blog/post');
        $this->add([
            ['from' => '/?p=12', 'to' => '/blog/post'],
            ['from' => '/index.php?page_id=7', 'to' => '/about'],
            ['from' => '/2019/05/post/', 'to' => '/blog/post'],
            ['from' => '/новости/старая', 'to' => '/about'],
            ['from' => '/catalog/item.php?ID=5&SECTION=2', 'to' => '/about'],
            ['from' => '/ushel', 'to' => 'https://partner.example/page'],
        ]);
        $hit = function (string $path): array {
            $r = $this->http('GET', $path);
            return [$r['status'], $r['headers']['location'] ?? ''];
        };
        $this->assertSame([301, '/blog/post'], $hit('/?p=12'), 'the home page with the query of WordPress');
        $this->assertSame([301, '/blog/post'], $hit('/?p=12&utm_source=yandex'));
        $this->assertSame(200, $this->http('GET', '/?p=13')['status'], 'another query shows the home page');
        $this->assertSame([301, '/about'], $hit('/index.php?page_id=7'));
        $this->assertSame([301, '/blog/post'], $hit('/2019/05/post/'), 'one step, also with the slash');
        $this->assertSame([301, '/blog/post'], $hit('/2019/05/post'));
        $this->assertSame([301, '/blog/post?replytocom=5'], $hit('/2019/05/post?replytocom=5'), 'a path match keeps the query');
        $this->assertSame([301, '/about'], $hit('/%D0%BD%D0%BE%D0%B2%D0%BE%D1%81%D1%82%D0%B8/%D1%81%D1%82%D0%B0%D1%80%D0%B0%D1%8F'));
        $this->assertSame([301, '/about'], $hit('/catalog/item.php?SECTION=2&ID=5'), 'query order does not matter');
        $this->assertSame([301, 'https://partner.example/page'], $hit('/ushel'));
        $this->assertSame(404, $this->http('GET', '/catalog/item.php?ID=6')['status']);
        $this->assertSame(200, $this->http('GET', '/blog/post')['status']);
        $this->assertSame(200, $this->http('GET', '/blog/post?p=12')['status'], 'a page with a query is still the page');
        $this->assertSame([301, '/blog/post'], $hit('/blog/post/'), 'the usual slash redirect');

        // The admin lists them and removes one.
        $csrf = $this->adminLoginForm();
        $page = $this->http('GET', '/admin/?s=settings&tab=redirects', null, [], true);
        $this->assertSame(200, $page['status']);
        $this->assertContains('/?p=12', $page['body']);
        $this->assertContains('/новости/старая', $page['body']);
        $r = $this->http('POST', '/admin/?s=settings&tab=redirects', http_build_query(['action' => 'redirect_remove', 'from' => '/ushel', 'csrf' => $csrf, 'tab' => 'redirects']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertContains('Редирект удалён', $r['body']);
        $this->assertSame(404, $this->http('GET', '/ushel')['status']);
        $r = $this->http('POST', '/admin/?s=settings&tab=redirects', http_build_query(['action' => 'redirect_add', 'from' => '/novyi', 'to' => '/about', 'csrf' => $csrf, 'tab' => 'redirects']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertContains('Редирект сохранён', $r['body']);
        $this->assertSame([301, '/about'], $hit('/novyi'));
        $r = $this->http('POST', '/admin/?s=settings&tab=redirects', http_build_query(['action' => 'redirect_add', 'from' => '/about', 'to' => '/blog/post', 'csrf' => $csrf, 'tab' => 'redirects']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $this->assertContains('existing page', $r['body'], 'a refusal is shown');
    }

    public function testToolsOverMcp(): void
    {
        $this->boot();
        $token = \AgeCMS\Auth::createToken('import-bot', 'editor');
        $call = function (string $name, array $args) use ($token): array {
            $r = $this->http('POST', '/admin/mcp.php', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]), ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']);
            $json = json_decode($r['body'], true);
            return ['error' => $json['result']['isError'] ?? true, 'data' => json_decode($json['result']['content'][0]['text'] ?? 'null', true)];
        };
        $added = $call('add_redirects', ['items' => [['from' => '/old', 'to' => '/about']]]);
        $this->assertSame(false, $added['error']);
        $this->assertSame(1, $added['data']['added']);
        $listed = $call('list_redirects', []);
        $this->assertSame(1, $listed['data']['total']);
        $this->assertSame('/old', $listed['data']['redirects'][0]['from']);
        $this->assertSame(true, $call('add_redirects', ['items' => [['from' => '/about', 'to' => '/']]])['error']);
        $this->assertSame(1, $call('remove_redirects', ['from' => ['/old']])['data']['removed']);
    }

    private function adminLoginForm(): string
    {
        $this->http('POST', '/admin/index.php', http_build_query(['action' => 'login', 'username' => 'admin', 'password' => 'Passw0rd-test-1']), ['Content-Type' => 'application/x-www-form-urlencoded'], true);
        $page = $this->http('GET', '/admin/', null, [], true);
        preg_match('/name="csrf" value="([^"]+)"/', $page['body'], $m);
        return $m[1] ?? '';
    }
}
