<?php
declare(strict_types=1);

use AgeCMS\CopyEdit;

/** Editing the text of a copied page in place: which elements are marked, what a change may do, what is refused. */
final class CopyEditTest extends TestCase
{
    private const BODY = '<div class="hero"><h1 class="t">Заголовок &amp; мир</h1><p>Первый <b>жирный</b> абзац</p><ul><li>Раз<li>Два</ul>'
        . '<a href="/about" class="btn">О нас</a><a href="/x"><img src="/img/a.png" srcset="/img/a2.png 2x" alt="Фото"></a>'
        . '<script>var a = "<p>не текст</p>";</script><style>p{color:red}</style><svg><text>svg</text></svg><!-- <p>комментарий</p> --><span>Хвост</span></div>';

    /** @return array<string,array<string,mixed>> n => element */
    private function byN(string $html): array
    {
        $out = [];
        foreach (CopyEdit::scan($html)['els'] as $el) {
            if ($el['n'] > 0) {
                $out[$el['n']] = $el;
            }
        }
        return $out;
    }

    public function testEditableElementsAreNumberedAndTheRestIsLeftAlone(): void
    {
        $tags = array_map(static fn(array $e): string => $e['tag'], array_values($this->byN(self::BODY)));
        $this->assertSame(['h1', 'p', 'b', 'li', 'li', 'a', 'a', 'img', 'span'], $tags, 'headings, paragraphs, inline text, list items, links, pictures, text spans; not script, style, svg, comments');
        $marked = CopyEdit::mark(self::BODY);
        $this->assertContains('<h1 class="t" data-ag-e="1">', $marked);
        $this->assertContains('<img src="/img/a.png" srcset="/img/a2.png 2x" alt="Фото" data-ag-e="8">', $marked);
        $this->assertNotContains('data-ag-e="10"', $marked);
        $this->assertSame(1, substr_count($marked, '<script>'), 'scripts are untouched');
        $this->assertSame(self::BODY, preg_replace('/ data-ag-e="\d+"/', '', $marked), 'marking changes nothing else');
    }

    public function testATextChangeLandsInThePlaceAndKeepsTheMarkup(): void
    {
        $r = CopyEdit::apply(self::BODY, [
            ['n' => 1, 'from' => ['Заголовок & мир'], 'to' => ['Новый <заголовок> & "кавычки"']],
            ['n' => 2, 'from' => ['Первый ', ' абзац'], 'to' => ['Второй ', ' текст']],
            ['n' => 3, 'from' => ['жирный'], 'to' => ['тонкий']],
        ]);
        $this->assertSame(3, $r['applied']);
        $this->assertSame([], $r['skipped']);
        $this->assertContains('<h1 class="t">Новый &lt;заголовок&gt; &amp; "кавычки"</h1>', $r['html']);
        $this->assertContains('<p>Второй <b>тонкий</b> текст</p>', $r['html']);
        $this->assertContains('<li>Раз<li>Два</ul>', $r['html'], 'what was not touched is byte for byte the same');
        $this->assertContains('var a = "<p>не текст</p>";', $r['html']);
    }

    public function testATextMayNeverBecomeMarkup(): void
    {
        $r = CopyEdit::apply(self::BODY, [['n' => 1, 'from' => ['Заголовок & мир'], 'to' => ['<script>alert(1)</script><img src=x onerror=alert(1)>']]]);
        $this->assertNotContains('<script>alert', $r['html']);
        $this->assertNotContains('<img src=x', $r['html']);
        $this->assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $r['html']);
    }

    public function testAChangeToAPageThatMovedOnIsRefusedNotGuessed(): void
    {
        $r = CopyEdit::apply(self::BODY, [
            ['n' => 1, 'from' => ['Совсем другой текст'], 'to' => ['x']],
            ['n' => 2, 'from' => ['Первый '], 'to' => ['x']],
            ['n' => 3, 'from' => ['жирный'], 'to' => ['']],
            ['n' => 99, 'from' => ['a'], 'to' => ['b']],
        ]);
        $this->assertSame(0, $r['applied']);
        $this->assertSame(4, count($r['skipped']));
        $this->assertSame(self::BODY, $r['html']);
    }

    public function testLinkAddressesAreCheckedAndPicturesReplaced(): void
    {
        $bad = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.example/x', 'http://a b.example', 'https://x.example/"onclick="x', "/ok\n/bad", 'vbscript:x', ''];
        foreach ($bad as $href) {
            $r = CopyEdit::apply(self::BODY, [['n' => 6, 'href' => $href]]);
            $this->assertSame(0, $r['applied'], $href);
        }
        foreach (['https://site.example/a?b=1#c', '/contacts', '#form', 'mailto:a@b.example', 'tel:+79991112233'] as $href) {
            $r = CopyEdit::apply(self::BODY, [['n' => 6, 'href' => $href]]);
            $this->assertSame(1, $r['applied'], $href);
            $this->assertContains('href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="btn"', $r['html']);
        }
        $r = CopyEdit::apply(self::BODY, [['n' => 8, 'src' => '/assets/uploads/aaaaaaaaaaaaaaaaaaaa.webp', 'alt' => 'Новая "картинка"']]);
        $this->assertSame(1, $r['applied']);
        $this->assertContains('<img src="/assets/uploads/aaaaaaaaaaaaaaaaaaaa.webp" alt="Новая &quot;картинка&quot;" data-ag-e', CopyEdit::mark($r['html']) ?: '', 'the old srcset is gone, or the browser would keep showing the old picture');
        $this->assertNotContains('srcset', substr($r['html'], (int)strpos($r['html'], '<img'), 160));
        $this->assertSame(0, CopyEdit::apply(self::BODY, [['n' => 8, 'src' => 'javascript:x']])['applied']);
        $this->assertSame(0, CopyEdit::apply(self::BODY, [['n' => 8, 'src' => 'http://insecure.example/a.png']])['applied']);
        $this->assertSame(0, CopyEdit::apply(self::BODY, [['n' => 1, 'href' => '/x']])['applied'], 'an address only on a link');
    }

    public function testOddMarkupDoesNotBreakTheScan(): void
    {
        $html = '<p>a < b and c > d<p>second<div>third</div>after<br/>x<table><tr><td>c1<td>c2<tr><td>c3</table><textarea><p>no</p></textarea><p>last';
        $texts = [];
        foreach ($this->byN($html) as $el) {
            foreach ($el['texts'] as [$s, $e]) {
                $texts[] = trim(html_entity_decode(substr($html, $s, $e - $s)));
            }
        }
        $this->assertSame(['a < b and c > d', 'second', 'third', 'c1', 'c2', 'c3', 'last'], $texts);
        $r = CopyEdit::apply($html, [['n' => 3, 'from' => ['third'], 'to' => ['3']]]);
        $this->assertContains('<div>3</div>', $r['html']);
        $r = CopyEdit::apply('', []);
        $this->assertSame(0, $r['applied']);
        $this->assertSame('<a href="/n" id="1">', CopyEdit::setAttrs('<a href="/o" id="1">', ['href' => '/n']));
        $this->assertSame('<a data-href="/keep" href="/n">', CopyEdit::setAttrs('<a data-href="/keep" href="/o">', ['href' => '/n']));
        $this->assertSame('<img src="/n" />', CopyEdit::setAttrs('<img src="/o" srcset="x 2x" />', ['src' => '/n', 'srcset' => null]));
    }

    public function testThePlainTextForSearchFollowsTheEdit(): void
    {
        $this->assertSame("Заголовок\nТекст", CopyEdit::plainText('<h1>Заголовок</h1><script>x</script><p>Текст</p>'));
        $this->assertSame(CopyEdit::base('a'), CopyEdit::base('a'));
        $this->assertTrue(CopyEdit::base('a') !== CopyEdit::base('b'));
    }
}
