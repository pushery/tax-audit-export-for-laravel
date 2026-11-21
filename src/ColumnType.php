<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

/**
 * The two kinds of value a column of the description standard holds, under the element names the
 * document type definition gives them.
 */
enum ColumnType: string
{
    case Numeric = 'Numeric';
    case AlphaNumeric = 'AlphaNumeric';
}
