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
