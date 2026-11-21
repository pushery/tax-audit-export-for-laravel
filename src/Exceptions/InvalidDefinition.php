<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport\Exceptions;

use InvalidArgumentException;

/**
 * Something the description standard cannot carry, named with its subject and the reason: a table, a
 * column or a data supplier, refused when it or the description is built; a row with a value its column
 * cannot hold or a name the table has no column for, refused when its record is written; and a name
 * Table::column() is asked for that the table has no column for. When a refused row stops an export, the
 * export removes the files it wrote, so an export that would describe its files wrongly never stays in
 * its directory.
 */
final class InvalidDefinition extends InvalidArgumentException
{
    public static function because(string $subject, string $reason): self
    {
        return new self(sprintf('%s: %s', $subject, $reason));
    }
}
