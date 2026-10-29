<?php
declare(strict_types=1);

namespace AgeCMS;

/**
 * Tabs of a long admin page: a row of links, the current tab is in the address (?tab=), so it works without JavaScript,
 * survives a reload and can be linked to. Used by the settings page of the core and by pages of modules:
 *
 *   $tabs = ['key' => 'Ключ', 'login' => 'Вход', 'tariffs' => 'Тарифы'];
 *   $tab = AdminTabs::current($tabs, $request->query['tab'] ?? null);
 *   $html = AdminTabs::nav($tabs, $tab, $request->path) . ($tab === 'key' ? '...' : '...');
 *
 * Rule of thumb: a page longer than three screens is split into tabs.
 */
final class AdminTabs
{
    /**
     * The tab asked for in the address, or the first one when it is missing or unknown.
     *
     * @param array<string,string> $tabs key => title, in order
     */
    public static function current(array $tabs, mixed $asked): string
    {
        if ($tabs === []) {
            throw new AgeCMSException('AdminTabs: no tabs');
        }
        return is_string($asked) && isset($tabs[$asked]) ? $asked : (string)array_key_first($tabs);
    }

    /**
     * The row of tabs. $url is the address of the page; the tab is added as ?tab= (or &tab= when it already has a query).
     *
     * @param array<string,string> $tabs key => title
     */
    public static function nav(array $tabs, string $current, string $url, string $label = 'Разделы страницы'): string
    {
        $glue = str_contains($url, '?') ? '&' : '?';
        $html = '<nav class="tabs" aria-label="' . ag_e($label) . '">';
        foreach ($tabs as $key => $title) {
            $html .= '<a href="' . ag_e($url . $glue . 'tab=' . rawurlencode((string)$key)) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . ag_e($title) . '</a>';
        }
        return $html . '</nav>';
    }

    /** Hidden field for a form inside a tab: after saving, the page opens on the same tab. */
    public static function field(string $current): string
    {
        return '<input type="hidden" name="tab" value="' . ag_e($current) . '">';
    }
}
