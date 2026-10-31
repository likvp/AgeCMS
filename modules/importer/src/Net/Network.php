<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Net;

/** Where fetchers come from. Tests put an ArrayFetcher here so that nothing goes to the network. */
final class Network
{
    private static ?Fetcher $override = null;

    public static function fetcher(int $delayMs): Fetcher
    {
        return self::$override ?? new HttpFetcher($delayMs);
    }

    public static function override(?Fetcher $fetcher): void
    {
        self::$override = $fetcher;
    }
}
