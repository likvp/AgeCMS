<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Admin;

use AgeCMS\Actor;
use AgeCMS\AgeCMSException;
use AgeCMS\Content;
use AgeCMS\CopyPage;
use AgeCMS\ModuleRequest;
use AgeCMS\ModuleResponse;
use AgeCMS\Pages;
use AgeCMS\Theme;
use AgeCMS\ValidationException;
use AgeCMS\Modules\Importer\Copy\CopyBuilder;

/**
 * The editor of a page that is an exact copy (/admin/m/importer/copy?slug=...): title, description for search engines, the
 * code of the page (head and body, and the phone version when there is one) in text fields. The code of a copy is foreign code
 * that runs on the page with the permissions of the visitor, so the editor is open only to administrators (the module permission)
 * and says so. Every save is a revision of the page, so the history and its rollback work as for any page.
 */
final class CopyEditor
{
    private const BASE = '/admin/m/importer';

    public static function handle(ModuleRequest $request, Actor $actor): ModuleResponse
    {
        $slug = (string)($request->body['slug'] ?? $request->query['slug'] ?? '');
        $doc = Pages::slugValid($slug) ? Pages::doc($slug) : null;
        if ($slug === '') {
            return ModuleResponse::html('Копия страницы', '<p>' . ag_e('Правка кода открывается со страницы, перенесённой как точная копия: на странице редактирования есть ссылка «править код».') . '</p><p><a href="/admin/pages">Все страницы</a></p>');
        }
        if ($doc === null || !CopyPage::is($doc)) {
            return ModuleResponse::html('Копия страницы', '<div class="notice warn">' . ag_e('Страницы «' . $slug . '» нет или она не является точной копией.') . '</div><p><a href="/admin/pages">Все страницы</a></p>', 404);
        }
        if ($request->method === 'POST') {
            try {
                self::save($slug, $doc, $request->body, $actor);
            } catch (AgeCMSException $e) {
                return self::form($slug, $doc, $e->getMessage(), '', $request->body);
            }
            return self::form($slug, Pages::doc($slug) ?? $doc, '', 'Сохранено. Прежняя версия лежит в истории страницы.');
        }
        return self::form($slug, $doc, '', '');
    }

    /** @param array<string,mixed> $doc @param array<string,mixed> $b */
    private static function save(string $slug, array $doc, array $b, Actor $actor): void
    {
        $data = CopyPage::data($doc);
        $line = static fn(string $k): string => trim((string)preg_replace('/\s+/u', ' ', CopyBuilder::clean((string)($b[$k] ?? ''))));
        foreach (['copy_head', 'copy_body', 'copy_m_head', 'copy_m_body'] as $key) {
            if (array_key_exists($key, $b)) {
                $data[$key] = trim(CopyBuilder::clean((string)$b[$key]));
            }
        }
        if (trim((string)($data['copy_body'] ?? '')) === '') {
            throw new ValidationException(['Код страницы (body) не может быть пустым.']);
        }
        $data['copy_text'] = CopyBuilder::text((string)$data['copy_body']);
        $data['copy_eval'] = !empty($b['copy_eval']);
        $fields = [
            'title' => mb_substr($line('title'), 0, 200),
            'meta_title' => mb_substr($line('meta_title'), 0, 200),
            'meta_description' => mb_substr($line('meta_description'), 0, 500),
            'is_noindex' => !empty($b['is_noindex']) ? 1 : 0,
            'blocks' => [['id' => CopyPage::BLOCK_ID, 'type' => Theme::COPY_BLOCK, 'data' => $data]],
        ];
        if ($fields['title'] === '') {
            $fields['title'] = (string)$doc['title'];
        }
        Content::updatePage($actor, $slug, $fields);
    }

    /** @param array<string,mixed> $doc @param array<string,mixed> $old values to show instead of the saved ones (after a refused save) */
    private static function form(string $slug, array $doc, string $error, string $ok, array $old = []): ModuleResponse
    {
        $e = 'ag_e';
        $d = CopyPage::data($doc);
        $v = static fn(string $k, string $fallback = ''): string => (string)($old[$k] ?? $fallback);
        $url = '/' . ($slug === 'index' ? '' : $slug);
        $body = ($error !== '' ? '<div class="notice error">' . $e($error) . '</div>' : '') . ($ok !== '' ? '<div class="notice ok">' . $e($ok) . '</div>' : '');
        $body .= '<p><a href="/admin/pages">Все страницы</a> · <a href="' . $e($url) . '" target="_blank" rel="noopener">Открыть на сайте</a> · <a href="/admin/history?slug=' . rawurlencode($slug) . '">История</a></p>';
        $body .= '<div class="notice warn"><div>Это точная копия страницы другого сайта. Её код (HTML, стили и скрипты) выполняется у посетителей как есть. Правьте код только если понимаете, что делаете: чужой скрипт может получить данные посетителя. Файлы копии лежат в <span class="mono">' . $e(CopyPage::url((string)($d['copy_key'] ?? ''))) . '</span>.</div></div>';
        $body .= '<form method="post" action="' . self::BASE . '/copy?slug=' . rawurlencode($slug) . '" class="panel"><div class="panel-b">' . ag_admin_csrf_field() . '<input type="hidden" name="slug" value="' . $e($slug) . '">';
        $body .= '<label class="f"><span>Заголовок страницы</span><input class="in" type="text" name="title" maxlength="200" value="' . $e($v('title', (string)$doc['title'])) . '"></label>';
        $body .= '<label class="f"><span>Заголовок для поисковиков</span><input class="in" type="text" name="meta_title" maxlength="200" value="' . $e($v('meta_title', (string)($doc['meta_title'] ?? ''))) . '"></label>';
        $body .= '<label class="f"><span>Описание для поисковиков</span><textarea class="in" name="meta_description" rows="3" maxlength="500">' . $e($v('meta_description', (string)($doc['meta_description'] ?? ''))) . '</textarea></label>';
        $body .= '<label class="chk"><input type="checkbox" name="is_noindex" value="1"' . ((int)($old['is_noindex'] ?? $doc['is_noindex'] ?? 0) === 1 ? ' checked' : '') . '> Не показывать страницу в поисковиках (noindex)</label>';
        $evalOn = $old !== [] ? !empty($old['copy_eval']) : !empty($d['copy_eval']);
        $body .= '<label class="chk"><input type="checkbox" name="copy_eval" value="1"' . ($evalOn ? ' checked' : '') . '> Разрешить скриптам страницы eval() (нужно редким старым скриптам; снижает защиту этой страницы)</label>';
        $area = static fn(string $name, string $label, string $value, int $rows): string => '<label class="f"><span>' . $label . '</span><textarea class="in mono" name="' . $name . '" rows="' . $rows . '" spellcheck="false" style="width:100%;font-size:12px;white-space:pre;overflow:auto">' . ag_e($value) . '</textarea></label>';
        $body .= $area('copy_head', 'Код в &lt;head&gt; (стили, скрипты, подключения)', $v('copy_head', (string)($d['copy_head'] ?? '')), 12);
        $body .= $area('copy_body', 'Код страницы (&lt;body&gt;)', $v('copy_body', (string)($d['copy_body'] ?? '')), 24);
        if ((string)($d['copy_m_body'] ?? '') !== '') {
            $body .= '<p class="muted">У страницы есть отдельная версия для телефона (открывается на узких экранах).</p>';
            $body .= $area('copy_m_head', 'Телефон: код в &lt;head&gt;', $v('copy_m_head', (string)($d['copy_m_head'] ?? '')), 8);
            $body .= $area('copy_m_body', 'Телефон: код страницы (&lt;body&gt;)', $v('copy_m_body', (string)($d['copy_m_body'] ?? '')), 16);
        }
        $body .= '<p><button class="btn primary" type="submit">Сохранить</button></p></div></form>';
        return ModuleResponse::html('Копия страницы: ' . (string)$doc['title'], $body, $error !== '' ? 422 : 200);
    }
}
