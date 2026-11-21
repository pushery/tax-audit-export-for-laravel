<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

use Pushery\TaxAuditExport\Exceptions\InvalidDefinition;

/**
 * The business that hands the data over, as the `DataSupplier` element of the `index.xml` names it:
 * its name, where it is, and a comment on what the export holds.
 */
final readonly class DataSupplier
{
    public function __construct(
        public string $name,
        public string $location = '',
        public string $comment = '',
    ) {
        if (trim($name) === '') {
            throw InvalidDefinition::because('DataSupplier', 'the name is empty');
        }
    }
}
