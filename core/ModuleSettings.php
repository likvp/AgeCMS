<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Settings of a module that its owner edits in the admin (System, "Настройки" next to the module). Declared in the
 * signed manifest:
 *   "settings": [{"key": "from_name", "label": "Имя отправителя", "type": "text", "default": "Карат"},
 *                {"key": "batch", "label": "Писем за запуск", "type": "number", "min": 1, "max": 200, "default": 20},
 *                {"key": "smtp_pass", "label": "Пароль", "type": "secret"}]
 * Types: text, textarea, number, bool, select (with "options"), secret. Values are checked against the declaration
 * before they are saved and are kept in data/config.php (mode 0600) under module_settings.<module>.<key>, next to the
 * other secrets and never in the database or the page output. A module reads its own with ModuleSettings::get().
 */
final class ModuleSettings
{
    private const MAX_FIELDS = 30;
    private const TYPES = ['text', 'textarea', 'number', 'bool', 'select', 'secret'];

    /**
     * @param array<string,mixed> $manifest
     * @return list<array{key:string,label:string,type:string,default:mixed,help:string,options:list<string>,min:int,max:int,length:int}>
     */
    public static function parse(array $manifest): array
    {
        $declared = $manifest['settings'] ?? [];
        if (!is_array($declared) || !array_is_list($declared) || count($declared) > self::MAX_FIELDS) {
            throw new AgeCMSException('settings must be a list of at most ' . self::MAX_FIELDS . ' fields');
        }
        $fields = [];
        $seen = [];
        foreach ($declared as $i => $f) {
            if (!is_array($f) || array_diff(array_keys($f), ['key', 'label', 'type', 'default', 'help', 'options', 'min', 'max', 'length']) !== [] || !isset($f['key'], $f['label'], $f['type'])) {
                throw new AgeCMSException("settings[{$i}]: key, label and type are required, unknown fields are not allowed");
            }
            if (!is_string($f['key']) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $f['key']) !== 1 || isset($seen[$f['key']])) {
                throw new AgeCMSException("settings[{$i}]: key is lowercase letters, digits and _ and is used once");
            }
            $seen[$f['key']] = true;
            if (!is_string($f['label']) || trim($f['label']) === '' || mb_strlen($f['label']) > 80 || preg_match('/[\x00-\x1f\x7f]/', $f['label']) === 1) {
                throw new AgeCMSException("settings[{$i}]: label is 1..80 characters");
            }
            $type = $f['type'];
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                throw new AgeCMSException("settings[{$i}]: type is one of " . implode(', ', self::TYPES));
            }
            $help = $f['help'] ?? '';
            if (!is_string($help) || mb_strlen($help) > 300) {
                throw new AgeCMSException("settings[{$i}]: help is a string up to 300 characters");
            }
            $options = $f['options'] ?? [];
            if ($type === 'select') {
                if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 30 || array_filter($options, static fn($o): bool => !is_string($o) || $o === '' || mb_strlen($o) > 80) !== []) {
                    throw new AgeCMSException("settings[{$i}]: select needs options, a list of 1..30 non-empty strings");
                }
            } elseif (isset($f['options'])) {
                throw new AgeCMSException("settings[{$i}]: options only go with type select");
            }
            $min = $f['min'] ?? 0;
            $max = $f['max'] ?? 1000000;
            if ($type === 'number' ? (!is_int($min) || !is_int($max) || $min > $max) : (isset($f['min']) || isset($f['max']))) {
                throw new AgeCMSException("settings[{$i}]: min and max are integers and only go with type number");
            }
            $length = $f['length'] ?? ($type === 'textarea' ? 5000 : 255);
            if (!is_int($length) || $length < 1 || $length > 20000 || (isset($f['length']) && !in_array($type, ['text', 'textarea', 'secret'], true))) {
                throw new AgeCMSException("settings[{$i}]: length is 1..20000 and goes with text, textarea or secret");
            }
            $default = $f['default'] ?? match ($type) {
                'number' => $min, 'bool' => false, 'select' => $options[0], default => '',
            };
            $ok = match ($type) {
                'number' => is_int($default) && $default >= $min && $default <= $max,
                'bool' => is_bool($default),
                'select' => in_array($default, $options, true),
                'secret' => $default === '',
                default => is_string($default) && mb_strlen($default) <= $length,
            };
            if (!$ok) {
                throw new AgeCMSException("settings[{$i}]: default does not fit the type" . ($type === 'secret' ? ' (a secret has no default)' : ''));
            }
            $fields[] = ['key' => $f['key'], 'label' => trim($f['label']), 'type' => $type, 'default' => $default, 'help' => $help, 'options' => array_values($options), 'min' => (int)$min, 'max' => (int)$max, 'length' => $length];
        }
        return $fields;
    }

    /** @param array<string,array{status:string,reason:?string,manifest:?array<string,mixed>}> $modules */
    public static function validateAll(array &$modules): void
    {
        foreach ($modules as $name => $info) {
            if ($info['status'] !== 'ok' || ($info['manifest']['settings'] ?? []) === []) {
                continue;
            }
            try {
                self::parse($info['manifest']);
            } catch (AgeCMSException $e) {
                $modules[$name] = ['status' => 'blocked', 'reason' => $e->getMessage(), 'manifest' => null, 'signed' => null];
                Logger::log('warning', 'module.blocked', ['module' => $name, 'reason' => $e->getMessage()]);
            }
        }
    }

    /** Declared fields of a verified module; empty for a module without settings or a blocked one. @return list<array<string,mixed>> */
    public static function fields(string $module): array
    {
        $info = SkillRegistry::modules()[$module] ?? null;
        if ($info === null || $info['status'] !== 'ok' || ($info['manifest']['settings'] ?? []) === []) {
            return [];
        }
        try {
            return self::parse($info['manifest']);
        } catch (AgeCMSException) {
            return [];
        }
    }

    /** The saved value of a setting, or its declared default. Unknown keys give $fallback. */
    public static function get(string $module, string $key, mixed $fallback = null): mixed
    {
        foreach (self::fields($module) as $f) {
            if ($f['key'] === $key) {
                $saved = Config::get("module_settings.{$module}.{$key}");
                return $saved === null ? $f['default'] : $saved;
            }
        }
        return $fallback;
    }

    /** @return array<string,mixed> every setting of the module with defaults filled in */
    public static function all(string $module): array
    {
        $out = [];
        foreach (self::fields($module) as $f) {
            $out[$f['key']] = self::get($module, $f['key']);
        }
        return $out;
    }

    /**
     * Checks the submitted form against the declaration and saves it. Returns the keys that changed.
     * A secret left empty keeps its value; "clear_<key>" erases it. Anything not declared is ignored.
     *
     * @param array<string,mixed> $input
     * @return list<string>
     * @throws ValidationException
     */
    public static function save(string $module, array $input): array
    {
        $fields = self::fields($module);
        if ($fields === []) {
            throw new NotFoundException("Module {$module} has no settings");
        }
        $values = [];
        foreach ($fields as $f) {
            $key = $f['key'];
            $raw = $input[$key] ?? null;
            $label = $f['label'];
            $values[$key] = match ($f['type']) {
                'bool' => isset($input[$key]) && $raw !== '0',
                'number' => self::number($raw, $f),
                'select' => in_array($raw, $f['options'], true) ? $raw : throw new ValidationException(["{$label}: выберите значение из списка"]),
                'secret' => isset($input['clear_' . $key]) ? '' : (self::text($raw, $f) ?: (string)Config::get("module_settings.{$module}.{$key}", '')),
                default => self::text($raw, $f),
            };
        }
        $changed = [];
        $old = (array)Config::get("module_settings.{$module}", []);
        foreach ($values as $key => $value) {
            if (($old[$key] ?? null) !== $value && !(!array_key_exists($key, $old) && $value === self::defaultOf($fields, $key))) {
                $changed[] = $key;
            }
        }
        Config::set("module_settings.{$module}", $values);
        Logger::log('info', 'module.settings_saved', ['module' => $module, 'changed' => $changed]);
        if ($changed !== []) {
            Audit::record(Audit::MODULES, "Настройки модуля {$module}: изменено " . implode(', ', $changed));
        }
        return $changed;
    }

    /** @param array<string,mixed> $f */
    private static function number(mixed $raw, array $f): int
    {
        if (!is_string($raw) || preg_match('/^-?\d{1,9}$/', trim($raw)) !== 1 || (int)$raw < $f['min'] || (int)$raw > $f['max']) {
            throw new ValidationException([$f['label'] . ': число от ' . $f['min'] . ' до ' . $f['max']]);
        }
        return (int)$raw;
    }

    /** @param array<string,mixed> $f */
    private static function text(mixed $raw, array $f): string
    {
        $text = is_string($raw) ? str_replace("\r\n", "\n", $raw) : '';
        $text = $f['type'] === 'textarea' ? trim($text) : trim(str_replace(["\r", "\n"], ' ', $text));
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text) === 1 || mb_strlen($text) > $f['length']) {
            throw new ValidationException([$f['label'] . ': не больше ' . $f['length'] . ' знаков, без управляющих символов']);
        }
        return $text;
    }

    /** @param list<array<string,mixed>> $fields */
    private static function defaultOf(array $fields, string $key): mixed
    {
        foreach ($fields as $f) {
            if ($f['key'] === $key) {
                return $f['default'];
            }
        }
        return null;
    }
}
