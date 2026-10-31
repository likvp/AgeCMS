<?php
declare(strict_types=1);

/** HTML-escape for templates. */
function ag_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** UTC timestamp in the format stored in the database. */
function ag_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * A setting of the site for a template: ag_setting('org_phone'), ag_setting('theme.footer.note'). Empty string when not set.
 * The layout also gets them all in $settings and the theme's own ones typed in $themeSettings.
 */
function ag_setting(string $key, string $default = ''): string
{
    return \AgeCMS\Renderer::$settings[$key] ?? $default;
}

/** Attributes that make a template field editable in place; empty outside edit mode. */
function ag_edit(string $field, bool $multiline = false): string
{
    $ctx = \AgeCMS\Renderer::$context;
    if (!$ctx['editing']) {
        return '';
    }
    return ' data-ag-slug="' . ag_e($ctx['slug']) . '" data-ag-block="' . ag_e($ctx['block']) . '" data-ag-field="' . ag_e($field) . '"' . ($multiline ? ' data-ag-multi' : '');
}

/**
 * Attributes for a richtext field edited in place: the page editor gives it a toolbar (bold, italic, link, headings, lists,
 * quote) and saves HTML. Print the value with ag_rich().
 */
function ag_edit_rich(string $field): string
{
    $attrs = ag_edit($field, true);
    return $attrs === '' ? '' : $attrs . ' data-ag-rich';
}

/**
 * HTML of a richtext field for a template, cleaned by the same white list as on saving (the page is cached, and a value
 * written before a change of the rules is cleaned again here). Print it as is, without ag_e().
 */
function ag_rich(mixed $html): string
{
    return is_string($html) ? \AgeCMS\RichText::sanitize($html) : '';
}

/** Adds a cache tag to the page being rendered: Cache::invalidate([$tag]) then drops its cached copy. */
function ag_cache_tag(string $tag): void
{
    \AgeCMS\Renderer::addTag($tag);
}

/**
 * Published child pages of $parent ('' is the page being rendered) for a listing in a block: slug, title, meta_description,
 * og_image, published_at. Sorted by sort_order, then the newest first. The cached page is dropped when a child changes.
 *
 * @return list<array{slug:string,title:string,meta_description:string,og_image:string,published_at:string,sort_order:int}>
 */
function ag_child_pages(string $parent = '', int $limit = 100): array
{
    $parent = trim($parent, '/');
    if ($parent === '') {
        $parent = \AgeCMS\Renderer::$context['slug'];
    }
    if ($parent === '' || !\AgeCMS\Pages::slugValid($parent)) {
        return [];
    }
    ag_cache_tag('children:' . $parent);
    return \AgeCMS\Pages::children($parent, $limit);
}

/** CSRF field of the admin for a form on an admin page of a module (see ModuleAdmin). Put it inside the <form>. */
function ag_admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . ag_e(\AgeCMS\Auth::csrf()) . '">';
}

/**
 * Hidden form fields for a form that a module posts to its own route (see ModuleRoutes): the signed fill-time token the core checks
 * on every POST to a module address. Put it inside the <form>; the page of the module is never cached.
 */
function ag_module_form_fields(): string
{
    return '<input type="hidden" name="token" value="' . ag_e(\AgeCMS\AntiSpam::issueToken()) . '">';
}
