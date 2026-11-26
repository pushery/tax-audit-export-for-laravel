<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

/**
 * The business that hands the data over, as the `DataSupplier` element of the `index.xml` names it:
 * its name, where it is, and a comment on what the export holds.
 *
 * Each of the three may be empty, as the document type definition allows. An export then holds an empty
 * `Name`, so it does not fail for a business that has not entered its name yet.
 */
final readonly class DataSupplier
{
    public function __construct(
        public string $name,
        public string $location = '',
        public string $comment = '',
    ) {}
}
