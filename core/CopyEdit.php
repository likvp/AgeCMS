<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Editing the text of a copied page (CopyPage) on the page itself. The body of a copy is foreign markup stored as it is, so the
 * edit never goes through a block editor: the markup is scanned, the elements a person can change are numbered, and a change is
 * written back into the exact place of the stored markup.
 *
 *  - mark() puts data-ag-e="N" on the elements that can be edited: headings, paragraphs, list items, links, buttons, table cells,
 *    inline text (span, b, i...) that have text of their own, links (their address) and pictures (their file).
 *  - apply() takes the changes of the page in the form {n, from, to, href, src, alt}. "from" is what the person saw: it is compared with
 *    what the markup holds, so a page changed by its own scripts or by another editor is refused, not corrupted. "to" are the new
 *    texts of the same text nodes. Only the text nodes of the element itself change (a nested element is its own unit), no markup
 *    comes from the person: texts are escaped, addresses are checked, so a change cannot bring a script onto the page.
 *
 * The scan is a forgiving tokenizer, not a full HTML parser; everything it cannot place with certainty (a mismatch of the texts) is
 * skipped and reported, never guessed.
 */
final class CopyEdit
{
    /** Elements that are edited as text when they hold text of their own. */
    private const TEXT = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'a', 'button', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'small', 'mark', 'sup', 'sub',
        'label', 'td', 'th', 'dt', 'dd', 'blockquote', 'figcaption', 'summary', 'caption', 'legend', 'div', 'cite', 'address', 'time', 'pre'];
    /** Elements whose content is not markup of the page: skipped with everything inside. */
    private const RAW = ['script', 'style', 'textarea', 'title', 'noscript', 'template', 'iframe', 'select', 'svg', 'math', 'object', 'canvas', 'video', 'audio', 'head'];
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param'];
    private const BLOCKS = ['address', 'article', 'aside', 'blockquote', 'div', 'dl', 'fieldset', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul'];
    public const MAX_TEXT = 20000;
    /** Lazy-loading attributes that would keep showing the old picture after a new one is chosen. */
    private const LAZY = ['srcset', 'sizes', 'data-src', 'data-srcset', 'data-original', 'data-lazy-src', 'data-lazy', 'data-img'];

    /**
     * @return array{els:list<array<string,mixed>>}
     */
    public static function scan(string $html): array
    {
        $els = [];
        $stack = []; // indexes into $els of the open elements
        $len = strlen($html);
        $pos = 0;
        $textFrom = 0;
        $flush = static function (int $to) use (&$els, &$stack, &$textFrom, $html): void {
            if ($to > $textFrom && $stack !== []) {
                $raw = substr($html, $textFrom, $to - $textFrom);
                if (self::visible(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                    $els[$stack[count($stack) - 1]]['texts'][] = [$textFrom, $to];
                }
            }
        };
        while ($pos < $len) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false) {
                break;
            }
            $next = $html[$lt + 1] ?? '';
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $flush($lt);
                $end = strpos($html, '-->', $lt + 4);
                $pos = $textFrom = $end === false ? $len : $end + 3;
                continue;
            }
            if ($next === '!' || $next === '?') {
                $flush($lt);
                $end = strpos($html, '>', $lt);
                $pos = $textFrom = $end === false ? $len : $end + 1;
                continue;
            }
            if ($next === '/') {
                if (preg_match('~</([A-Za-z][A-Za-z0-9:-]*)[^>]*>~A', $html, $m, 0, $lt) === 1) {
                    $flush($lt);
                    self::close($stack, $els, strtolower($m[1]));
                    $pos = $textFrom = $lt + strlen($m[0]);
                    continue;
                }
                $pos = $lt + 1;
                continue;
            }
            if (preg_match('~<([A-Za-z][A-Za-z0-9:-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>~A', $html, $m, 0, $lt) !== 1) {
                $pos = $lt + 1; // a "<" that is only text
                continue;
            }
            $flush($lt);
            $tag = strtolower($m[1]);
            $end = $lt + strlen($m[0]);
            if (in_array($tag, self::RAW, true)) {
                // Everything up to the matching end tag is not ours (svg may nest: count).
                $depth = 1;
                $from = $end;
                $re = '~<(/?)' . preg_quote($tag, '~') . '(?=[\s/>])~i';
                while ($depth > 0 && preg_match($re, $html, $c, PREG_OFFSET_CAPTURE, $from) === 1) {
                    $depth += $c[1][0] === '/' ? -1 : ($tag === 'svg' || $tag === 'math' ? 1 : 0);
                    $from = $c[0][1] + strlen($c[0][0]);
                }
                $gt = $depth === 0 ? strpos($html, '>', $from) : false;
                $pos = $textFrom = $gt === false ? $len : $gt + 1;
                continue;
            }
            self::implicitClose($stack, $els, $tag);
            $els[] = ['tag' => $tag, 'start' => $lt, 'end' => $end, 'attrs' => $m[2], 'texts' => [], 'n' => 0];
            if (!in_array($tag, self::VOID, true)) {
                $stack[] = count($els) - 1;
            }
            $pos = $textFrom = $end;
        }
        $flush($len);
        $n = 0;
        foreach ($els as $i => $el) {
            if ($el['tag'] === 'a' || $el['tag'] === 'img' || ($el['texts'] !== [] && in_array($el['tag'], self::TEXT, true))) {
                $els[$i]['n'] = ++$n;
            }
        }
        return ['els' => $els];
    }

    private static function visible(string $text): bool
    {
        return trim(str_replace("\u{00A0}", ' ', $text)) !== '';
    }

    /** @param list<int> $stack @param list<array<string,mixed>> $els */
    private static function close(array &$stack, array $els, string $tag): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($els[$stack[$i]]['tag'] === $tag) {
                $stack = array_slice($stack, 0, $i);
                return;
            }
        }
    }

    /** The end tags HTML lets out: a new li ends the open li, a block ends an open p, a cell ends a cell. @param list<int> $stack @param list<array<string,mixed>> $els */
    private static function implicitClose(array &$stack, array $els, string $tag): void
    {
        $top = $stack === [] ? '' : $els[$stack[count($stack) - 1]]['tag'];
        $closes = match (true) {
            $tag === 'li' => ['li'],
            $tag === 'dt' || $tag === 'dd' => ['dt', 'dd'],
            $tag === 'td' || $tag === 'th' => ['td', 'th'],
            $tag === 'tr' => ['td', 'th', 'tr'],
            in_array($tag, self::BLOCKS, true) => ['p'],
            default => [],
        };
        while ($stack !== [] && in_array($top, $closes, true)) {
            array_pop($stack);
            $top = $stack === [] ? '' : $els[$stack[count($stack) - 1]]['tag'];
        }
    }

    /** The markup with data-ag-e="N" on every element a person can edit. */
    public static function mark(string $html): string
    {
        $els = array_reverse(array_filter(self::scan($html)['els'], static fn(array $e): bool => $e['n'] > 0));
        foreach ($els as $el) {
            $at = $el['end'] - 1;
            if ($at > 0 && $html[$at - 1] === '/') {
                $at--;
            }
            $html = substr($html, 0, $at) . ' data-ag-e="' . $el['n'] . '"' . substr($html, $at);
        }
        return $html;
    }

    /** A short fingerprint of the stored markup: an edit made on an older version of the page is refused. */
    public static function base(string $html): string
    {
        return substr(sha1($html), 0, 16);
    }

    private static function norm(string $s): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $s)));
    }

    /** An address a link may get: a site address, a page of this site, an anchor, mail or phone. Never javascript: or data:. */
    public static function hrefOk(string $href): bool
    {
        return strlen($href) <= 2000 && preg_match('/[\x00-\x20"\'<>`\\\\]/', $href) !== 1
            && preg_match('~^(https?://[^/?#]+[^\s]*|mailto:[^\s]+|tel:[+0-9()\-]{3,30}|/(?!/)[^\s]*|#[^\s]*)$~i', $href) === 1;
    }

    /** A picture address: a file of this site or an https address. */
    public static function srcOk(string $src): bool
    {
        return strlen($src) <= 2000 && preg_match('/[\x00-\x20"\'<>`\\\\]/', $src) !== 1 && preg_match('~^(/(?!/)[^\s]*|https://[^/?#]+[^\s]*)$~i', $src) === 1;
    }

    /**
     * Writes the changes into the markup.
     *
     * @param list<array<string,mixed>> $edits {n:int, from:list<string>, to:list<string>, href?:string, src?:string, alt?:string}
     * @return array{html:string,applied:int,skipped:list<array{n:int,why:string}>}
     */
    public static function apply(string $html, array $edits): array
    {
        $els = [];
        foreach (self::scan($html)['els'] as $el) {
            if ($el['n'] > 0) {
                $els[$el['n']] = $el;
            }
        }
        $skipped = [];
        $patches = []; // [start, end, replacement]
        $applied = 0;
        foreach ($edits as $edit) {
            $n = (int)($edit['n'] ?? 0);
            $why = null;
            $mine = [];
            $el = $els[$n] ?? null;
            if ($el === null) {
                $skipped[] = ['n' => $n, 'why' => 'такого элемента на странице нет'];
                continue;
            }
            if (array_key_exists('to', $edit)) {
                $from = $edit['from'] ?? null;
                $to = $edit['to'];
                if (!is_array($from) || !is_array($to) || count($from) !== count($el['texts']) || count($to) !== count($el['texts'])) {
                    $why = 'состав текста элемента изменился (для таких правок откройте код страницы)';
                } else {
                    foreach ($el['texts'] as $i => [$s, $e]) {
                        $raw = substr($html, $s, $e - $s);
                        if (!is_string($from[$i]) || !is_string($to[$i]) || self::norm(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== self::norm($from[$i])) {
                            $why = 'страница изменилась после загрузки, обновите её и повторите правку';
                            break;
                        }
                        $new = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace(["\r\n", "\r"], "\n", $to[$i])));
                        if (mb_strlen($new) > self::MAX_TEXT) {
                            $why = 'текст слишком длинный';
                            break;
                        }
                        if (!self::visible($new)) {
                            $why = 'текст нельзя стереть целиком, замените его';
                            break;
                        }
                        preg_match('/^\s*/u', $raw, $lead);
                        preg_match('/\s*$/u', $raw, $trail);
                        $mine[] = [$s, $e, ($lead[0] ?? '') . htmlspecialchars($new, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . ($trail[0] ?? '')];
                    }
                }
            }
            $attrs = [];
            if ($why === null && isset($edit['href'])) {
                if ($el['tag'] !== 'a' || !is_string($edit['href']) || !self::hrefOk(trim($edit['href']))) {
                    $why = 'адрес ссылки не подходит (нужен адрес сайта, страница этого сайта, якорь, почта или телефон)';
                } else {
                    $attrs['href'] = trim($edit['href']);
                }
            }
            if ($why === null && isset($edit['src'])) {
                if ($el['tag'] !== 'img' || !is_string($edit['src']) || !self::srcOk(trim($edit['src']))) {
                    $why = 'адрес картинки не подходит (нужен файл этого сайта или адрес https)';
                } else {
                    $attrs['src'] = trim($edit['src']);
                    foreach (self::LAZY as $lazy) {
                        $attrs[$lazy] = null;
                    }
                }
            }
            if ($why === null && isset($edit['alt'])) {
                if ($el['tag'] !== 'img' || !is_string($edit['alt']) || mb_strlen($edit['alt']) > 500) {
                    $why = 'подпись картинки не подходит';
                } else {
                    $attrs['alt'] = preg_replace('/[\x00-\x1F\x7F]/', ' ', $edit['alt']);
                }
            }
            if ($why !== null) {
                $skipped[] = ['n' => $n, 'why' => $why];
                continue;
            }
            if ($attrs !== []) {
                $open = substr($html, $el['start'], $el['end'] - $el['start']);
                $mine[] = [$el['start'], $el['end'], self::setAttrs($open, $attrs)];
            }
            if ($mine === []) {
                continue;
            }
            foreach ($mine as $p) {
                $patches[] = $p;
            }
            $applied++;
        }
        usort($patches, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        $last = PHP_INT_MAX;
        foreach ($patches as [$s, $e, $r]) {
            if ($e > $last) {
                continue; // overlapping patches cannot happen from different elements; the guard keeps the markup whole
            }
            $html = substr($html, 0, $s) . $r . substr($html, $e);
            $last = $s;
        }
        return ['html' => $html, 'applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Sets (string) or removes (null) attributes of an open tag, keeping the others as they are.
     *
     * @param array<string,string|null> $set
     */
    public static function setAttrs(string $open, array $set): string
    {
        if (preg_match('~^<([A-Za-z][A-Za-z0-9:-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(\s*/?)>$~s', $open, $m) !== 1) {
            return $open;
        }
        [, $tag, $attrs, $close] = $m;
        $kept = [];
        $seen = [];
        $pos = 0;
        $len = strlen($attrs);
        while ($pos < $len) {
            if (preg_match('~\s*([^\s"\'>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?~A', $attrs, $a, 0, $pos) !== 1) {
                $pos++;
                continue;
            }
            $name = strtolower($a[1]);
            $pos += strlen($a[0]);
            if (array_key_exists($name, $set)) {
                $seen[$name] = true;
                if ($set[$name] !== null) {
                    $kept[] = $a[1] . '="' . htmlspecialchars($set[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                }
                continue;
            }
            $kept[] = trim($a[0]);
        }
        foreach ($set as $name => $value) {
            if ($value !== null && !isset($seen[$name])) {
                $kept[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        return '<' . $tag . ($kept === [] ? '' : ' ' . implode(' ', $kept)) . ($close !== '' && str_contains($close, '/') ? ' /' : '') . '>';
    }

    /** Visible text of a body, for the search index (the same rules as the importer applies when it makes a copy). */
    public static function plainText(string $body): string
    {
        $body = (string)preg_replace('~<(script|style|noscript|template|svg|iframe)\b[^>]*>.*?</\1>~is', ' ', $body);
        $body = (string)preg_replace('~<!--.*?-->~s', ' ', $body);
        $body = (string)preg_replace('~<(br|hr)\b[^>]*>|</(p|div|li|h[1-6]|tr|td|th|section|header|footer|article|blockquote|figcaption|pre)>~i', "$0\n", $body);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string)preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string)preg_replace('/ *\n[ \n]*/u', "\n", $text);
        return mb_substr(trim($text), 0, 300000);
    }
}
