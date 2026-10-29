<?php
declare(strict_types=1);

use AgeCMS\Router;

/** The editing strip and the pencil button the core adds to a page for a signed-in user. */
final class EditBarTest extends TestCase
{
    private function decorate(string $html, bool $editing): string
    {
        $method = new ReflectionMethod(Router::class, 'withEditButton');
        return (string)$method->invoke(null, $html, $editing, '/about');
    }

    public function testTheCoreDrawsTheStripRightAfterBodyOnlyInTheEditor(): void
    {
        $page = "<!DOCTYPE html><html><head><title>t</title></head>\n<body class=\"x\">\n<header>h</header></body></html>";
        $editing = $this->decorate($page, true);
        $this->assertSame(1, substr_count($editing, 'data-ag-bar'));
        $this->assertTrue(preg_match('~<body class="x">\s*<div class="ag-bar" data-ag-bar~', $editing) === 1);
        $this->assertTrue(strpos($editing, 'edit-button.css') < strpos($editing, '</head>'), 'styles in <head>');
        $this->assertContains('href="/_/edit?to=%2Fabout"', $editing);

        $plain = $this->decorate($page, false);
        $this->assertNotContains('data-ag-bar', $plain, 'no strip outside the editor');
        $this->assertContains('class="ag-fab"', $plain, 'the pencil button is there');
    }

    public function testAThemeWithItsOwnStripKeepsItAndGetsNoSecondOne(): void
    {
        $page = '<html><head></head><body><div class="ag-bar dark"><span id="ag-status"></span></div><main></main></body></html>';
        $out = $this->decorate($page, true);
        $this->assertSame(1, substr_count($out, 'ag-bar'), 'only the theme\'s strip');
        $this->assertNotContains('data-ag-bar', $out);

        $similar = '<html><head></head><body><div class="ag-bar-like"></div></body></html>';
        $this->assertContains('data-ag-bar', $this->decorate($similar, true), 'another class that starts the same is not a strip');
    }
}
