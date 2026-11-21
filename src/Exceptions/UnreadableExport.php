<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An export whose description or files cannot be read the way the description says, named with the file
 * and what was found there.
 */
final class UnreadableExport extends RuntimeException
{
    public static function because(string $file, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('%s: %s', $file, $reason), 0, $previous);
    }
}
