<?php
declare(strict_types=1);

/**
 * Every table of the admin can be sorted by a click on its header: admin.js does it for any table with a header, so a table
 * of the core or of a bundled module must have one. A table without a header is allowed only as a card of parameters, where
 * every row starts with its own <th>.
 */
final class AdminTableSortTest extends TestCase
{
    /** @return list<string> */
    private function adminSources(): array
    {
        $root = dirname(__DIR__);
        return array_values(array_merge(
            (array)glob($root . '/core/views/admin/*.php'),
            (array)glob($root . '/admin/*.php'),
            (array)glob($root . '/modules/*/src/Admin/*.php'),
            (array)glob($root . '/modules/*/Module.php'),
        ));
    }

    public function testEveryListTableHasAHeader(): void
    {
        $seen = 0;
        foreach ($this->adminSources() as $file) {
            $parts = preg_split('/<table\b/', (string)file_get_contents($file)) ?: [];
            foreach (array_slice($parts, 1) as $n => $after) {
                $seen++;
                $isCard = preg_match('/\A[^>]*>\s*(?:<tbody>)?\s*(?:<\?php[^>]*\?>\s*)?<tr><th\b/', $after) === 1
                    && preg_match('/\A[^>]*>\s*(?:<tbody>)?\s*<tr><th\b(?:(?!<\/tr>).)*?<td\b/s', $after) === 1;
                $hasHead = preg_match('/\A[^>]*>\s*<thead\b/', $after) === 1;
                $this->assertTrue($hasHead || $isCard, basename($file) . ', table ' . ($n + 1) . ': a header <thead> is required for sorting');
            }
        }
        $this->assertTrue($seen >= 12, 'the tables of the admin were found');
    }

    public function testTheScriptSortsTablesAlsoAfterALiveUpdate(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__) . '/assets/admin/admin.js');
        $this->assertContains('function sortTables(root)', $js);
        $this->assertTrue(preg_match('/function initLive\(root\) \{\s*sortTables\(root\);/', $js) === 1, 'new fragments are sorted too');
        $this->assertContains("setAttribute('aria-sort'", $js);
        $css = (string)file_get_contents(dirname(__DIR__) . '/assets/admin/admin.css');
        foreach (['.th-sort', 'th[aria-sort]', '.tbl-sortbar[hidden]'] as $rule) {
            $this->assertContains($rule, $css);
        }
    }

    public function testTheRuleIsInTheModuleGuide(): void
    {
        $docs = (string)file_get_contents(dirname(__DIR__) . '/docs/MODULES.md');
        $this->assertContains('Таблицы: сортировка по заголовку обязательна', $docs);
    }
}
