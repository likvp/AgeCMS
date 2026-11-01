<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Copy;

use AgeCMS\Modules\Importer\ImportException;
use AgeCMS\Modules\Importer\Planner;

/**
 * Turns a page file of a capture package into the data of the system block "site_copy" (AgeCMS\CopyPage): the parts of the
 * document, addresses of files pointing at the folder of this copy, links to other pages of the old site pointing at their new
 * addresses, clean text and the visible text for search.
 */
final class CopyBuilder
{
    /** The address prefix the capture tool writes in front of every saved file. */
    public const SENTINEL = '/__agecms_copy__/';

    /**
     * The four parts of a page file made by the capture tool (it writes "<html ...><head>...</head><body ...>...</body></html>").
     *
     * @return array{html_attrs:string,head:string,body_attrs:string,body:string}
     */
    public static function split(string $html): array
    {
        $attr = '((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)';
        if (preg_match('~<html' . $attr . '>~i', $html, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new ImportException('В файле страницы нет тега html.');
        }
        $htmlAttrs = $m[1][0];
        $headAt = stripos($html, '<head', $m[0][1]);
        $bodySplit = strpos($html, '</head><body');
        if ($bodySplit === false) {
            if (preg_match('~</head>\s*<body~i', $html, $mm, PREG_OFFSET_CAPTURE) !== 1) {
                throw new ImportException('В файле страницы нет разделения на head и body.');
            }
            $bodySplit = $mm[0][1];
        }
        $headStart = $headAt === false ? $m[0][1] + strlen($m[0][0]) : (int)strpos($html, '>', $headAt) + 1;
        $head = substr($html, $headStart, $bodySplit - $headStart);
        $rest = substr($html, $bodySplit);
        if (preg_match('~^</head>\s*<body' . $attr . '>~i', $rest, $b) !== 1) {
            throw new ImportException('В файле страницы нет тега body.');
        }
        $bodyAttrs = $b[1];
        $bodyStart = strlen($b[0]);
        $end = strripos($rest, '</body>');
        $body = substr($rest, $bodyStart, ($end === false ? strlen($rest) : $end) - $bodyStart);
        return ['html_attrs' => trim($htmlAttrs), 'head' => trim($head), 'body_attrs' => trim($bodyAttrs), 'body' => trim($body)];
    }

    /** Valid UTF-8 without control characters (the document schema refuses them). */
    public static function clean(string $s): string
    {
        $s = (string)@iconv('UTF-8', 'UTF-8//IGNORE', $s);
        $s = str_replace("\r\n", "\n", $s);
        return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    }

    /** Visible text of the page body, for the search index and the preview. */
    public static function text(string $body): string
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

    /**
     * Links to pages of the old site inside href attributes (a, area) go to the new addresses; a link to a page that was not
     * imported keeps its path (it works when the new site takes the old domain).
     *
     * @param array<string,string> $links Planner links: old key => new path
     */
    public static function links(string $html, array $links, string $source): string
    {
        if ($links === []) {
            return $html;
        }
        return (string)preg_replace_callback('~(<(?:a|area)\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?\shref=)("([^"]*)"|\'([^\']*)\')~i', static function (array $m) use ($links, $source): string {
            $quote = $m[2][0];
            $raw = $m[4] ?? $m[3];
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_starts_with($url, '/assets/uploads/')) {
                return $m[0];
            }
            $new = Planner::resolve($url, $links, $source);
            if ($new === null) {
                return $m[0];
            }
            return $m[1] . $quote . htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $quote;
        }, $html);
    }

    /**
     * @param array<string,string> $links
     * @return array<string,mixed> the data of the block (field names of CopyPage::schema())
     */
    public static function data(string $file, string $key, array $links, string $source, string $mode, string $mobileFile = ''): array
    {
        $parts = self::part($file, $key, $links, $source);
        $data = [
            'copy_html_attrs' => $parts['html_attrs'],
            'copy_body_attrs' => $parts['body_attrs'],
            'copy_head' => $parts['head'],
            'copy_body' => $parts['body'],
            'copy_text' => self::text($parts['body']),
            'copy_source' => mb_substr($source, 0, 500),
            'copy_mode' => $mode === 'dom' ? 'dom' : 'source',
            'copy_key' => $key,
        ];
        if ($mobileFile !== '' && is_file($mobileFile)) {
            $m = self::part($mobileFile, $key, $links, $source);
            $data += ['copy_m_html_attrs' => $m['html_attrs'], 'copy_m_body_attrs' => $m['body_attrs'], 'copy_m_head' => $m['head'], 'copy_m_body' => $m['body']];
        }
        return $data;
    }

    /** @param array<string,string> $links @return array{html_attrs:string,head:string,body_attrs:string,body:string} */
    private static function part(string $file, string $key, array $links, string $source): array
    {
        $html = (string)@file_get_contents($file);
        if ($html === '') {
            throw new ImportException('Файл страницы пуст или не читается.');
        }
        $parts = self::split(self::clean($html));
        $prefix = \AgeCMS\CopyPage::url($key);
        foreach ($parts as $k => $v) {
            $v = str_replace(self::SENTINEL, $prefix, $v);
            $parts[$k] = in_array($k, ['head', 'body'], true) ? ($k === 'body' ? self::links($v, $links, $source) : $v) : $v;
        }
        return $parts;
    }
}
