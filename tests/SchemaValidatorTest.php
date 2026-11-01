<?php
declare(strict_types=1);

use AgeCMS\{SchemaValidator, ValidationException};

final class SchemaValidatorTest extends TestCase
{
    private function doc(array $blocks, array $extra = []): array
    {
        return $extra + ['title' => 'T', 'blocks' => $blocks];
    }

    private function hero(array $data = ['h1' => 'Hello']): array
    {
        return ['id' => 'hero_1', 'type' => 'hero', 'data' => $data];
    }

    public function testValidDocumentIsNormalized(): void
    {
        $this->freshSite();
        $out = SchemaValidator::document($this->doc([$this->hero()], ['is_noindex' => true]));
        $this->assertSame(['title', 'parent_slug', 'meta_title', 'meta_description', 'canonical_url', 'og_image', 'is_noindex', 'is_published', 'sort_order', 'schema_type', 'theme', 'blocks'], array_keys($out));
        $this->assertSame(1, $out['is_noindex']);
        $this->assertSame(1, $out['is_published']);
        $this->assertSame('WebPage', $out['schema_type']);
    }

    private function rejects(array $doc, string $part): void
    {
        $this->assertThrows(ValidationException::class, fn() => SchemaValidator::document($doc), $part);
    }

    public function testPathsThatClimbOutAndLoginsInAddressesAreRejected(): void
    {
        $this->freshSite();
        $image = static fn(string $src): array => ['id' => 'img_1', 'type' => 'image', 'data' => ['src' => $src, 'alt' => 'x']];
        $this->assertSame('/assets/uploads/a.webp', SchemaValidator::document($this->doc([$image('/assets/uploads/a.webp')]))['blocks'][0]['data']['src']);
        $this->assertSame('/assets/uploads/a.webp?v=2#x', SchemaValidator::document($this->doc([$image('/assets/uploads/a.webp?v=2#x')]))['blocks'][0]['data']['src']);
        $this->assertSame('https://cdn.example/a/b.webp', SchemaValidator::document($this->doc([$image('https://cdn.example/a/b.webp')]))['blocks'][0]['data']['src']);
        foreach (['/assets/uploads/../../data/data.sqlite', '/assets/uploads/%2e%2e/%2e%2e/data/data.sqlite', '/assets/uploads/%252e%252e/x', '/assets/uploads/./a.webp', '/assets/uploads/..'] as $bad) {
            $this->rejects($this->doc([$image($bad)]), "must not contain '..'");
        }
        $this->rejects($this->doc([$image('/assets/uploads/a\\b.webp')]), 'must be an http(s) URL');
        $this->rejects($this->doc([$image('/assets/uploads/a%5Cb.webp')]), "must not contain '..' or a backslash");
        $this->rejects($this->doc([$this->hero()], ['og_image' => '/assets/uploads/../../data/data.sqlite']), "og_image must not contain '..'");
        $this->assertSame('/assets/uploads/a.webp', SchemaValidator::document($this->doc([$this->hero()], ['og_image' => '/assets/uploads/a.webp']))['og_image']);

        // A login or password in an address is how a link pretends to lead to another host.
        $this->rejects($this->doc([$this->hero()], ['canonical_url' => 'https://user:pw@evil.example/']), 'login or password');
        $this->rejects($this->doc([$this->hero()], ['og_image' => 'https://example.test@evil.example/a.png']), 'login or password');
        $this->rejects($this->doc([$this->hero(['h1' => 'A', 'cta_url' => 'https://user@evil.example/'])]), 'login or password');
        $this->assertSame('https://example.test/a@b', SchemaValidator::document($this->doc([$this->hero()], ['canonical_url' => 'https://example.test/a@b']))['canonical_url'], 'an @ in the path is fine');
        $this->assertSame('mailto:a@b.example', SchemaValidator::document($this->doc([$this->hero(['h1' => 'A', 'cta_url' => 'mailto:a@b.example'])]))['blocks'][0]['data']['cta_url']);
    }

    public function testTagsInPlainFieldsAreKeptAsTextAndShownEscaped(): void
    {
        $this->freshSite();
        $evil = '<script>alert(1)</script> <b>x</b>';
        $doc = SchemaValidator::document($this->doc([$this->hero(['h1' => $evil, 'subtitle' => $evil])], ['title' => $evil, 'meta_description' => $evil]));
        $this->assertSame($evil, $doc['blocks'][0]['data']['h1'], 'a plain field is not rewritten behind the back of the author');
        $this->assertSame($evil, $doc['title']);
        \AgeCMS\Queue::submit($this->admin(), 'create_page', ['slug' => 'plain', 'fields' => $doc]);
        $html = \AgeCMS\Renderer::renderSlug('plain', false)['html'];
        $this->assertNotContains('<script>alert(1)', $html, 'never printed as a tag');
        $this->assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'printed as letters');
    }

    public function testHallucinatedStructureIsRejected(): void
    {
        $this->freshSite();
        $this->rejects($this->doc([['id' => 'x1', 'type' => 'carousel', 'data' => []]]), "type 'carousel'");
        $this->rejects($this->doc([$this->hero(['h1' => 'A', 'color' => 'red'])]), "field 'color' is not allowed");
        $this->rejects($this->doc([['id' => 'hero_1', 'type' => 'hero', 'data' => ['h1' => 'A'], 'style' => 'x']]), "unknown key 'style'");
        $this->rejects($this->doc([$this->hero()], ['seo_keywords' => 'x']), "Unknown page field 'seo_keywords'");
        $this->rejects(['blocks' => []], 'title is required');
        $this->rejects($this->doc([$this->hero(['subtitle' => 'no heading'])]), 'h1 is required');
    }

    public function testSizeAndTypeLimits(): void
    {
        $this->freshSite();
        $this->rejects($this->doc([$this->hero(['h1' => str_repeat('x', 201)])]), 'longer than 200');
        $this->rejects($this->doc([$this->hero(['h1' => 123])]), 'must be a string');
        $this->rejects($this->doc([$this->hero(['h1' => "line\nbreak"])]), 'control characters');
        $this->rejects($this->doc([$this->hero()], ['sort_order' => '5']), 'sort_order');
        $this->rejects($this->doc([$this->hero()], ['schema_type' => 'Evil']), 'schema_type');
        $this->rejects($this->doc([$this->hero()], ['is_published' => 2]), 'is_published');
        $this->rejects($this->doc(array_fill(0, 101, $this->hero())), 'Too many blocks');
    }

    public function testDangerousUrlsAreRejected(): void
    {
        $this->freshSite();
        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', 'vbscript:x', '//evil.example/x', 'ftp://x.example', 'https://a.example/"onclick="x'] as $url) {
            $this->rejects($this->doc([$this->hero(['h1' => 'A', 'cta_url' => $url])]), 'cta_url');
        }
        foreach (['https://ok.example/path?q=1', '/relative', '#anchor', 'mailto:a@b.example', 'tel:+7(900)123-45-67'] as $url) {
            $out = SchemaValidator::document($this->doc([$this->hero(['h1' => 'A', 'cta_url' => $url])]));
            $this->assertSame($url, $out['blocks'][0]['data']['cta_url']);
        }
    }

    public function testBlockIdsMustBeUniqueAndWellFormed(): void
    {
        $this->freshSite();
        $this->rejects($this->doc([$this->hero(), $this->hero()]), 'duplicated');
        $this->rejects($this->doc([['id' => 'Bad-Id', 'type' => 'hero', 'data' => ['h1' => 'A']]]), 'id must match');
    }

    public function testListFieldsAreValidatedPerItem(): void
    {
        $this->freshSite();
        $features = static fn(array $items) => ['id' => 'f1', 'type' => 'features', 'data' => ['items' => $items]];
        $this->rejects($this->doc([$features([['text' => 'no title']])]), 'items[0].title is required');
        $this->rejects($this->doc([$features([['title' => 'A', 'size' => 'huge']])]), 'must be one of');
        $this->rejects($this->doc([$features(array_fill(0, 13, ['title' => 'A']))]), 'too many items');
        $ok = SchemaValidator::document($this->doc([$features([['title' => 'A', 'size' => 'wide']])]));
        $this->assertSame('wide', $ok['blocks'][0]['data']['items'][0]['size']);
    }
}
