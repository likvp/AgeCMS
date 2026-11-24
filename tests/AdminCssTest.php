<?php
declare(strict_types=1);

/** The classes that docs/MODULES.md offers to modules for their admin pages exist in assets/admin/admin.css, so a page built by the document is not left without a layout. */
final class AdminCssTest extends TestCase
{
    public function testDocumentedClassesExistInTheStylesheet(): void
    {
        $root = dirname(__DIR__);
        $docs = (string)file_get_contents($root . '/docs/MODULES.md');
        $css = (string)preg_replace('~/\\*.*?\\*/~s', '', (string)file_get_contents($root . '/assets/admin/admin.css'));
        $from = strpos($docs, '### Вид страниц модуля в админке');
        $to = strpos($docs, '### Живые части страницы');
        $this->assertTrue($from !== false && $to !== false && $from < $to, 'the section is in its place');
        $section = substr($docs, (int)$from, (int)$to - (int)$from);
        preg_match_all('/class="([^"]+)"/', $section, $m);
        $used = array_unique(explode(' ', implode(' ', $m[1])));
        $this->assertTrue(count($used) > 20, 'the examples were found');
        foreach ($used as $class) {
            $this->assertTrue(preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $css) === 1, ".{$class} is in admin.css");
        }
        foreach (['.row', '.stack', '.cols', '.facts', '.photos', 'input[type=file].in', '.tbl.params', '.card-pic.sm', '.card-num'] as $rule) {
            $this->assertContains($rule, $css);
        }
        $this->assertSame(substr_count($css, '{'), substr_count($css, '}'), 'braces match');
    }
}
