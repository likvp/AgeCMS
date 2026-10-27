<?php
declare(strict_types=1);

use AgeCMS\JsonPatch;

final class JsonPatchTest extends TestCase
{
    public function testRoundTripOnRandomDocuments(): void
    {
        mt_srand(42);
        $gen = static function (int $depth) use (&$gen) {
            $t = mt_rand(0, $depth > 2 ? 3 : 7);
            if ($t === 0) { return mt_rand(0, 5); }
            if ($t === 1) { return 'v' . mt_rand(0, 3); }
            if ($t === 2) { return (bool)mt_rand(0, 1); }
            if ($t === 3) { return null; }
            $n = mt_rand(0, 3);
            $out = [];
            if ($t <= 5) {
                for ($i = 0; $i < $n; $i++) { $out[] = $gen($depth + 1); }
                return $out;
            }
            for ($i = 0; $i < $n; $i++) { $out[['a', 'b/c', 'd~e', 'f', 'g'][mt_rand(0, 4)]] = $gen($depth + 1); }
            return $out;
        };
        // JSON objects are unordered, so compare with sorted keys.
        $canon = static function ($v) use (&$canon) {
            if (is_array($v)) {
                $v = array_map($canon, $v);
                if (!array_is_list($v)) {
                    ksort($v);
                }
            }
            return $v;
        };
        for ($i = 0; $i < 3000; $i++) {
            $a = $gen(0);
            $b = $gen(0);
            $result = JsonPatch::apply($a, JsonPatch::diff($a, $b));
            $this->assertSame(json_encode($canon($b)), json_encode($canon($result)), 'round trip #' . $i);
        }
    }

    public function testEmptyPatchForEqualDocuments(): void
    {
        $this->assertSame([], JsonPatch::diff(['a' => [1, 2], 'b' => 'x'], ['a' => [1, 2], 'b' => 'x']));
    }

    public function testPointerEscaping(): void
    {
        $ops = JsonPatch::diff(['a/b' => 1, 'c~d' => 2], ['a/b' => 9, 'c~d' => 2]);
        $this->assertSame([['op' => 'replace', 'path' => '/a~1b', 'value' => 9]], $ops);
        $this->assertSame(['a/b', 'c~d'], JsonPatch::parse('/a~1b/c~0d'));
    }

    public function testRfc6902Operations(): void
    {
        $doc = ['foo' => 'bar', 'list' => [1, 2, 3]];
        $this->assertSame(['foo' => 'bar', 'list' => [1, 9, 2, 3], 'baz' => 'qux'], JsonPatch::apply($doc, [
            ['op' => 'add', 'path' => '/baz', 'value' => 'qux'],
            ['op' => 'add', 'path' => '/list/1', 'value' => 9],
        ]));
        $this->assertSame(['foo' => 'bar', 'list' => [1, 3]], JsonPatch::apply($doc, [['op' => 'remove', 'path' => '/list/1']]));
        $this->assertSame(['foo' => 'bar', 'list' => [1, 2, 3, 4]], JsonPatch::apply($doc, [['op' => 'add', 'path' => '/list/-', 'value' => 4]]));
        $moved = JsonPatch::apply($doc, [['op' => 'move', 'from' => '/foo', 'path' => '/moved']]);
        $this->assertSame(['list' => [1, 2, 3], 'moved' => 'bar'], $moved);
        $copied = JsonPatch::apply($doc, [['op' => 'copy', 'from' => '/foo', 'path' => '/copy']]);
        $this->assertSame('bar', $copied['copy']);
        $this->assertSame($doc, JsonPatch::apply($doc, [['op' => 'test', 'path' => '/foo', 'value' => 'bar']]));
    }

    public function testInvalidPatchesThrow(): void
    {
        $doc = ['a' => 1, 'l' => [1]];
        $this->assertThrows(InvalidArgumentException::class, fn() => JsonPatch::apply($doc, [['op' => 'remove', 'path' => '/missing']]));
        $this->assertThrows(InvalidArgumentException::class, fn() => JsonPatch::apply($doc, [['op' => 'replace', 'path' => '/missing', 'value' => 1]]));
        $this->assertThrows(InvalidArgumentException::class, fn() => JsonPatch::apply($doc, [['op' => 'add', 'path' => '/l/5', 'value' => 1]]));
        $this->assertThrows(InvalidArgumentException::class, fn() => JsonPatch::apply($doc, [['op' => 'test', 'path' => '/a', 'value' => 2]]));
        $this->assertThrows(InvalidArgumentException::class, fn() => JsonPatch::apply($doc, [['op' => 'bogus', 'path' => '/a']]));
        $this->assertThrows(InvalidArgumentException::class, fn() => JsonPatch::parse('no-slash'));
    }

    public function testReplacePreservesKeyOrder(): void
    {
        $doc = ['a' => 1, 'b' => 2, 'c' => 3];
        $this->assertSame(['a' => 1, 'b' => 9, 'c' => 3], JsonPatch::apply($doc, [['op' => 'replace', 'path' => '/b', 'value' => 9]]));
    }
}
