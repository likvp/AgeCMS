<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer\Net;

/** Fetches documents for the importer. The real one goes to the network through the core's SSRF guard; tests use ArrayFetcher. */
interface Fetcher
{
    /** null when the address is refused (private network, bad scheme) or the request failed. Redirects are followed. */
    public function get(string $url, int $maxBytes = 8388608): ?Response;

    /** How many requests went out. */
    public function count(): int;
}
