<?php
declare(strict_types=1);

namespace AgeCMS\Modules\Importer;

use AgeCMS\AgeCMSException;

/** A failure the owner should read as it is (Russian text): the site did not open, the file is not an export, and so on. */
final class ImportException extends AgeCMSException
{
}
