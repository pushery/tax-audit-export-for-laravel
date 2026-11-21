<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

use Pushery\TaxAuditExport\Exceptions\InvalidDefinition;

/**
 * A column of a table: its name in the header of the file, what it holds, and its type as the
 * `index.xml` describes it. A number may name its decimal places (`Accuracy`), a text its greatest
 * length (`MaxLength`).
 *
 * The name stands in the header line of the CSV file without quotes, so it may hold neither the column
 * delimiter, nor the text encapsulator, nor a line break. Nor may it be a whole number such as `2024`:
 * a row names its values by column, and PHP keeps such an array key as an integer, not as the name.
 */
final readonly class Column
{
    private function __construct(
        public string $name,
        public string $description,
        public ColumnType $type,
        public ?int $accuracy,
        public ?int $maxLength,
    ) {
        $subject = sprintf('Column "%s"', $name);

        if (trim($name) === '') {
            throw InvalidDefinition::because('Column', 'the name is empty');
        }

        if (preg_match('/[\r\n]/', $name) === 1 || str_contains($name, CsvFormat::DELIMITER) || str_contains($name, CsvFormat::ENCLOSURE)) {
            throw InvalidDefinition::because($subject, 'the name holds a delimiter, a quote or a line break, which the header line cannot carry');
        }

        // PHP turns an array key into an integer exactly where the text is the integer written back as text:
        // '2024' and '-1' become numbers, while '02024', '-0' and '+1' stay strings.
        if ((string) (int) $name === $name) {
            throw InvalidDefinition::because($subject, 'the name is a whole number, which PHP keeps as an integer when it keys a row');
        }

        if (trim($description) === '') {
            throw InvalidDefinition::because($subject, 'the description is empty');
        }

        if ($accuracy !== null && $accuracy < 0) {
            throw InvalidDefinition::because($subject, sprintf('the accuracy is %d, below 0', $accuracy));
        }

        if ($maxLength !== null && $maxLength < 1) {
            throw InvalidDefinition::because($subject, sprintf('the greatest length is %d, below 1', $maxLength));
        }
    }

    /** A number, written with a point before its decimals and without grouping; where no accuracy is given, a whole number. */
    public static function numeric(string $name, string $description, ?int $accuracy = null): self
    {
        return new self($name, $description, ColumnType::Numeric, $accuracy, null);
    }

    /** A text, written between double quotes; where a greatest length is given, no longer than that many characters. */
    public static function text(string $name, string $description, ?int $maxLength = null): self
    {
        return new self($name, $description, ColumnType::AlphaNumeric, null, $maxLength);
    }

    public function isNumeric(): bool
    {
        return $this->type === ColumnType::Numeric;
    }
}
