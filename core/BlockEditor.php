<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Support for the mouse editor on the page: readable names of block fields, ready-made contents of a new block
 * and the JSON the page carries so that editor.js can draw the "change block" form without any request.
 */
final class BlockEditor
{
    /** Names shown in the form for the fields themes usually have. A theme can set its own with "label" in the field. */
    private const LABELS = [
        'heading' => 'Заголовок', 'h1' => 'Главный заголовок', 'h2' => 'Заголовок', 'title' => 'Заголовок', 'subtitle' => 'Подзаголовок',
        'text' => 'Текст', 'body' => 'Текст', 'intro' => 'Вступление', 'note' => 'Примечание', 'description' => 'Описание',
        'caption' => 'Подпись', 'alt' => 'Описание картинки', 'src' => 'Картинка', 'image' => 'Картинка', 'photo' => 'Фото', 'photos' => 'Фото',
        'wide' => 'На всю ширину', 'cta_text' => 'Текст кнопки', 'cta_url' => 'Куда ведёт кнопка', 'button_text' => 'Текст кнопки',
        'success_text' => 'Текст после отправки', 'url' => 'Ссылка', 'link' => 'Ссылка', 'items' => 'Элементы', 'rows' => 'Строки',
        'tiles' => 'Плитки', 'bullets' => 'Пункты', 'q' => 'Вопрос', 'a' => 'Ответ', 'name' => 'Название', 'label' => 'Подпись',
        'value' => 'Значение', 'icon' => 'Значок', 'badge' => 'Метка', 'tag' => 'Метка', 'price' => 'Цена', 'updated' => 'Дата обновления',
        'phone' => 'Телефон', 'phones' => 'Телефоны', 'email' => 'Почта', 'address' => 'Адрес', 'address_hint' => 'Подсказка к адресу',
        'legal_address' => 'Юридический адрес', 'hours' => 'Часы работы', 'map_url' => 'Ссылка на карту', 'route_url' => 'Ссылка на маршрут',
        'reviews_url' => 'Ссылка на отзывы', 'rating_value' => 'Оценка', 'rating_text' => 'Подпись к оценке', 'rating_url' => 'Ссылка на отзывы',
        'calc_title' => 'Заголовок калькулятора', 'calc_note' => 'Примечание к калькулятору', 'ombudsman_text' => 'Текст про уполномоченного',
        'day_rate' => 'Ставка в день', 'rates' => 'Ставки', 'psk' => 'ПСК', 'min_amount' => 'Минимальная сумма', 'max_amount' => 'Максимальная сумма',
        'size' => 'Размер', 'step' => 'Шаг', 'default_amount' => 'Сумма по умолчанию', 'min_days' => 'Минимум дней', 'max_days' => 'Максимум дней',
        'default_days' => 'Срок по умолчанию', 'col1' => 'Колонка 1', 'col2' => 'Колонка 2', 'col3' => 'Колонка 3',
    ];

    /** Readable names of the values themes usually give an enum. A theme can set its own with "labels" (value => name) in the field. */
    private const VALUES = [
        'wide' => 'Широкий', 'narrow' => 'Узкий', 'normal' => 'Обычный', 'small' => 'Маленький', 'medium' => 'Средний', 'large' => 'Большой',
        'left' => 'Слева', 'right' => 'Справа', 'center' => 'По центру', 'dark' => 'Тёмный', 'light' => 'Светлый', 'top' => 'Сверху', 'bottom' => 'Снизу',
    ];

    /** Readable name of a field: "label" of the theme, then the table above, then the key itself. */
    public static function label(string $key, array $spec): string
    {
        $own = $spec['label'] ?? null;
        if (is_string($own) && $own !== '') {
            return $own;
        }
        return self::LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Block types of a theme for the editor: a short name, an explanation and the fields with names.
     *
     * @return list<array<string,mixed>>
     */
    public static function types(string $theme): array
    {
        $out = [];
        foreach (Theme::blocks($theme) as $type => $schema) {
            $title = (string)($schema['title'] ?? $type);
            $hint = '';
            if (($colon = strpos($title, ':')) !== false) {
                $hint = trim(substr($title, $colon + 1));
                $title = trim(substr($title, 0, $colon));
            }
            $item = ['type' => $type, 'title' => $title, 'hint' => $hint, 'fields' => self::fields($schema['fields'] ?? [])];
            $preview = self::preview($theme, $schema['preview'] ?? null);
            if ($preview !== null) {
                $item['preview'] = $preview;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Address of the picture of a block for the "add block" window: "preview" in theme.json is a file inside the theme folder
     * (previews/hero.webp). Only an image that is really there counts, otherwise the card stays without a picture.
     */
    public static function preview(string $theme, mixed $file): ?string
    {
        if (!is_string($file) || preg_match('~^(?!/)(?!.*\.\.)[A-Za-z0-9_./-]{1,120}\.(png|jpe?g|webp|avif|gif|svg)$~', $file) !== 1) {
            return null;
        }
        $path = Theme::dir($theme) . '/' . $file;
        return is_file($path) ? '/themes/' . $theme . '/' . $file . '?v=' . filemtime($path) : null;
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @return list<array<string,mixed>>
     */
    private static function fields(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $spec) {
            $kind = (string)($spec['type'] ?? 'string');
            $field = ['name' => (string)$name, 'type' => $kind, 'label' => self::label((string)$name, $spec), 'required' => !empty($spec['required'])];
            foreach (['max', 'min'] as $limit) {
                if (isset($spec[$limit])) {
                    $field[$limit] = (int)$spec[$limit];
                }
            }
            if (is_string($spec['hint'] ?? null)) {
                $field['hint'] = $spec['hint'];
            }
            if (!empty($spec['media'])) {
                $field['media'] = true;
            }
            if ($kind === 'enum') {
                $labels = (array)($spec['labels'] ?? []);
                $field['options'] = array_map(
                    static fn($v): array => ['value' => (string)$v, 'label' => is_string($labels[$v] ?? null) ? $labels[$v] : (self::VALUES[$v] ?? (string)$v)],
                    array_values((array)($spec['values'] ?? [])),
                );
            }
            if ($kind === 'list') {
                $field['of'] = self::fields((array)($spec['of'] ?? []));
            }
            $out[] = $field;
        }
        return $out;
    }

    /**
     * Contents of a block added with the mouse: every required field filled with its own name, one sample item in each list.
     * A required image cannot be invented, the caller passes it.
     *
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    public static function skeleton(array $fields, string $path = ''): array
    {
        $data = [];
        foreach ($fields as $name => $spec) {
            $kind = (string)($spec['type'] ?? 'string');
            $label = self::label((string)$name, $spec);
            $wanted = !empty($spec['required']) || in_array($name, ['heading', 'h1', 'title'], true) && $path === '';
            if (!empty($spec['media']) && !empty($spec['required'])) {
                throw new ValidationException(["{$path}{$name}: choose the image first, a new block with a required image needs data"]);
            }
            if ($kind === 'list') {
                if (!empty($spec['required']) || $path === '') {
                    $sample = self::skeleton((array)($spec['of'] ?? []), "{$path}{$name}.");
                    $data[$name] = $sample === [] ? [] : [$sample];
                }
                continue;
            }
            if (!$wanted) {
                continue;
            }
            $data[$name] = match ($kind) {
                'url' => '/',
                'int' => (int)max((int)($spec['min'] ?? 0), 0),
                'bool' => false,
                'enum' => (string)(($spec['values'] ?? [''])[0] ?? ''),
                default => $label,
            };
        }
        return $data;
    }

    /** A free block id: the type and a short random tail, within the 40 characters the schema allows. */
    public static function newId(array $doc, string $type): string
    {
        $taken = array_column($doc['blocks'] ?? [], 'id');
        $base = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'block', 0, 30);
        do {
            $id = $base . '_' . bin2hex(random_bytes(3));
        } while (in_array($id, $taken, true));
        return $id;
    }

    /** The data the editor needs, as a script tag for the end of the page content. Safe inside HTML. */
    public static function payload(string $slug, string $theme, array $doc, string $csrf): string
    {
        $blocks = [];
        foreach ($doc['blocks'] as $block) {
            $blocks[] = ['id' => $block['id'], 'type' => $block['type'], 'data' => $block['data']];
        }
        $json = json_encode(
            ['slug' => $slug, 'csrf' => $csrf, 'blocks' => $blocks, 'types' => self::types($theme)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );
        return '<script type="application/json" id="ag-editor-data">' . $json . '</script>';
    }
}
