<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

/** Thrown inside a step when the time is up in the middle of a page: the page stays pending for the next step. */
final class Pause extends \RuntimeException
{
}
