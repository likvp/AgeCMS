<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\Modules\Importer\Html\Sanitizer;

/**
 * Neutral elements into blocks of the active theme, read from its theme.json (nothing about the default theme is hard
 * coded). Each block type gets a role: hero, article, text, faq, image, form, list, child_pages. An explicit
 * "import": "<role>" in theme.json wins; otherwise the role is guessed from the field types and names.
 *
 * Rules: the first screen (hero element, or the page h1) goes to the hero block; FAQ to the block with a list of
 * question/answer; a picture standing alone to the image block; runs of headings, paragraphs, lists, quotes, tables and
 * pictures between them to one article (richtext), or to text blocks with plain text when the theme has no richtext;
 * a form to the form block; embeds and unparsed fragments into the article as a link, and into the report.
 * Field limits (max, required, list size) are kept by cutting, with a note in the losses.
 */
final class Mapper
{
    public const ROLES = ['hero', 'article', 'text', 'faq', 'image', 'form', 'list', 'child_pages'];
    private const MAX_BLOCKS = 100;
    private const MAX_GALLERY = 24;
    /** Pictures of a gallery put into the text of an article (figure after figure). */
    private const MAX_GALLERY_INLINE = 120;
    /** A new h2 starts a new article block only when the current one has this much text; otherwise it stays inside. */
    private const SECTION_MIN = 2000;
    /** Neighbouring article blocks shorter than this together are joined into one. */
    private const MERGE_UNDER = 4000;
    /** A picture narrower than this (by its attributes or a "-150x150" size in the file name) stays inside the text. */
    private const SMALL_IMAGE = 500;

    /** @var array<string,string> role => block type */
    private array $roles;
    /** @var list<array{what:string,why:string,source:string}> */
    private array $losses = [];
    /** @var array<string,int> */
    private array $ids = [];
    /** @var (callable(string):?string)|null */
    private $link;
    /** @var list<string> anchors of the elements consumed since the last block */
    private array $pending = [];
    /** @var array<string,string> anchor of the source (a Tilda record "rec123", an <a name>) => id of the block it went to */
    private array $anchors = [];
    /** @var array<string,bool> anchors the menus of the site lead to */
    private array $wanted = [];

    /**
     * @param array<string,array<string,mixed>> $blocks theme.json "blocks"
     * @param (callable(string):?string)|null $link absolute old URL => new address on this site, null to keep the link
     */
    public function __construct(private array $blocks, ?callable $link = null)
    {
        $this->roles = self::detectRoles($blocks);
        $this->link = $link;
    }

    /** @return array<string,string> */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @param array<string,array<string,mixed>> $blocks
     * @return array<string,string> role => block type
     */
    public static function detectRoles(array $blocks): array
    {
        $roles = [];
        foreach ($blocks as $type => $spec) {
            $hint = is_string($spec['import'] ?? null) ? $spec['import'] : '';
            if (in_array($hint, self::ROLES, true) && !isset($roles[$hint])) {
                $roles[$hint] = (string)$type;
            }
        }
        $guess = static function (callable $test) use ($blocks, &$roles): ?string {
            foreach ($blocks as $type => $spec) {
                if (in_array((string)$type, $roles, true) || isset($spec['import'])) {
                    continue;
                }
                if ($test((string)$type, (array)($spec['fields'] ?? []))) {
                    return (string)$type;
                }
            }
            return null;
        };
        $has = static fn(array $fields, string $t): ?string => array_key_first(array_filter($fields, static fn($f): bool => ($f['type'] ?? 'string') === $t));
        $tests = [
            'child_pages' => static fn(string $type, array $f): bool => $type === 'child_pages' || isset($f['parent']) && ($f['parent']['type'] ?? '') === 'string' && count($f) <= 3,
            'hero' => static fn(string $type, array $f): bool => isset($f['h1']) || in_array($type, ['hero', 'cover', 'banner', 'first_screen'], true) && $has($f, 'string') !== null,
            'article' => static fn(string $type, array $f): bool => $has($f, 'richtext') !== null,
            'faq' => static function (string $type, array $f): bool {
                foreach ($f as $field) {
                    if (($field['type'] ?? '') === 'list') {
                        $of = (array)($field['of'] ?? []);
                        $names = array_keys($of);
                        if (count($of) >= 2 && (in_array('q', $names, true) || in_array('question', $names, true)) || str_contains($type, 'faq')) {
                            return true;
                        }
                    }
                }
                return false;
            },
            'image' => static function (string $type, array $f): bool {
                foreach ($f as $field) {
                    if (($field['type'] ?? '') === 'url' && !empty($field['media']) && !empty($field['required'])) {
                        return true;
                    }
                }
                return false;
            },
            'form' => static fn(string $type, array $f): bool => preg_match('~(form|lead|zayavk|callback|contact)~', $type) === 1 || isset($f['button_text'], $f['success_text']),
            'text' => static function (string $type, array $f): bool {
                foreach ($f as $field) {
                    if (($field['type'] ?? '') === 'list') {
                        return false;
                    }
                }
                foreach ($f as $field) {
                    if (($field['type'] ?? '') === 'text' && !empty($field['required'])) {
                        return true;
                    }
                }
                return $type === 'text';
            },
            'list' => static function (string $type, array $f): bool {
                foreach ($f as $field) {
                    if (($field['type'] ?? '') === 'list' && count((array)($field['of'] ?? [])) >= 1) {
                        return true;
                    }
                }
                return false;
            },
        ];
        foreach ($tests as $role => $test) {
            if (!isset($roles[$role]) && ($type = $guess($test)) !== null) {
                $roles[$role] = $type;
            }
        }
        return $roles;
    }

    /**
     * @param array<string,mixed> $page neutral page
     * @param array{children_of?:string,children?:list<array{title:string,url:string}>} $extra
     *        children_of: the slug whose children this page lists (a blog section); children: the posts, for themes without child_pages
     * @return array{blocks:list<array{id:string,type:string,data:array<string,mixed>}>,losses:list<array{what:string,why:string,source:string}>}
     */
    public function map(array $page, array $extra = []): array
    {
        $this->losses = [];
        $this->ids = [];
        $this->pending = [];
        $this->anchors = [];
        foreach ((array)($page['notes'] ?? []) as $note) {
            $this->losses[] = ['what' => (string)($note['what'] ?? ''), 'why' => (string)($note['why'] ?? ''), 'source' => (string)($note['source'] ?? '')];
        }
        $els = array_map(fn(array $el): array => $this->relink($el), (array)$page['elements']);
        $blocks = [];

        // The first screen: a hero element, else the first h1, else the title of the page.
        $first = $els[0] ?? null;
        $heroEl = null;
        if ($first !== null && $first['type'] === 'hero') {
            $heroEl = array_shift($els);
        } else {
            $title = mb_strtolower(Neutral::line((string)$page['title']));
            foreach ($els as $i => $el) {
                // The page h1, or a smaller heading that repeats the title (news templates of Bitrix print it as h3).
                if ($el['type'] === 'heading' && ((int)$el['level'] === 1 || ($title !== '' && mb_strtolower((string)$el['text']) === $title))) {
                    $heroEl = Neutral::el('hero', ['h1' => $el['text'], 'subtitle' => '', 'cta_text' => '', 'cta_url' => '', 'image' => ''], $el['source']);
                    array_splice($els, $i, 1);
                    break;
                }
                if ($i >= 2) {
                    break;
                }
            }
        }
        if ($heroEl === null && (string)$page['title'] !== '') {
            $heroEl = Neutral::el('hero', ['h1' => (string)$page['title'], 'subtitle' => '', 'cta_text' => '', 'cta_url' => '', 'image' => ''], 'заголовок страницы');
        }
        if ($heroEl !== null) {
            if (($heroEl['h1'] ?? '') === '') {
                $heroEl['h1'] = (string)$page['title'];
            }
            $this->mark($heroEl);
            array_push($blocks, ...$this->hero($heroEl));
        }

        // A hero further down the page is not a first screen: its parts join the flow.
        $flat = [];
        foreach ($els as $el) {
            if ($el['type'] === 'hero') {
                if (($el['h1'] ?? '') !== '') {
                    $flat[] = Neutral::el('heading', ['level' => 2, 'text' => $el['h1']], $el['source']);
                }
                if (($el['subtitle'] ?? '') !== '') {
                    $flat[] = Neutral::el('paragraph', ['html' => nl2br(htmlspecialchars((string)$el['subtitle'], ENT_QUOTES), false)], $el['source']);
                }
                if (($el['cta_text'] ?? '') !== '' && ($el['cta_url'] ?? '') !== '') {
                    $flat[] = Neutral::el('button', ['text' => $el['cta_text'], 'url' => $el['cta_url']], $el['source']);
                }
                if (($el['image'] ?? '') !== '') {
                    $flat[] = Neutral::el('image', ['src' => $el['image'], 'alt' => (string)($el['h1'] ?? ''), 'caption' => ''], $el['source']);
                }
                continue;
            }
            if ($el['type'] === 'heading' && (int)$el['level'] === 1) {
                $el['level'] = 2;
            }
            $flat[] = $el;
        }

        $flow = [];
        foreach ($flat as $i => $el) {
            $type = $el['type'];
            if ($type === 'image' && !$this->inline($flat, $i)) {
                array_push($blocks, ...$this->flush($flow, $page));
                $this->mark($el);
                array_push($blocks, ...$this->image($el, (string)$page['title']));
                continue;
            }
            if ($type === 'gallery' && isset($this->roles['article'])) {
                // A gallery goes into the text as figures one after another: one block, not a block per picture.
                if (count((array)$el['images']) > self::MAX_GALLERY_INLINE) {
                    $this->loss('Галерея ' . $el['source'], 'взяты первые ' . self::MAX_GALLERY_INLINE . ' картинок из ' . count((array)$el['images']), $el['source']);
                    $el['images'] = array_slice((array)$el['images'], 0, self::MAX_GALLERY_INLINE);
                }
                $flow[] = $el;
                continue;
            }
            if ($type === 'gallery') {
                array_push($blocks, ...$this->flush($flow, $page));
                $this->mark($el);
                $images = (array)$el['images'];
                if (count($images) > self::MAX_GALLERY) {
                    $this->loss('Галерея ' . $el['source'], 'взяты первые ' . self::MAX_GALLERY . ' картинок из ' . count($images), $el['source']);
                    $images = array_slice($images, 0, self::MAX_GALLERY);
                }
                foreach ($images as $img) {
                    array_push($blocks, ...$this->image(Neutral::el('image', $img, $el['source']), (string)$page['title']));
                }
                continue;
            }
            if ($type === 'faq') {
                // A heading right before the questions is their heading.
                $heading = '';
                $last = $flow === [] ? null : $flow[count($flow) - 1];
                if ($last !== null && $last['type'] === 'heading') {
                    $heading = (string)$last['text'];
                    array_pop($flow);
                }
                array_push($blocks, ...$this->flush($flow, $page));
                if ($heading !== '') {
                    $this->mark($last);
                }
                $this->mark($el);
                array_push($blocks, ...$this->faq($el, $heading, $page));
                continue;
            }
            if ($type === 'form') {
                $heading = '';
                $last = $flow === [] ? null : $flow[count($flow) - 1];
                if ($last !== null && $last['type'] === 'heading' && isset($this->roles['form'])) {
                    $heading = (string)$last['text'];
                    array_pop($flow);
                }
                array_push($blocks, ...$this->flush($flow, $page));
                if ($heading !== '') {
                    $this->mark($last);
                }
                $this->mark($el);
                array_push($blocks, ...$this->form($el, $heading));
                continue;
            }
            if ($type === 'embed') {
                $this->loss(Neutral::describe($el), 'встроенные плееры и карты чужих сайтов не переносятся: на странице ссылка', $el['source']);
            } elseif ($type === 'raw') {
                $this->loss('Не разобранный фрагмент', (string)($el['note'] ?? 'разметка не распознана') . ': перенесён как текст', $el['source']);
            }
            $flow[] = $el;
        }
        array_push($blocks, ...$this->flush($flow, $page));
        $blocks = $this->mergeArticles($blocks);

        if (($extra['children_of'] ?? '') !== '' || ($extra['children'] ?? []) !== []) {
            array_push($blocks, ...$this->children((string)($extra['children_of'] ?? ''), (array)($extra['children'] ?? [])));
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            $this->loss('Блоки после ' . self::MAX_BLOCKS . '-го', 'на странице не больше ' . self::MAX_BLOCKS . ' блоков, лишние (' . (count($blocks) - self::MAX_BLOCKS) . ') не перенесены', '');
            $blocks = array_slice($blocks, 0, self::MAX_BLOCKS);
        }
        $ids = array_column($blocks, 'id');
        return ['blocks' => $blocks, 'losses' => $this->losses, 'anchors' => array_filter($this->anchors, static fn(string $id): bool => in_array($id, $ids, true))];
    }

    /**
     * Anchors an element brings: the record of the source it came from (Tilda "rec123" in its source label) and the
     * names the extractor gives in "anchor". They go to the next block made.
     *
     * @param array<string,mixed> $el
     */
    private function mark(array $el): void
    {
        foreach (self::anchorNames($el) as $name) {
            if ($name !== '' && !isset($this->anchors[$name]) && !in_array($name, $this->pending, true)) {
                $this->pending[] = $name;
            }
        }
    }

    /** @param array<string,mixed> $el @return list<string> */
    private static function anchorNames(array $el): array
    {
        $names = array_map('strval', (array)($el['anchor'] ?? []));
        if (preg_match('~^rec(\d+)\b~', (string)($el['source'] ?? ''), $m) === 1) {
            $names[] = 'rec' . $m[1];
        }
        return $names;
    }

    /**
     * Anchors the menus of the site lead to (#service, #rec123): the parts that carry them start their own blocks.
     *
     * @param list<string> $names
     */
    public function wantAnchors(array $names): void
    {
        $this->wanted = array_fill_keys(array_map('strval', $names), true);
    }

    /** A wanted anchor that no block has yet. @param array<string,mixed> $el */
    private function wanted(array $el): bool
    {
        foreach (self::anchorNames($el) as $name) {
            if (isset($this->wanted[$name]) && !isset($this->anchors[$name]) && !in_array($name, $this->pending, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Neighbouring article blocks that are short together become one (the heading of the second goes into the text),
     * so that a page of many small sections is not a column of tiny blocks.
     *
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    private function mergeArticles(array $blocks): array
    {
        $type = $this->roles['article'] ?? $this->roles['text'] ?? null;
        if ($type === null) {
            return $blocks;
        }
        $rich = isset($this->roles['article']);
        [$bodyField, $headingField, $max] = $this->bodyFields($type, $rich);
        if ($bodyField === null) {
            return $blocks;
        }
        $limit = min(self::MERGE_UNDER, $max);
        $out = [];
        foreach ($blocks as $b) {
            $prev = $out === [] ? null : $out[count($out) - 1];
            if ($prev !== null && $prev['type'] === $type && $b['type'] === $type && isset($prev['data'][$bodyField], $b['data'][$bodyField])) {
                $heading = $headingField !== null ? (string)($b['data'][$headingField] ?? '') : '';
                $add = $heading === '' ? '' : ($rich ? '<h2>' . htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5) . '</h2>' . "\n" : $heading . "\n\n");
                $body = (string)$prev['data'][$bodyField] . ($rich ? "\n" : "\n\n") . $add . (string)$b['data'][$bodyField];
                $extra = array_diff(array_keys($b['data']), [$bodyField, (string)$headingField]);
                $target = array_filter($this->anchors, fn(string $id, string $name): bool => $id === $b['id'] && isset($this->wanted[$name]), ARRAY_FILTER_USE_BOTH);
                if (mb_strlen($body) <= $limit && $extra === [] && $target === []) {
                    $out[count($out) - 1]['data'][$bodyField] = $body;
                    foreach ($this->anchors as $name => $id) {
                        if ($id === $b['id']) {
                            $this->anchors[$name] = $prev['id'];
                        }
                    }
                    continue;
                }
            }
            $out[] = $b;
        }
        return $out;
    }

    /** Body field, heading field and the body limit of a text block type. @return array{0:?string,1:?string,2:int} */
    private function bodyFields(string $type, bool $rich): array
    {
        $fields = $this->fields($type);
        $bodyField = null;
        foreach ($fields as $name => $f) {
            if (($f['type'] ?? '') === ($rich ? 'richtext' : 'text')) {
                $bodyField = $name;
                if (!empty($f['required'])) {
                    break;
                }
            }
        }
        $headingField = $this->pick($fields, ['heading', 'title', 'h2'], ['string']);
        $max = $bodyField === null ? 0 : (int)($fields[$bodyField]['max'] ?? ($rich ? 50000 : 5000));
        return [$bodyField, $headingField, $max];
    }

    /** Width of a picture by its attributes or the size in its file name (WordPress "-150x150"), 0 when unknown. @param array<string,mixed> $el */
    private static function imageWidth(array $el): int
    {
        if ((int)($el['width'] ?? 0) > 0) {
            return (int)$el['width'];
        }
        if (preg_match('~-(\d{2,4})x(\d{2,4})(-[a-z0-9]{1,6})?\.(jpe?g|png|webp|gif|avif)$~i', (string)parse_url((string)($el['src'] ?? ''), PHP_URL_PATH), $m) === 1) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * The page title without the name of the old site at its end ("О нас - Кофейня Зерно" on a site "Кофейня Зерно"):
     * the core adds the name of the new site itself. Empty when only the page title is left (the core uses the title).
     */
    public static function metaTitle(string $metaTitle, string $title, string $siteTitle): string
    {
        $metaTitle = Neutral::line($metaTitle);
        $site = Neutral::line($siteTitle);
        if ($site !== '' && mb_strlen($metaTitle) > mb_strlen($site)) {
            $quoted = preg_quote($site, '~');
            $cut = preg_replace('~\s*(\s[-|\x{2013}\x{2014}\x{00BB}\x{00B7}/]\s*|\s::\s*|:\s+)' . $quoted . '\s*$~iu', '', $metaTitle);
            if (is_string($cut) && $cut !== '' && $cut !== $metaTitle) {
                $metaTitle = trim($cut);
            }
        }
        $norm = static fn(string $s): string => mb_strtolower(trim(preg_replace('~[\s«»"\'.,:!?]+~u', ' ', $s) ?? $s));
        return $norm($metaTitle) === $norm($title) ? '' : $metaTitle;
    }

    /** A picture inside text: text-like elements on both sides and a theme block that takes inline pictures. @param list<array<string,mixed>> $els */
    private function inline(array $els, int $i): bool
    {
        if (!isset($this->roles['article'])) {
            return false;
        }
        // A small picture (an icon, a badge, a 150x150 thumbnail) is never stretched to a block of its own.
        $width = self::imageWidth($els[$i]);
        if ($width > 0 && $width < self::SMALL_IMAGE) {
            return true;
        }
        // A picture with text or other pictures next to it (a run of photos is a gallery) goes into the text; a big one
        // standing alone keeps its own block.
        $text = ['heading', 'paragraph', 'list', 'quote', 'table', 'button', 'image', 'gallery'];
        $prev = $els[$i - 1] ?? null;
        $next = $els[$i + 1] ?? null;
        return $prev !== null && in_array($prev['type'], $text, true) || $next !== null && in_array($next['type'], $text, true);
    }

    /** Links inside an element: old addresses of imported pages become the new ones. @param array<string,mixed> $el */
    private function relink(array $el): array
    {
        if ($this->link === null) {
            return $el;
        }
        $fix = function (string $html): string {
            return preg_replace_callback('~href="([^"]+)"~', function (array $m): string {
                $new = ($this->link)(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
                return 'href="' . htmlspecialchars($new ?? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5) . '"';
            }, $html) ?? $html;
        };
        foreach (['html', 'a'] as $k) {
            if (isset($el[$k]) && is_string($el[$k])) {
                $el[$k] = $fix($el[$k]);
            }
        }
        if ($el['type'] === 'list') {
            $el['items'] = array_map($fix, (array)$el['items']);
        }
        if ($el['type'] === 'faq') {
            foreach ($el['items'] as &$item) {
                $item['a'] = $fix((string)$item['a']);
            }
            unset($item);
        }
        foreach (['url', 'cta_url'] as $k) {
            if (isset($el[$k]) && is_string($el[$k]) && $el[$k] !== '') {
                $el[$k] = ($this->link)($el[$k]) ?? $el[$k];
            }
        }
        return $el;
    }

    // ---- blocks --------------------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function hero(array $el): array
    {
        $type = $this->roles['hero'] ?? null;
        if ($type === null) {
            $flow = [Neutral::el('heading', ['level' => 2, 'text' => $el['h1']], $el['source'])];
            if (($el['subtitle'] ?? '') !== '') {
                $flow[] = Neutral::el('paragraph', ['html' => htmlspecialchars((string)$el['subtitle'], ENT_QUOTES)], $el['source']);
            }
            return $this->flush($flow, ['title' => $el['h1']]);
        }
        $fields = $this->fields($type);
        $data = [];
        $this->put($data, $fields, $this->pick($fields, ['h1', 'title', 'heading', 'name'], ['string']), (string)$el['h1'], $el['source']);
        $this->put($data, $fields, $this->pick($fields, ['subtitle', 'lead', 'text', 'description', 'descr'], ['text', 'string'], ['h1', 'title', 'heading']), (string)($el['subtitle'] ?? ''), $el['source']);
        $ctaField = $this->pick($fields, ['cta_text', 'button_text', 'button', 'cta'], ['string'], ['h1', 'title', 'heading', 'subtitle']);
        $ctaUrlField = $this->pickUrl($fields, false, ['cta_url', 'button_url', 'link', 'url', 'href']);
        if (($el['cta_text'] ?? '') !== '' && ($el['cta_url'] ?? '') !== '' && $ctaField !== null && $ctaUrlField !== null && self::urlOk((string)$el['cta_url'])) {
            $this->put($data, $fields, $ctaField, (string)$el['cta_text'], $el['source']);
            $data[$ctaUrlField] = (string)$el['cta_url'];
        } elseif (($el['cta_text'] ?? '') !== '') {
            $this->loss('Кнопка «' . $el['cta_text'] . '» на первом экране', $ctaField === null ? 'в блоке темы нет кнопки' : 'адрес кнопки не подходит: ' . $el['cta_url'], $el['source']);
        }
        $out = [];
        $imageField = $this->pickUrl($fields, true, ['image', 'background', 'bg', 'photo', 'picture']);
        $image = (string)($el['image'] ?? '');
        if ($image !== '' && $imageField !== null) {
            $data[$imageField] = $image;
            $image = '';
        }
        $out[] = $this->block($type, $data, $el['source']);
        if ($image !== '') {
            array_push($out, ...$this->image(Neutral::el('image', ['src' => $image, 'alt' => (string)$el['h1'], 'caption' => ''], $el['source']), (string)$el['h1']));
        }
        return array_values(array_filter($out));
    }

    /** @return list<array<string,mixed>> */
    private function image(array $el, string $pageTitle): array
    {
        $type = $this->roles['image'] ?? null;
        if ($type === null) {
            if (isset($this->roles['article'])) {
                $flow = [$el];
                return $this->flush($flow, ['title' => $pageTitle]);
            }
            $this->loss(Neutral::describe($el), 'в теме нет блока для картинки', $el['source']);
            return [];
        }
        $fields = $this->fields($type);
        $src = $this->pickUrl($fields, true, ['src', 'image', 'url']);
        if ($src === null || !self::urlOk((string)$el['src'])) {
            $this->loss(Neutral::describe($el), 'адрес картинки не подходит', $el['source']);
            return [];
        }
        $data = [$src => (string)$el['src']];
        $alt = (string)$el['alt'] !== '' ? (string)$el['alt'] : ((string)($el['caption'] ?? '') !== '' ? (string)$el['caption'] : $pageTitle);
        $this->put($data, $fields, $this->pick($fields, ['alt', 'title'], ['string']), $alt, $el['source']);
        $this->put($data, $fields, $this->pick($fields, ['caption', 'text', 'description'], ['string', 'text'], ['alt']), (string)($el['caption'] ?? ''), $el['source']);
        $block = $this->block($type, $data, $el['source']);
        return $block === null ? [] : [$block];
    }

    /** @return list<array<string,mixed>> */
    private function faq(array $el, string $heading, array $page = []): array
    {
        $type = $this->roles['faq'] ?? null;
        $items = (array)$el['items'];
        if ($type === null) {
            $flow = $heading === '' ? [] : [Neutral::el('heading', ['level' => 2, 'text' => $heading], $el['source'])];
            foreach ($items as $item) {
                $flow[] = Neutral::el('heading', ['level' => 3, 'text' => $item['q']], $el['source']);
                $flow[] = Neutral::el('paragraph', ['html' => (string)$item['a']], $el['source']);
            }
            return $this->flush($flow, ['title' => $heading]);
        }
        $fields = $this->fields($type);
        $listField = null;
        foreach ($fields as $name => $f) {
            if (($f['type'] ?? '') === 'list') {
                $listField = $name;
                break;
            }
        }
        if ($listField === null) {
            return [];
        }
        $of = (array)($fields[$listField]['of'] ?? []);
        $qField = $this->pick($of, ['q', 'question', 'title', 'name'], ['string', 'text']);
        $aField = $this->pick($of, ['a', 'answer', 'text', 'body'], ['text', 'richtext', 'string'], [$qField ?? '']);
        $max = max(1, (int)($fields[$listField]['max'] ?? 50));
        $aType = (string)($of[$aField]['type'] ?? 'text');
        $aMax = (int)($of[$aField]['max'] ?? ($aType === 'text' ? 5000 : ($aType === 'richtext' ? 50000 : 200)));
        $convert = static fn(string $html): string => $aType === 'richtext' ? Sanitizer::rich($html) : Neutral::text($html);
        $out = [];
        $overflow = [];
        foreach (array_chunk($items, $max) as $n => $chunk) {
            $rows = [];
            foreach ($chunk as $item) {
                $row = [];
                $this->put($row, $of, $qField, (string)$item['q'], $el['source']);
                $answer = $convert((string)$item['a']);
                if ($aField !== null && mb_strlen($answer) > $aMax) {
                    // A long answer: its beginning stays in the question, the rest goes into the text under the questions.
                    [$head, $rest] = self::splitHtml((string)$item['a'], $aMax - 10, $convert);
                    if ($head !== '' && $rest !== '') {
                        $answer = $convert($head);
                        $overflow[] = Neutral::el('heading', ['level' => 3, 'text' => (string)$item['q']], $el['source']);
                        $overflow[] = preg_match('~<(p|ul|ol|table|blockquote|h\d)\b~i', $rest) === 1 ? Neutral::el('raw', ['html' => $rest], $el['source']) : Neutral::el('paragraph', ['html' => $rest], $el['source']);
                        $this->loss('Ответ «' . mb_substr(Neutral::line((string)$item['q']), 0, 40) . '»', 'длиннее ' . $aMax . ' знаков: начало в вопросе, продолжение в тексте под вопросами', $el['source']);
                    }
                }
                $this->put($row, $of, $aField, $answer, $el['source']);
                if ($qField !== null && $aField !== null && ($row[$qField] ?? '') !== '' && ($row[$aField] ?? '') !== '') {
                    $rows[] = $row;
                }
            }
            if ($rows === []) {
                continue;
            }
            $data = [$listField => $rows];
            if ($n === 0) {
                $this->put($data, $fields, $this->pick($fields, ['heading', 'title'], ['string']), $heading, $el['source']);
            }
            $block = $this->block($type, $data, $el['source']);
            if ($block !== null) {
                $out[] = $block;
            }
        }
        if ($overflow !== []) {
            array_push($out, ...$this->flush($overflow, $page + ['title' => $heading]));
        }
        return $out;
    }

    /**
     * Splits HTML at paragraph ends (or sentence ends inside one paragraph) so that the first part, converted, fits $max.
     *
     * @param callable(string):string $convert
     * @return array{0:string,1:string}
     */
    private static function splitHtml(string $html, int $max, callable $convert): array
    {
        $parts = preg_split('~(</p>|</ul>|</ol>|</table>|</blockquote>|<br\s*/?>\s*<br\s*/?>)~i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $pieces = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $piece = $parts[$i] . ($parts[$i + 1] ?? '');
            if (trim($piece) !== '') {
                $pieces[] = $piece;
            }
        }
        if (count($pieces) < 2) {
            $pieces = preg_split('~(?<=[.!?…])\s+(?=[^\s<])~u', $html, -1, PREG_SPLIT_NO_EMPTY) ?: [$html];
            $glue = ' ';
        } else {
            $glue = '';
        }
        $head = '';
        foreach ($pieces as $i => $piece) {
            $try = $head === '' ? $piece : $head . $glue . $piece;
            if (mb_strlen($convert($try)) > $max) {
                return [$head, trim(implode($glue, array_slice($pieces, $i)))];
            }
            $head = $try;
        }
        return [$head, ''];
    }

    /** @return list<array<string,mixed>> */
    private function form(array $el, string $heading): array
    {
        $type = $this->roles['form'] ?? null;
        $names = implode(', ', array_filter(array_map(static fn(array $f): string => (string)($f['label'] ?: $f['name']), (array)$el['fields'])));
        if ($type === null) {
            $this->loss('Форма' . ($names !== '' ? ' (' . $names . ')' : ''), 'в теме нет блока формы', $el['source']);
            return [];
        }
        $fields = $this->fields($type);
        $data = [];
        $this->put($data, $fields, $this->pick($fields, ['heading', 'title'], ['string']), $heading, $el['source']);
        $this->put($data, $fields, $this->pick($fields, ['button_text', 'submit', 'button'], ['string'], ['heading', 'title']), (string)($el['submit_text'] ?? ''), $el['source']);
        $standard = true;
        foreach ((array)$el['fields'] as $f) {
            if (preg_match('~(name|phone|tel|mail|message|comment|имя|телефон|почт|сообщ|коммент|вопрос)~iu', $f['name'] . ' ' . $f['label'] . ' ' . $f['type']) !== 1) {
                $standard = false;
            }
        }
        if (!$standard) {
            $this->loss('Поля формы (' . $names . ')', 'форма заменена формой заявки сайта: имя, телефон, сообщение', $el['source']);
        }
        $block = $this->block($type, $data, $el['source']);
        return $block === null ? [] : [$block];
    }

    /** @param list<array{title:string,url:string}> $children @return list<array<string,mixed>> */
    private function children(string $parent, array $children): array
    {
        $type = $this->roles['child_pages'] ?? null;
        if ($type !== null && $parent !== '') {
            $fields = $this->fields($type);
            $data = [];
            $parentField = $this->pick($fields, ['parent', 'slug', 'section'], ['string']);
            if ($parentField !== null) {
                $data[$parentField] = $parent;
            }
            $block = $this->block($type, $data, 'записи раздела');
            return $block === null ? [] : [$block];
        }
        if ($children === []) {
            return [];
        }
        $items = array_map(static fn(array $c): string => '<a href="' . htmlspecialchars($c['url'], ENT_QUOTES) . '">' . htmlspecialchars($c['title'], ENT_QUOTES) . '</a>', $children);
        $flow = [Neutral::el('list', ['ordered' => false, 'items' => $items], 'записи раздела')];
        return $this->flush($flow, ['title' => '']);
    }

    /**
     * The collected text flow into article blocks (or text blocks without richtext).
     *
     * @param list<array<string,mixed>> $flow
     * @param array<string,mixed> $page
     * @return list<array<string,mixed>>
     */
    private function flush(array &$flow, array $page): array
    {
        if ($flow === []) {
            return [];
        }
        $els = $flow;
        $flow = [];
        $type = $this->roles['article'] ?? null;
        $rich = $type !== null;
        if ($type === null) {
            $type = $this->roles['text'] ?? null;
        }
        if ($type === null) {
            foreach ($els as $el) {
                $this->loss(Neutral::describe($el), 'в теме нет блока для текста', (string)$el['source']);
            }
            return [];
        }
        $fields = $this->fields($type);
        $bodyField = null;
        foreach ($fields as $name => $f) {
            if (($f['type'] ?? '') === ($rich ? 'richtext' : 'text')) {
                $bodyField = $name;
                if (!empty($f['required'])) {
                    break;
                }
            }
        }
        if ($bodyField === null) {
            return [];
        }
        $headingField = $this->pick($fields, ['heading', 'title', 'h2'], ['string']);
        $max = (int)($fields[$bodyField]['max'] ?? ($rich ? 50000 : 5000));

        // Split into sections that fit, at element boundaries; a section may start with its heading.
        $out = [];
        $heading = '';
        $parts = [];
        $length = 0;
        $emit = function () use (&$out, &$heading, &$parts, &$length, $type, $bodyField, $headingField, $fields, $rich): void {
            if ($parts === [] && $heading === '') {
                return;
            }
            $body = $rich ? implode("\n", $parts) : implode("\n\n", $parts);
            $data = [];
            if ($headingField !== null && $heading !== '' && $body !== '') {
                $this->put($data, $fields, $headingField, $heading, 'заголовок раздела');
            } elseif ($heading !== '') {
                $body = $rich ? '<h2>' . htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5) . '</h2>' . ($body !== '' ? "\n" . $body : '') : $heading . ($body !== '' ? "\n\n" . $body : '');
            }
            if (trim(strip_tags($body)) === '' && !str_contains($body, '<img')) {
                $heading = '';
                $parts = [];
                $length = 0;
                return;
            }
            $data[$bodyField] = $body;
            $block = $this->block($type, $data, 'текст');
            if ($block !== null) {
                $out[] = $block;
            }
            $heading = '';
            $parts = [];
            $length = 0;
        };
        foreach ($els as $i => $el) {
            // A part a menu leads to (an anchor of the source) starts a block of its own, so that the link lands on it.
            if ($parts !== [] && $this->wanted($el)) {
                $emit();
            }
            if ($el['type'] === 'heading' && (int)$el['level'] <= 2 && $headingField !== null) {
                // A short section keeps the next heading inside its text; a new block starts after enough text.
                if ($parts === [] && $heading === '' || $length >= self::SECTION_MIN) {
                    $emit();
                    $heading = (string)$el['text'];
                    $this->mark($el);
                    continue;
                }
                $el['level'] = 2;
            }
            $piece = $rich ? $this->richPiece($el) : $this->textPiece($el);
            if ($piece === '') {
                $this->mark($el);
                continue;
            }
            if (mb_strlen($piece) > $max) {
                $this->loss(Neutral::describe($el), 'длиннее ' . $max . ' знаков, обрезано', (string)$el['source']);
                $piece = $rich ? Sanitizer::rich(mb_substr($piece, 0, $max - 100)) : mb_substr($piece, 0, $max - 1) . '…';
            }
            if ($length + mb_strlen($piece) + 2 > $max) {
                $keep = $heading;
                $emit();
                $heading = $keep !== '' ? $keep . ' (продолжение)' : '';
            }
            $this->mark($el);
            $parts[] = $piece;
            $length += mb_strlen($piece) + 2;
        }
        $emit();
        return $out;
    }

    /** HTML of one element inside an article. */
    private function richPiece(array $el): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        return match ($el['type']) {
            'heading' => '<h' . max(2, min(4, (int)$el['level'])) . '>' . $e((string)$el['text']) . '</h' . max(2, min(4, (int)$el['level'])) . '>',
            'paragraph' => str_starts_with(ltrim((string)$el['html']), '<pre') ? (string)$el['html'] : '<p>' . $el['html'] . '</p>',
            'list' => ($el['ordered'] ? '<ol>' : '<ul>') . implode('', array_map(static fn(string $i): string => '<li>' . $i . '</li>', (array)$el['items'])) . ($el['ordered'] ? '</ol>' : '</ul>'),
            'quote' => '<blockquote><p>' . $el['html'] . '</p>' . (($el['cite'] ?? '') !== '' ? '<p><em>' . $e((string)$el['cite']) . '</em></p>' : '') . '</blockquote>',
            'table' => Sanitizer::rich((string)$el['html']),
            'image' => '<figure><img src="' . $e((string)$el['src']) . '" alt="' . $e((string)$el['alt']) . '"' . self::sizeAttrs($el) . '>' . (($el['caption'] ?? '') !== '' ? '<figcaption>' . $e((string)$el['caption']) . '</figcaption>' : '') . '</figure>',
            'gallery' => implode('', array_map(static fn(array $i): string => '<figure><img src="' . $e((string)$i['src']) . '" alt="' . $e((string)($i['alt'] ?? '')) . '"' . self::sizeAttrs($i) . '>' . ((string)($i['caption'] ?? '') !== '' ? '<figcaption>' . $e((string)$i['caption']) . '</figcaption>' : '') . '</figure>', (array)$el['images'])),
            'button' => '<p><a href="' . $e((string)$el['url']) . '">' . $e((string)$el['text']) . '</a></p>',
            'embed' => '<p><a href="' . $e((string)$el['src']) . '">' . ($el['kind'] === 'video' ? 'Видео' : ($el['kind'] === 'map' ? 'Карта' : 'Материал')) . ': ' . $e((string)$el['src']) . '</a></p>',
            'raw' => Sanitizer::rich((string)$el['html']),
            default => '',
        };
    }

    /** width and height of a small picture, so that the page does not stretch it to the column. @param array<string,mixed> $el */
    private static function sizeAttrs(array $el): string
    {
        $w = self::imageWidth($el);
        if ($w <= 0 || $w >= self::SMALL_IMAGE) {
            return '';
        }
        $h = (int)($el['height'] ?? 0);
        if ($h <= 0 && preg_match('~-(\d{2,4})x(\d{2,4})(-[a-z0-9]{1,6})?\.[a-z]+$~i', (string)parse_url((string)($el['src'] ?? ''), PHP_URL_PATH), $m) === 1) {
            $h = (int)$m[2];
        }
        return ' width="' . $w . '"' . ($h > 0 ? ' height="' . $h . '"' : '');
    }

    /** Plain text of one element for a text block. */
    private function textPiece(array $el): string
    {
        $links = static function (string $html): string {
            $html = preg_replace_callback('~<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is', static function (array $m): string {
                $text = trim(strip_tags($m[2]));
                $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
                return $text === '' || $text === $href || str_starts_with($href, '#') ? $text : $text . ' (' . $href . ')';
            }, $html) ?? $html;
            return Neutral::text($html);
        };
        return match ($el['type']) {
            'heading' => (string)$el['text'],
            'paragraph', 'raw' => $links((string)$el['html']),
            'list' => implode("\n", array_map(static fn(string $i): string => '- ' . $links($i), (array)$el['items'])),
            'quote' => '«' . $links((string)$el['html']) . '»' . (($el['cite'] ?? '') !== '' ? ' - ' . $el['cite'] : ''),
            'table' => self::tableText((string)$el['html']),
            'button' => $el['text'] . ': ' . $el['url'],
            'embed' => ($el['kind'] === 'video' ? 'Видео' : ($el['kind'] === 'map' ? 'Карта' : 'Материал')) . ': ' . $el['src'],
            'image', 'gallery' => '',
            default => '',
        };
    }

    private static function tableText(string $html): string
    {
        $rows = [];
        if (preg_match_all('~<tr[^>]*>(.*?)</tr>~is', $html, $m) > 0) {
            foreach ($m[1] as $row) {
                preg_match_all('~<t[hd][^>]*>(.*?)</t[hd]>~is', $row, $cells);
                $rows[] = implode(' | ', array_map(static fn(string $c): string => Neutral::line(strip_tags($c)), $cells[1]));
            }
        }
        return implode("\n", $rows);
    }

    // ---- fields ---------------------------------------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> */
    private function fields(string $type): array
    {
        return (array)($this->blocks[$type]['fields'] ?? []);
    }

    /**
     * A field by preferred names, then the first one of the wanted types that is not excluded.
     *
     * @param array<string,array<string,mixed>> $fields
     * @param list<string> $names
     * @param list<string> $types
     * @param list<string> $exclude
     */
    private function pick(array $fields, array $names, array $types, array $exclude = []): ?string
    {
        foreach ($names as $n) {
            if (isset($fields[$n]) && in_array($fields[$n]['type'] ?? 'string', $types, true) && !in_array($n, $exclude, true)) {
                return $n;
            }
        }
        foreach ($fields as $n => $f) {
            if (in_array($f['type'] ?? 'string', $types, true) && !in_array($n, $exclude, true) && !in_array($n, $names, true) && empty($f['media'])) {
                if (preg_match('~(url|link|href|alt|id|size|style|color|theme|class)~', (string)$n) !== 1) {
                    return (string)$n;
                }
            }
        }
        return null;
    }

    /** @param array<string,array<string,mixed>> $fields @param list<string> $names */
    private function pickUrl(array $fields, bool $media, array $names): ?string
    {
        foreach ($names as $n) {
            if (isset($fields[$n]) && ($fields[$n]['type'] ?? '') === 'url' && !empty($fields[$n]['media']) === $media) {
                return $n;
            }
        }
        foreach ($fields as $n => $f) {
            if (($f['type'] ?? '') === 'url' && !empty($f['media']) === $media) {
                return (string)$n;
            }
        }
        return null;
    }

    /**
     * Puts a value into a field, cut to the field's max with a note.
     *
     * @param array<string,mixed> $data
     * @param array<string,array<string,mixed>> $fields
     */
    private function put(array &$data, array $fields, ?string $field, string $value, string $source): void
    {
        if ($field === null || trim($value) === '') {
            return;
        }
        $spec = $fields[$field] ?? [];
        $type = (string)($spec['type'] ?? 'string');
        $value = $type === 'string' ? Neutral::line($value) : trim(str_replace("\r\n", "\n", $value));
        $value = (string)preg_replace($type === 'string' ? '/[\x00-\x1F\x7F]/' : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $max = (int)($spec['max'] ?? ($type === 'text' ? 5000 : ($type === 'richtext' ? 50000 : 200)));
        if (mb_strlen($value) > $max) {
            $this->loss('Текст «' . mb_substr(Neutral::line(strip_tags($value)), 0, 40) . '…»', 'не помещается в поле ' . $field . ' (до ' . $max . ' знаков), обрезано', $source);
            $value = rtrim(mb_substr($value, 0, max(1, $max - 1))) . '…';
        }
        $data[$field] = $value;
    }

    /** A block when its required fields are there; otherwise a note and null. @param array<string,mixed> $data */
    private function block(string $type, array $data, string $source): ?array
    {
        foreach ($this->fields($type) as $name => $spec) {
            if (!empty($spec['required']) && (!isset($data[$name]) || $data[$name] === '' || $data[$name] === [])) {
                $this->loss('Блок ' . $type, 'нет обязательного поля ' . $name, $source);
                return null;
            }
        }
        $n = $this->ids[$type] = ($this->ids[$type] ?? 0) + 1;
        $prefix = preg_replace('~[^a-z0-9_]~', '_', strtolower($type)) ?: 'b';
        if (!preg_match('~^[a-z]~', $prefix)) {
            $prefix = 'b_' . $prefix;
        }
        $id = substr($prefix, 0, 34) . '_' . $n;
        foreach ($this->pending as $name) {
            $this->anchors[$name] = $id;
        }
        $this->pending = [];
        return ['id' => $id, 'type' => $type, 'data' => $data];
    }

    private function loss(string $what, string $why, string $source): void
    {
        $this->losses[] = ['what' => $what, 'why' => $why, 'source' => $source];
    }

    public static function urlOk(string $url): bool
    {
        if ($url === '' || str_starts_with($url, 'local:')) {
            return $url !== '';
        }
        if (class_exists('AgeCMS\\SchemaValidator') && method_exists('AgeCMS\\SchemaValidator', 'urlValid')) {
            return \AgeCMS\SchemaValidator::urlValid($url);
        }
        return preg_match('~^(https?://[^\s<>"\'\\\\]+|/(?![/\\\\])[^\s<>"\'\\\\]*|#[^\s<>"\'\\\\]*|mailto:[^\s<>"\'\\\\]+|tel:[+0-9()\-]+)$~u', $url) === 1;
    }
}
