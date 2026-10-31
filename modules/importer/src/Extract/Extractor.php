<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Extract;

/**
 * A source of pages in the neutral format. Work goes in steps so that a shared hosting request (30 seconds) or a
 * background tick can do a part and the next one goes on: everything to remember lives in $state, which is plain JSON.
 *
 * $state['site'] holds {source_url, platform, title, menus{header, footer}} once known, $state['warnings'] a list of
 * strings for the report, $state['detect'] the platform detection.
 */
interface Extractor
{
    /**
     * Does work until $deadline (microtime) and calls $emit(page) for every page found.
     *
     * @param array<string,mixed> $state
     * @param callable(array<string,mixed>):void $emit
     * @return bool true when there is nothing left to do
     */
    public function step(array &$state, float $deadline, callable $emit): bool;
}
