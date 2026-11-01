<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Admin;

use AgeCMS\Actor;
use AgeCMS\AgeCMSException;
use AgeCMS\Content;
use AgeCMS\CopyEdit;
use AgeCMS\CopyPage;
use AgeCMS\Logger;
use AgeCMS\ModuleRequest;
use AgeCMS\ModuleResponse;
use AgeCMS\Pages;
use AgeCMS\Theme;
use AgeCMS\ValidationException;

/**
 * Saves the text edits made on a copied page in place (assets/copy-edit.js): POST /admin/m/importer/copy-edit with the fields
 * "payload" (JSON: slug, variant, base, edits) and the admin CSRF token. The right to write pages (content.write) is enough, because
 * nothing but texts, link addresses and picture files can change and CopyEdit checks every one of them; the code of the page, which
 * only an administrator may change, is not touched. One save is one revision of the page, so the history and its rollback work.
 */
final class TextEdit
{
    public static function handle(ModuleRequest $request, Actor $actor): ModuleResponse
    {
        if ($request->method !== 'POST') {
            return ModuleResponse::json(['ok' => false, 'error' => 'Только POST.'], 405);
        }
        $raw = (string)($request->body['payload'] ?? '');
        if (strlen($raw) > 2097152) {
            return ModuleResponse::json(['ok' => false, 'error' => 'Правка слишком большая.'], 413);
        }
        $in = json_decode($raw, true);
        if (!is_array($in) || !is_string($in['slug'] ?? null) || !is_array($in['edits'] ?? null)) {
            return ModuleResponse::json(['ok' => false, 'error' => 'Ожидается JSON с полями slug и edits.'], 422);
        }
        $slug = $in['slug'];
        $doc = Pages::slugValid($slug) ? Pages::doc($slug) : null;
        if ($doc === null || !CopyPage::is($doc)) {
            return ModuleResponse::json(['ok' => false, 'error' => 'Страницы нет или она не является точной копией.'], 404);
        }
        $data = CopyPage::data($doc);
        $mobile = ($in['variant'] ?? '') === 'm';
        $key = $mobile ? 'copy_m_body' : 'copy_body';
        $body = (string)($data[$key] ?? '');
        if ($body === '' || count($in['edits']) > 2000) {
            return ModuleResponse::json(['ok' => false, 'error' => 'Нечего править или правок слишком много.'], 422);
        }
        if (!is_string($in['base'] ?? null) || !hash_equals(CopyEdit::base($body), $in['base'])) {
            return ModuleResponse::json(['ok' => false, 'error' => 'Страницу изменили в другом окне после того, как вы её открыли. Обновите страницу и повторите правку.'], 409);
        }
        $r = CopyEdit::apply($body, array_values(array_filter($in['edits'], 'is_array')));
        try {
            if ($r['applied'] > 0) {
                $data[$key] = $r['html'];
                if (!$mobile) {
                    $data['copy_text'] = CopyEdit::plainText($r['html']);
                }
                Content::updatePage($actor, $slug, ['blocks' => [['id' => CopyPage::BLOCK_ID, 'type' => Theme::COPY_BLOCK, 'data' => $data]]]);
                Logger::log('info', 'copy.text_edited', ['slug' => $slug, 'edits' => $r['applied'], 'actor' => $actor->name]);
            }
        } catch (ValidationException $e) {
            return ModuleResponse::json(['ok' => false, 'error' => implode(' ', $e->errors)], 422);
        } catch (AgeCMSException $e) {
            return ModuleResponse::json(['ok' => false, 'error' => $e->getMessage()], 403);
        }
        return ModuleResponse::json(['ok' => true, 'result' => ['applied' => $r['applied'], 'skipped' => $r['skipped'], 'base' => CopyEdit::base($r['html'])]]);
    }
}
