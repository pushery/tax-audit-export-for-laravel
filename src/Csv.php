<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

use Pushery\TaxAuditExport\Exceptions\InvalidDefinition;

/**
 * A table as a CSV file in the format of {@see CsvFormat}: a header line, then a record for every row.
 *
 * A number stands without quotes, with a point before its decimals and without grouping. A text stands
 * between double quotes, a quote in it doubled. A line break in a text becomes a space, because an import
 * that follows the `index.xml` reads a line feed as the end of a record wherever it stands. Apart from that
 * a text stands as given, its first character included, so the value in the file is the value of the
 * original. A text may therefore begin with `=`, `+`, `-`, `@` or a tab, which a spreadsheet takes for the
 * start of a formula: the files are read through an import that follows the `index.xml` and takes such a
 * column as text, never opened directly in a spreadsheet.
 *
 * A value is held to what the `index.xml` declares for its column. A number has no more decimals than the
 * accuracy of its column, and a column that names none holds whole numbers, because the description
 * standard takes an accuracy of 0 there and leaves open what an import makes of more decimals. A text is
 * no longer than the greatest length of its column, counted in characters, because an import may size the
 * column by that length instead of reading the file.
 */
final class Csv
{
    public static function header(Table $table): string
    {
        return implode(CsvFormat::DELIMITER, $table->header()).CsvFormat::LINE_END;
    }

    /**
     * One record of the table, from a row that holds a value for a column under its name. A column the
     * row does not name is written empty, a name the table has no column for is refused.
     *
     * @param  array<string, int|float|string|null>  $row
     */
    public static function record(Table $table, array $row): string
    {
        $unknown = $table->unknownName($row);

        if ($unknown !== null) {
            throw InvalidDefinition::because(sprintf('Table "%s"', $table->name), sprintf('a row names "%s", which is no column of it', $unknown));
        }

        $fields = [];

        foreach ($table->columns as $column) {
            $value = $row[$column->name] ?? null;
            $fields[] = $column->isNumeric() ? self::number($column, $value) : self::text($column, $value);
        }

        return implode(CsvFormat::DELIMITER, $fields).CsvFormat::LINE_END;
    }

    /**
     * The whole file of a table with these rows.
     *
     * @param  iterable<array<string, int|float|string|null>>  $rows
     */
    public static function write(Table $table, iterable $rows): string
    {
        $file = self::header($table);

        foreach ($rows as $row) {
            $file .= self::record($table, $row);
        }

        return $file;
    }

    private static function number(Column $column, int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if ($column->accuracy === null) {
                throw InvalidDefinition::because(sprintf('Column "%s"', $column->name), 'a float needs the accuracy of its column, or a string that holds the number as it is to be written');
            }

            return number_format($value, $column->accuracy, CsvFormat::DECIMAL_SYMBOL, '');
        }

        // \z rather than $, which also matches before a final line feed, and a line feed ends the record.
        if (preg_match('/^-?\d+(\.\d+)?\z/', $value) !== 1) {
            throw InvalidDefinition::because(sprintf('Column "%s"', $column->name), sprintf('"%s" is no number written with a point and without grouping', $value));
        }

        $point = strpos($value, CsvFormat::DECIMAL_SYMBOL);
        $decimals = $point === false ? 0 : strlen($value) - $point - 1;
        $accuracy = $column->accuracy ?? 0;

        if ($decimals > $accuracy) {
            throw InvalidDefinition::because(sprintf('Column "%s"', $column->name), sprintf('"%s" has more decimals than the accuracy of %d', $value, $accuracy));
        }

        return $value;
    }

    private static function text(Column $column, int|float|string|null $value): string
    {
        $text = (string) preg_replace('/\r\n|\r|\n/', ' ', (string) $value);
        $length = mb_strlen($text);

        // The value itself stays out of the message: a text may carry personal data, and the message
        // may end up in a log.
        if ($column->maxLength !== null && $length > $column->maxLength) {
            throw InvalidDefinition::because(sprintf('Column "%s"', $column->name), sprintf('the text has %d characters, more than the greatest length of %d', $length, $column->maxLength));
        }

        return CsvFormat::ENCLOSURE.str_replace(CsvFormat::ENCLOSURE, CsvFormat::ENCLOSURE.CsvFormat::ENCLOSURE, $text).CsvFormat::ENCLOSURE;
    }
}
