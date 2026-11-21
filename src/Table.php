<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

use Pushery\TaxAuditExport\Exceptions\InvalidDefinition;

/**
 * A table of an export: the file it is written to, its name and description in the `index.xml`, its
 * columns in the order of the header line, the first of them its key, and the tables whose records it
 * names by their key.
 *
 * A table that names a record of another carries that table's key column under the same name, which is
 * how the description standard links two tables (`ForeignKey`). The export checks each reference
 * against the table it names before anything is written.
 *
 * A table built with `keyed: false` has no key, and the `index.xml` names each of its columns as a plain
 * column (`VariableColumn`). That is how the DSFinV-K describes its tables, whose first column names the
 * cash register and repeats in every record of one. No table can reference a table without a key, since
 * a `ForeignKey` names the key of the table it references.
 */
final readonly class Table
{
    /**
     * The columns in the order of the header line, the first of them the key of a table that has one.
     *
     * @var non-empty-list<Column>
     */
    public array $columns;

    /**
     * The columns under their names, built once, so that a name is looked up rather than searched for
     * in every record a table writes.
     *
     * @var array<string, Column>
     */
    private array $named;

    /**
     * @param  list<Column>  $columns  refused when empty, since a description names at least one column
     * @param  list<string>  $references  the names of the tables whose keys this table names
     * @param  bool  $keyed  whether the first column is the key; false describes the table without one
     */
    public function __construct(
        public string $file,
        public string $name,
        public string $description,
        array $columns,
        public array $references = [],
        public bool $keyed = true,
    ) {
        $subject = sprintf('Table "%s"', $name);

        if (trim($name) === '') {
            throw InvalidDefinition::because('Table', 'the name is empty');
        }

        // \z rather than $, which also matches before a final line feed.
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\z/', $file) !== 1) {
            throw InvalidDefinition::because($subject, sprintf('the file "%s" is no plain file name', $file));
        }

        if (trim($description) === '') {
            throw InvalidDefinition::because($subject, 'the description is empty');
        }

        if ($columns === []) {
            throw InvalidDefinition::because($subject, 'it has no column');
        }

        $names = array_map(static fn (Column $column): string => $column->name, $columns);
        $twice = array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1));

        if ($twice !== []) {
            throw InvalidDefinition::because($subject, sprintf('the column "%s" stands twice', $twice[0]));
        }

        $this->columns = $columns;
        $this->named = array_combine($names, $columns);
    }

    /** The column that is the key of the table, or null for a table built without a key. */
    public function key(): ?Column
    {
        return $this->keyed ? $this->columns[0] : null;
    }

    /**
     * The names of the columns, in the order of the header line.
     *
     * @return non-empty-list<string>
     */
    public function header(): array
    {
        return array_map(static fn (Column $column): string => $column->name, $this->columns);
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->named[$name]);
    }

    public function column(string $name): Column
    {
        return $this->named[$name] ?? throw InvalidDefinition::because(sprintf('Table "%s"', $this->name), sprintf('it has no column "%s"', $name));
    }

    /**
     * The first name in the row that names no column of the table, in the order of the row, or null when
     * every name of the row is a column.
     *
     * @internal
     *
     * @param  array<array-key, mixed>  $row
     */
    public function unknownName(array $row): ?string
    {
        $name = array_key_first(array_diff_key($row, $this->named));

        return $name === null ? null : (string) $name;
    }
}
