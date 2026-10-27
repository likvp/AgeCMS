<?php
declare(strict_types=1);

namespace AgeCMS;

use InvalidArgumentException;

/**
 * RFC 6902 JSON Patch over decoded (associative) JSON.
 * diff() emits add/remove/replace; apply() also understands move, copy and test.
 * An empty PHP array is ambiguous between {} and []; it is treated as whichever the other side needs.
 */
final class JsonPatch
{
    /** @return list<array<string,mixed>> */
    public static function diff(mixed $from, mixed $to, string $path = ''): array
    {
        if ($from === $to) {
            return [];
        }
        if (is_array($from) && is_array($to)) {
            if (array_is_list($from) && array_is_list($to)) {
                return self::diffLists($from, $to, $path);
            }
            if ((array_is_list($from) && $from !== []) || (array_is_list($to) && $to !== [])) {
                return [['op' => 'replace', 'path' => $path, 'value' => $to]];
            }
            return self::diffObjects($from, $to, $path);
        }
        return [['op' => 'replace', 'path' => $path, 'value' => $to]];
    }

    /**
     * @param list<mixed> $a
     * @param list<mixed> $b
     * @return list<array<string,mixed>>
     */
    private static function diffLists(array $a, array $b, string $path): array
    {
        $ops = [];
        $na = count($a);
        $nb = count($b);
        for ($i = 0; $i < min($na, $nb); $i++) {
            array_push($ops, ...self::diff($a[$i], $b[$i], $path . '/' . $i));
        }
        for ($i = $na; $i < $nb; $i++) {
            $ops[] = ['op' => 'add', 'path' => $path . '/' . $i, 'value' => $b[$i]];
        }
        for ($i = $na - 1; $i >= $nb; $i--) {
            $ops[] = ['op' => 'remove', 'path' => $path . '/' . $i];
        }
        return $ops;
    }

    /**
     * @param array<string|int,mixed> $a
     * @param array<string|int,mixed> $b
     * @return list<array<string,mixed>>
     */
    private static function diffObjects(array $a, array $b, string $path): array
    {
        $ops = [];
        foreach ($a as $key => $_) {
            if (!array_key_exists($key, $b)) {
                $ops[] = ['op' => 'remove', 'path' => $path . '/' . self::escape((string)$key)];
            }
        }
        foreach ($a as $key => $value) {
            if (array_key_exists($key, $b)) {
                array_push($ops, ...self::diff($value, $b[$key], $path . '/' . self::escape((string)$key)));
            }
        }
        foreach ($b as $key => $value) {
            if (!array_key_exists($key, $a)) {
                $ops[] = ['op' => 'add', 'path' => $path . '/' . self::escape((string)$key), 'value' => $value];
            }
        }
        return $ops;
    }

    /**
     * @param list<array<string,mixed>> $ops
     * @throws InvalidArgumentException when an operation cannot be applied
     */
    public static function apply(mixed $doc, array $ops): mixed
    {
        foreach ($ops as $i => $op) {
            $type = $op['op'] ?? null;
            $tokens = self::parse((string)($op['path'] ?? ''));
            switch ($type) {
                case 'add':
                    $doc = self::add($doc, $tokens, $op['value'] ?? null);
                    break;
                case 'remove':
                    $doc = self::remove($doc, $tokens);
                    break;
                case 'replace':
                    self::get($doc, $tokens);
                    $doc = self::set($doc, $tokens, $op['value'] ?? null);
                    break;
                case 'test':
                    if (self::get($doc, $tokens) !== ($op['value'] ?? null)) {
                        throw new InvalidArgumentException("Patch op #{$i}: test failed at {$op['path']}");
                    }
                    break;
                case 'move':
                    $from = self::parse((string)($op['from'] ?? ''));
                    $value = self::get($doc, $from);
                    $doc = self::add(self::remove($doc, $from), $tokens, $value);
                    break;
                case 'copy':
                    $value = self::get($doc, self::parse((string)($op['from'] ?? '')));
                    $doc = self::add($doc, $tokens, $value);
                    break;
                default:
                    throw new InvalidArgumentException("Patch op #{$i}: unknown op");
            }
        }
        return $doc;
    }

    /** @return list<string> */
    public static function parse(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }
        if ($pointer[0] !== '/') {
            throw new InvalidArgumentException("Invalid JSON pointer: {$pointer}");
        }
        return array_map(
            static fn(string $t): string => str_replace(['~1', '~0'], ['/', '~'], $t),
            explode('/', substr($pointer, 1)),
        );
    }

    public static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    /** @param list<string> $tokens */
    public static function get(mixed $doc, array $tokens): mixed
    {
        foreach ($tokens as $token) {
            if (!is_array($doc)) {
                throw new InvalidArgumentException('Path does not exist');
            }
            $key = self::key($doc, $token);
            $doc = $doc[$key];
        }
        return $doc;
    }

    /** Replaces an existing value in place, keeping key order. @param list<string> $tokens */
    private static function set(mixed $doc, array $tokens, mixed $value): mixed
    {
        if ($tokens === []) {
            return $value;
        }
        $token = array_shift($tokens);
        $key = self::key($doc, $token);
        $doc[$key] = self::set($doc[$key], $tokens, $value);
        return $doc;
    }

    /** @param list<string> $tokens */
    private static function add(mixed $doc, array $tokens, mixed $value): mixed
    {
        if ($tokens === []) {
            return $value;
        }
        if (!is_array($doc)) {
            throw new InvalidArgumentException('Cannot add into a scalar');
        }
        $token = array_shift($tokens);
        if ($tokens !== []) {
            $key = self::key($doc, $token);
            $doc[$key] = self::add($doc[$key], $tokens, $value);
            return $doc;
        }
        $asList = array_is_list($doc) && ($doc !== [] || $token === '-' || ctype_digit($token));
        if (!$asList) {
            $doc[$token] = $value;
            return $doc;
        }
        if ($token === '-') {
            $doc[] = $value;
            return $doc;
        }
        if (!ctype_digit($token) || (int)$token > count($doc)) {
            throw new InvalidArgumentException("Index out of range: {$token}");
        }
        array_splice($doc, (int)$token, 0, [$value]);
        return $doc;
    }

    /** @param list<string> $tokens */
    private static function remove(mixed $doc, array $tokens): mixed
    {
        if ($tokens === []) {
            return null;
        }
        if (!is_array($doc)) {
            throw new InvalidArgumentException('Path does not exist');
        }
        $token = array_shift($tokens);
        $key = self::key($doc, $token);
        if ($tokens !== []) {
            $doc[$key] = self::remove($doc[$key], $tokens);
            return $doc;
        }
        if (array_is_list($doc)) {
            array_splice($doc, (int)$key, 1);
        } else {
            unset($doc[$key]);
        }
        return $doc;
    }

    /** @param array<string|int,mixed> $doc */
    private static function key(array $doc, string $token): string|int
    {
        if (array_is_list($doc)) {
            if (!ctype_digit($token) || !array_key_exists((int)$token, $doc)) {
                throw new InvalidArgumentException("Path does not exist: {$token}");
            }
            return (int)$token;
        }
        if (!array_key_exists($token, $doc)) {
            throw new InvalidArgumentException("Path does not exist: {$token}");
        }
        return $token;
    }
}
