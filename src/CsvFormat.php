<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

/**
 * The format every file of an export is written in, and the one its `index.xml` states: UTF-8, a comma
 * between the fields, a double quote around a text, a line feed after every record, a point before the
 * decimals, and a header line an import skips.
 *
 * The standard wants the decimal and the grouping symbol both or neither, and neither would mean its
 * defaults, a comma before the decimals. So both are stated, and a number is written without grouping.
 */
final class CsvFormat
{
    public const string DELIMITER = ',';

    public const string ENCLOSURE = '"';

    public const string LINE_END = "\n";

    public const string DECIMAL_SYMBOL = '.';

    public const string DIGIT_GROUPING_SYMBOL = ',';

    /** The first record that holds data: the line after the header. */
    public const int FIRST_RECORD = 2;
}
