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

/** Attributes that make a template field editable in place; empty outside edit mode. */
function ag_edit(string $field, bool $multiline = false): string
{
    $ctx = \AgeCMS\Renderer::$context;
    if (!$ctx['editing']) {
        return '';
    }
    return ' data-ag-slug="' . ag_e($ctx['slug']) . '" data-ag-block="' . ag_e($ctx['block']) . '" data-ag-field="' . ag_e($field) . '"' . ($multiline ? ' data-ag-multi' : '');
}
