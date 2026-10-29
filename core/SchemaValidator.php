<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Schema enforcement. A page document is only accepted if every key is known, every value has the
 * right type and size, and every block type and field is declared by the theme. This is what keeps
 * an agent's hallucinated structure out of the database.
 */
final class SchemaValidator
{
    public const SCHEMA_TYPES = ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'Article', 'Service', 'Product', 'FAQPage', 'LocalBusiness'];
    private const MAX_BLOCKS = 100;
    // A path may not start with // or /\ : browsers read that as a link to another host.
    private const URL_PATTERN = '~^(https?://[^\s<>"\'\\\\]+|/(?![/\\\\])[^\s<>"\'\\\\]*|#[^\s<>"\'\\\\]*|mailto:[^\s<>"\'\\\\]+|tel:[+0-9()\-]+)$~u';

    /**
     * Validates and normalizes a page document: fills defaults, fixes key order, casts flags to ints.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public static function document(array $input): array
    {
        $errors = [];
        $known = ['title', 'parent_slug', 'meta_title', 'meta_description', 'canonical_url', 'og_image', 'is_noindex', 'is_published', 'sort_order', 'schema_type', 'theme', 'blocks'];
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = "Unknown page field '{$key}'";
            }
        }

        $doc = [
            'title' => self::scalar($input, 'title', 'string', 200, true, $errors),
            'parent_slug' => (string)($input['parent_slug'] ?? ''),
            'meta_title' => self::scalar($input, 'meta_title', 'string', 200, false, $errors),
            'meta_description' => self::scalar($input, 'meta_description', 'text', 500, false, $errors),
            'canonical_url' => self::scalar($input, 'canonical_url', 'url', 500, false, $errors),
            'og_image' => self::scalar($input, 'og_image', 'url', 500, false, $errors),
            'is_noindex' => self::flag($input, 'is_noindex', 0, $errors),
            'is_published' => self::flag($input, 'is_published', 1, $errors),
            'sort_order' => self::intValue($input['sort_order'] ?? 0, -100000, 100000, 'sort_order', $errors),
            'schema_type' => (string)($input['schema_type'] ?? 'WebPage'),
            'theme' => (string)($input['theme'] ?? ''),
            'blocks' => [],
        ];

        if ($doc['parent_slug'] !== '' && !Pages::slugValid($doc['parent_slug'])) {
            $errors[] = 'parent_slug is not a valid slug';
        }
        if (!in_array($doc['schema_type'], self::SCHEMA_TYPES, true)) {
            $errors[] = 'schema_type must be one of ' . implode(', ', self::SCHEMA_TYPES);
        }
        if ($doc['theme'] !== '' && !Theme::exists($doc['theme'])) {
            $errors[] = "Theme '{$doc['theme']}' does not exist";
        }

        $themeName = $doc['theme'] !== '' ? $doc['theme'] : self::activeTheme();
        $doc['blocks'] = self::blocks($input['blocks'] ?? [], $themeName, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $doc;
    }

    /**
     * @return list<array<string,mixed>>
     * @param list<string> $errors
     */
    private static function blocks(mixed $blocks, string $themeName, array &$errors): array
    {
        if (!is_array($blocks) || ($blocks !== [] && !array_is_list($blocks))) {
            $errors[] = 'blocks must be a list';
            return [];
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            $errors[] = 'Too many blocks (max ' . self::MAX_BLOCKS . ')';
            return [];
        }
        $schemas = Theme::exists($themeName) ? Theme::blocks($themeName) : [];
        $seen = [];
        $out = [];
        foreach ($blocks as $i => $block) {
            $at = "blocks[{$i}]";
            if (!is_array($block) || array_is_list($block) && $block !== []) {
                $errors[] = "{$at} must be an object";
                continue;
            }
            foreach (array_keys($block) as $key) {
                if (!in_array($key, ['id', 'type', 'data'], true)) {
                    $errors[] = "{$at}: unknown key '{$key}'";
                }
            }
            $id = $block['id'] ?? null;
            $type = $block['type'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $id) !== 1) {
                $errors[] = "{$at}.id must match [a-z][a-z0-9_]{0,39}";
                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = "{$at}.id '{$id}' is duplicated";
                continue;
            }
            $seen[$id] = true;
            if (!is_string($type) || !isset($schemas[$type])) {
                $errors[] = "{$at}.type '" . (is_string($type) ? $type : gettype($type)) . "' is not defined by theme '{$themeName}'";
                continue;
            }
            $data = $block['data'] ?? [];
            $out[] = ['id' => $id, 'type' => $type, 'data' => self::object($schemas[$type]['fields'] ?? [], $data, "{$at}.data", $errors)];
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private static function object(array $fields, mixed $value, string $path, array &$errors): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = "{$path} must be an object";
            return [];
        }
        foreach (array_keys($value) as $key) {
            if (!isset($fields[$key])) {
                $errors[] = "{$path}: field '{$key}' is not allowed";
            }
        }
        $out = [];
        foreach ($fields as $name => $spec) {
            $present = array_key_exists($name, $value) && $value[$name] !== null;
            if (!$present) {
                if (!empty($spec['required'])) {
                    $errors[] = "{$path}.{$name} is required";
                }
                continue;
            }
            $normalized = self::field($spec, $value[$name], "{$path}.{$name}", $errors);
            if (!empty($spec['required']) && $normalized === '') {
                $errors[] = "{$path}.{$name} must not be empty";
            }
            $out[$name] = $normalized;
        }
        return $out;
    }

    /**
     * One value against one field of a theme schema (a field of a block or of the theme settings). Errors are added to $errors.
     *
     * @param array<string,mixed> $spec
     * @param list<string> $errors
     */
    public static function value(array $spec, mixed $value, string $path, array &$errors): mixed
    {
        return self::field($spec, $value, $path, $errors);
    }

    /** A link a field may hold: http(s), a path on the site, #anchor, mailto: or tel:. */
    public static function urlValid(string $value): bool
    {
        return preg_match(self::URL_PATTERN, $value) === 1;
    }

    /**
     * @param array<string,mixed> $spec
     * @param list<string> $errors
     */
    private static function field(array $spec, mixed $value, string $path, array &$errors): mixed
    {
        $type = (string)($spec['type'] ?? 'string');
        switch ($type) {
            case 'string':
            case 'text':
            case 'url':
                if (!is_string($value)) {
                    $errors[] = "{$path} must be a string";
                    return '';
                }
                return self::string($value, $type, (int)($spec['max'] ?? ($type === 'text' ? 5000 : ($type === 'url' ? 500 : 200))), $path, $errors);
            case 'int':
                return self::intValue($value, (int)($spec['min'] ?? -1000000000), (int)($spec['max'] ?? 1000000000), $path, $errors);
            case 'bool':
                if (!is_bool($value)) {
                    $errors[] = "{$path} must be true or false";
                    return false;
                }
                return $value;
            case 'enum':
                if (!is_string($value) || !in_array($value, $spec['values'] ?? [], true)) {
                    $errors[] = "{$path} must be one of " . implode(', ', $spec['values'] ?? []);
                    return '';
                }
                return $value;
            case 'list':
                if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
                    $errors[] = "{$path} must be a list";
                    return [];
                }
                if (count($value) > (int)($spec['max'] ?? 50)) {
                    $errors[] = "{$path} has too many items (max " . (int)($spec['max'] ?? 50) . ')';
                    return [];
                }
                $items = [];
                foreach ($value as $i => $item) {
                    $items[] = self::object($spec['of'] ?? [], $item, "{$path}[{$i}]", $errors);
                }
                return $items;
            default:
                $errors[] = "{$path}: unsupported field type '{$type}' in theme";
                return null;
        }
    }

    /** @param list<string> $errors */
    private static function string(string $value, string $type, int $max, string $path, array &$errors): string
    {
        $value = str_replace("\r\n", "\n", $value);
        $pattern = $type === 'text' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($pattern, $value) === 1) {
            $errors[] = "{$path} contains control characters";
        }
        if (mb_strlen($value) > $max) {
            $errors[] = "{$path} is longer than {$max} characters";
        }
        if ($type === 'url' && $value !== '' && preg_match(self::URL_PATTERN, $value) !== 1) {
            $errors[] = "{$path} must be an http(s) URL, a relative path, #anchor, mailto: or tel:";
        }
        return trim($value);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $errors
     */
    private static function scalar(array $input, string $key, string $type, int $max, bool $required, array &$errors): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            $errors[] = "{$key} must be a string";
            return '';
        }
        $value = self::string($value, $type, $max, $key, $errors);
        if ($required && $value === '') {
            $errors[] = "{$key} is required";
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $errors
     */
    private static function flag(array $input, string $key, int $default, array &$errors): int
    {
        $value = $input[$key] ?? $default;
        if (is_bool($value)) {
            return (int)$value;
        }
        if ($value === 0 || $value === 1) {
            return $value;
        }
        $errors[] = "{$key} must be 0/1 or true/false";
        return $default;
    }

    /** @param list<string> $errors */
    private static function intValue(mixed $value, int $min, int $max, string $path, array &$errors): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            $errors[] = "{$path} must be an integer between {$min} and {$max}";
            return 0;
        }
        return $value;
    }

    private static function activeTheme(): string
    {
        try {
            return Theme::active();
        } catch (NotInstalledException) {
            return 'default';
        }
    }
}
