<?php
declare(strict_types=1);

use AgeCMS\{Content, Pages, Queue, Revisions, RichText, SchemaValidator, ValidationException};

final class RichTextTest extends TestCase
{
    private function clean(string $html): string
    {
        $once = RichText::sanitize($html);
        $this->assertSame($once, RichText::sanitize($once), 'a second pass changes nothing: ' . $html);
        return $once;
    }

    public function testScriptsHandlersAndDangerousAddressesAreGone(): void
    {
        $vectors = [
            '<a href="javascript:alert(1)">a</a>',
            '<a href=" JaVaScRiPt:alert(1)">a</a>',
            '<a href="java&#x09;script:alert(1)">a</a>',
            '<a href="java&#10;script:alert(1)">a</a>',
            '<a href="&#106;avascript:alert(1)">a</a>',
            '<a href="vbscript:msgbox(1)">a</a>',
            '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">a</a>',
            '<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" alt="x">',
            '<img src="x" onerror="alert(1)">',
            '<img src="javascript:alert(1)">',
            '<img src="http://example.com/a.png">',
            '<svg onload="alert(1)"><script>alert(1)</script></svg>',
            '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
            '<p style="background:url(javascript:alert(1))" onclick="alert(1)">t</p>',
            '<style>body{background:red}</style><p>t</p>',
            '<iframe src="https://evil.example/"></iframe><object data="x"></object><embed src="x">',
            '<form action="https://evil.example/"><input name="q"><button>go</button></form>',
            '<scr<script>ipt>alert(1)</scr</script>ipt>',
            '<a href="//evil.example/">a</a><a href="/\\evil.example/">b</a>',
            '<div onmouseover="alert(1)">t</div><p><a href="https://ok.example/" onmouseover="x" target="_blank">ok</a></p>',
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
            '<!--<img src="x" onerror="alert(1)">--><p>t</p>',
            '<p>t</p><base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=https://evil.example/">',
        ];
        foreach ($vectors as $html) {
            $out = $this->clean($html);
            foreach (['<script', 'javascript:', 'vbscript:', 'data:', 'onerror', 'onload', 'onclick', 'onmouseover', 'style=', '<svg', '<math', '<iframe', '<object', '<embed', '<form', '<input', '<base', '<meta', 'target=', '<style', '//evil', '\\evil', 'http://example.com'] as $bad) {
                $this->assertNotContains($bad, strtolower($out), $html);
            }
        }
        $this->assertSame('<p><a href="https://ok.example/">ok</a></p>', RichText::sanitize('<p><a href="https://ok.example/" onmouseover="x" target="_blank">ok</a></p>'));
        $this->assertSame('<p>a</p>', RichText::sanitize('<a href="javascript:alert(1)">a</a>'), 'a link without a usable address stays as its text');
        $this->assertSame('', RichText::sanitize('<img src="data:image/png;base64,AAAA">'), 'a picture without one goes away');
    }

    public function testTheWhiteListKeepsWhatAnArticleNeeds(): void
    {
        $html = '<h1>Заголовок</h1><h2>Раздел</h2><h3>Подраздел</h3><h4>Мелкий</h4><h5>Ещё мельче</h5>'
            . '<p><strong>жирный</strong> <b>b</b> <em>курсив</em> <i>i</i> <u>u</u> <s>s</s> <code>x()</code><br>строка</p>'
            . '<ul><li>один</li><li>два</li></ul><ol><li>первый</li></ol><blockquote>цитата</blockquote>'
            . '<figure><img src="https://cdn.example/a.jpg" alt="Фото" width="640" height="480" class="x"><figcaption>Подпись</figcaption></figure>'
            . '<p><img src="/assets/uploads/0123456789abcdef0123.webp" alt=""></p>'
            . '<table><thead><tr><th colspan="2">Шапка</th></tr></thead><tbody><tr><td rowspan="2">1</td><td>2</td></tr></tbody></table>'
            . '<hr><pre><code>a &lt; b</code></pre>'
            . '<p><a href="/ceny" title="Цены">цены</a>, <a href="#top">наверх</a>, <a href="mailto:info@example.ru">почта</a>, <a href="tel:+7 912 000-00-00">звонок</a>, <a href="https://partner.example/?a=1&amp;b=2" rel="nofollow sponsored noopener evil">партнёр</a>, <a href="../old.html">файл</a></p>';
        $out = $this->clean($html);
        foreach ([
            '<h2>Заголовок</h2>', '<h2>Раздел</h2>', '<h3>Подраздел</h3>', '<h4>Мелкий</h4>', '<h4>Ещё мельче</h4>',
            '<strong>жирный</strong>', '<b>b</b>', '<em>курсив</em>', '<i>i</i>', '<u>u</u>', '<s>s</s>', '<code>x()</code>', '<br>',
            '<ul><li>один</li><li>два</li></ul>', '<ol><li>первый</li></ol>', '<blockquote>цитата</blockquote>',
            '<img src="https://cdn.example/a.jpg" alt="Фото" width="640" height="480">', '<figcaption>Подпись</figcaption>',
            '<img src="/assets/uploads/0123456789abcdef0123.webp" alt="">', '<th colspan="2">Шапка</th>', '<td rowspan="2">1</td>',
            '<hr>', '<pre><code>a &lt; b</code></pre>', '<a href="/ceny" title="Цены">цены</a>', '<a href="#top">', '<a href="mailto:info@example.ru">',
            '<a href="tel:+7912000-00-00">', 'href="https://partner.example/?a=1&amp;b=2" rel="nofollow noopener sponsored"', '<a href="../old.html">',
        ] as $kept) {
            $this->assertContains($kept, $out);
        }
        $this->assertNotContains('<h1', $out, 'the page keeps its only h1');
        $this->assertNotContains('class=', $out);
    }

    public function testBrokenHtmlContainersAndEmptyTagsComeOutTidy(): void
    {
        $this->assertSame("<p>unclosed <b>bold <i>both</i></b></p>\n<ul><li>one</li><li>two</li></ul>", $this->clean('<p>unclosed <b>bold <i>both</p><ul><li>one<li>two</ul>'));
        $this->assertSame("<p>Привет мир</p>\n<p>a</p>\n<p>b</p>", $this->clean('<div>Привет <span style="color:red">мир</span></div><div><p>a</p><p>b</p></div>'), 'containers of other CMS: a paragraph or unwrapped');
        $this->assertSame('<p>текст</p>', $this->clean('<p>&nbsp;</p><p><br></p><p> </p><p>текст</p><p><strong></strong></p>'), 'empty paragraphs of WordPress and editors are dropped');
        $this->assertSame("<p>Google</p>\n<p>Docs</p>", $this->clean('<b style="font-weight:normal;" id="docs-internal-guid-1"><p>Google</p><p>Docs</p></b>'));
        $this->assertSame("<p>orphan 1</p>\n<p>orphan 2</p>", $this->clean('<li>orphan 1</li><li>orphan 2</li>'), 'items out of a list stay separate');
        $this->assertSame('<p>a &lt; b и c &gt; d</p>', $this->clean('<p>a < b и c > d</p>'), 'a lone < is text');
        $this->assertSame("<p>текст до</p>\n<p>абзац</p>", $this->clean('текст до<p>абзац</p>'));
        $this->assertSame('<blockquote>a<br>b</blockquote>', $this->clean('<blockquote><div>a</div><div>b</div></blockquote>'));
        $this->assertSame('', $this->clean('<p> </p><div></div>'));
        $this->assertSame('', RichText::sanitize("  \n "));
        $this->assertSame('<p>ok</p>', $this->clean("<p>o\x01k</p>"), 'control characters go');
        $this->assertSame('<p>ok?</p>', $this->clean("<p>ok\xC3</p>"), 'broken UTF-8 is repaired before parsing');
    }

    public function testNestedTablesStayTables(): void
    {
        $out = $this->clean('<table><tr><td>1<table><tr><td>inner</td></tr></table></td><td colspan="2" style="x">2</td></tr></table>');
        $this->assertSame('<table><tr><td>1<table><tr><td>inner</td></tr></table></td><td colspan="2">2</td></tr></table>', $out);
        $this->assertSame('<table><tr><td>a</td></tr></table>', $this->clean('<table>текст между строк<tr><td>a</td><p>чужой абзац</p></tr><caption>подпись</caption></table>'), 'text and foreign tags between rows are dropped or moved out');
        // Very deep nesting is flattened instead of recursing without end.
        $deep = str_repeat('<blockquote>', 300) . 'глубоко' . str_repeat('</blockquote>', 300);
        $this->assertContains('глубоко', $this->clean($deep));
    }

    public function testPlainTextBecomesParagraphs(): void
    {
        $this->assertSame("<p>Первый абзац<br>вторая строка</p>\n<p>Второй &amp; последний, \"в кавычках\" и it's</p>", $this->clean("Первый абзац\r\nвторая строка\n\n\nВторой & последний, \"в кавычках\" и it's"));
        $this->assertSame('<p>2 &lt; 3</p>', $this->clean('2 < 3'));
    }

    public function testCyrillicStaysReadable(): void
    {
        $out = $this->clean('<p>Съешь же ещё этих мягких французских булок, да выпей чаю. <a href="/о-нас">О нас</a></p>');
        $this->assertContains('Съешь же ещё этих мягких французских булок, да выпей чаю.', $out, 'no entities for letters');
        $this->assertContains('href="/', $out);
        $this->assertContains('>О нас</a>', $out);
    }

    public function testTheSchemaCleansAndCountsHtml(): void
    {
        $this->freshSite();
        $spec = ['type' => 'richtext', 'max' => 40, 'required' => true];
        $errors = [];
        $this->assertSame('<p>Hi <b>there</b></p>', SchemaValidator::value($spec, '<p onclick="x">Hi <b>there</b></p><script>x</script>', 'body', $errors));
        $this->assertSame([], $errors);
        SchemaValidator::value($spec, '<p>' . str_repeat('я', 40) . '</p>', 'body', $errors);
        $this->assertContains('longer than 40', implode(' ', $errors), 'max counts the HTML, tags included');
        $errors = [];
        SchemaValidator::value($spec, 42, 'body', $errors);
        $this->assertContains('must be a string', implode(' ', $errors));

        $doc = ['title' => 'Статья', 'blocks' => [['id' => 'a', 'type' => 'article', 'data' => ['body' => '<p><br></p><script>alert(1)</script>']]]];
        $e = $this->assertThrows(ValidationException::class, fn() => SchemaValidator::document($doc));
        $this->assertContains('must not be empty', $e->getMessage(), 'a required richtext that cleans to nothing is empty');
        $doc['blocks'][0]['data']['body'] = "Просто текст\n\nвторой абзац";
        $this->assertSame("<p>Просто текст</p>\n<p>второй абзац</p>", SchemaValidator::document($doc)['blocks'][0]['data']['body']);
    }

    public function testLongHtmlGoesThroughRevisionsAndRollback(): void
    {
        $this->freshSite();
        $para = '<p>Абзац с <strong>жирным</strong>, <a href="https://example.com/x?a=1&amp;b=2">ссылкой</a> и "кавычками" / слэшами ~1 ' . str_repeat('текст ', 40) . '</p>';
        $first = str_repeat($para . "\n", 150);
        $created = Queue::submit($this->admin(), 'create_page', ['slug' => 'blog/post', 'fields' => ['title' => 'Пост', 'published_at' => '2019-05-04', 'blocks' => [
            ['id' => 'body', 'type' => 'article', 'data' => ['heading' => 'Заголовок', 'body' => $first]],
        ]]]);
        $stored = Pages::doc('blog/post')['blocks'][0]['data']['body'];
        $this->assertTrue(mb_strlen($stored) > 50000, 'a long body is kept whole');
        $this->assertSame(RichText::sanitize($first), $stored);

        $second = str_replace('жирным', 'полужирным', $stored) . '<h2>Новый раздел</h2>';
        $edit = Queue::submit($this->admin(), 'update_block_field', ['slug' => 'blog/post', 'block_id' => 'body', 'field_path' => 'body', 'value' => $second]);
        $this->assertContains('Новый раздел', Pages::doc('blog/post')['blocks'][0]['data']['body']);
        $this->assertSame($stored, Revisions::stateAt('blog/post', (int)$created['revision_id'])['blocks'][0]['data']['body'], 'the ledger gives back the long value');
        $this->assertSame(Pages::doc('blog/post'), Revisions::stateAt('blog/post', (int)$edit['revision_id']), 'the latest state replays from the patches');

        Queue::submit($this->admin(), 'rollback', ['slug' => 'blog/post', 'revision_id' => (int)$created['revision_id']]);
        $this->assertSame($stored, Pages::doc('blog/post')['blocks'][0]['data']['body'], 'rollback restores the HTML exactly');
        $this->assertSame('2019-05-04', Pages::doc('blog/post')['published_at']);
        $this->assertSame(true, Revisions::verifyChain()['ok']);
    }

    public function testTemplatesPrintCleanHtml(): void
    {
        $this->assertSame('<p>a</p>', ag_rich('<p onclick="x">a</p><script>b</script>'));
        $this->assertSame('', ag_rich(null));
    }
}
