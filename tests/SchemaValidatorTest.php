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
