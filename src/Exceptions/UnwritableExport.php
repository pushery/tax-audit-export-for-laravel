<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport\Exceptions;

use RuntimeException;

/**
 * An export that cannot be written into its directory: the directory holds an export already, or the
 * directory or a file of the export cannot be created or written. Named with the path and what stood
 * in the way.
 */
final class UnwritableExport extends RuntimeException
{
    public static function because(string $path, string $reason): self
    {
        return new self(sprintf('%s: %s', $path, $reason));
    }
}
