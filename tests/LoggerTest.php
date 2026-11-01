<?php
declare(strict_types=1);

use AgeCMS\Logger;

final class LoggerTest extends TestCase
{
    /** @return list<array<string,mixed>> lines of the log with this event */
    private function lines(string $event): array
    {
        $file = AGECMS_DATA . '/logs/mcp_agent.json';
        $out = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && ($row['event'] ?? '') === $event) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function testThrottledEventIsWrittenOncePerHourWithTheCountOfHeldBackOnes(): void
    {
        $this->freshSite();
        $t = 1_800_000_000;
        Logger::throttled('m:a', 'warning', 'test.repeat', ['module' => 'm', 'reason' => 'x'], 3600, $t);
        for ($i = 1; $i <= 5; $i++) {
            Logger::throttled('m:a', 'warning', 'test.repeat', ['module' => 'm', 'reason' => 'x'], 3600, $t + $i * 60);
        }
        $this->assertSame(1, count($this->lines('test.repeat')), 'six identical events inside the hour are one line');

        Logger::throttled('m:b', 'warning', 'test.repeat', ['module' => 'm', 'reason' => 'y'], 3600, $t + 400);
        $this->assertSame(2, count($this->lines('test.repeat')), 'another key is written at once');

        Logger::throttled('m:a', 'warning', 'test.repeat', ['module' => 'm', 'reason' => 'x'], 3600, $t + 3600);
        $lines = $this->lines('test.repeat');
        $this->assertSame(3, count($lines), 'after an hour the event is written again');
        $this->assertSame(5, $lines[2]['skipped'], 'with the number of events held back meanwhile');
        $this->assertSame(false, isset($lines[0]['skipped']), 'the first line has no counter');

        Logger::throttled('m:a', 'warning', 'test.repeat', ['module' => 'm', 'reason' => 'x'], 3600, $t + 3700);
        Logger::throttled('m:a', 'warning', 'test.repeat', ['module' => 'm', 'reason' => 'x'], 3600, $t + 7200);
        $lines = $this->lines('test.repeat');
        $this->assertSame(4, count($lines));
        $this->assertSame(1, $lines[3]['skipped']);
    }

    public function testBlockedModuleIsLoggedOncePerReason(): void
    {
        $this->freshSite();
        for ($i = 0; $i < 20; $i++) {
            Logger::moduleBlocked('importer', 'Requires core 1.5.0 or newer');
        }
        $this->assertSame(1, count($this->lines('module.blocked')), 'twenty requests, one line');
        Logger::moduleBlocked('importer', 'Another reason');
        Logger::moduleBlocked('other', 'Requires core 1.5.0 or newer');
        $this->assertSame(3, count($this->lines('module.blocked')), 'another reason or another module is its own event');
    }
}
