<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

use DOMDocument;
use DOMElement;
use DOMImplementation;
use DOMNode;
use Pushery\TaxAuditExport\Exceptions\InvalidDefinition;

/**
 * The `index.xml` of an export, after the description standard for the handing over of data, an annex
 * to the GoBD: the description an import reads the files through.
 *
 * It names every table with its file, the format the file is written in ({@see CsvFormat}), a column for
 * each field of the header line with its type, the first as the key of a table that has one, and the
 * links between the tables. It declares the document type definition {@see Dtd::FILE}, which lies
 * beside it.
 */
final class Index
{
    /** The file name an import looks for. */
    public const string FILE = 'index.xml';

    /** The version of the description standard the document type definition defines. */
    private const string VERSION = '1.0';

    /** Stands in for the line end until the document is written, see describe(). */
    private const string PLACEHOLDER = 'LINE-END';

    /**
     * The description of these tables, for the business that hands them over.
     *
     * @param  list<Table>  $tables
     */
    public static function describe(DataSupplier $supplier, array $tables, string $media = 'Datenüberlassung'): string
    {
        $keys = self::guard($tables);

        $implementation = new DOMImplementation;
        $document = $implementation->createDocument(null, '', $implementation->createDocumentType('DataSet', '', Dtd::FILE));
        $document->encoding = 'UTF-8';
        $document->formatOutput = true;

        $children = [self::element($document, 'Name', $media)];

        foreach ($tables as $table) {
            $children[] = self::table($document, $table, $keys);
        }

        $document->append(self::element($document, 'DataSet', null, [
            self::element($document, 'Version', self::VERSION),
            self::element($document, 'DataSupplier', null, [
                self::element($document, 'Name', $supplier->name),
                self::element($document, 'Location', $supplier->location),
                self::element($document, 'Comment', $supplier->comment),
            ]),
            self::element($document, 'Media', null, $children),
        ]));

        // The line end goes in as a character reference, the way the reference of the tax authority writes
        // it: as a plain line feed it would be a text node of nothing but white space, which a reader may drop.
        $lineEnd = implode('', array_map(
            static fn (string $character): string => sprintf('&#x%X;', mb_ord($character)),
            mb_str_split(CsvFormat::LINE_END),
        ));

        return str_replace(
            '<RecordDelimiter>'.self::PLACEHOLDER.'</RecordDelimiter>',
            '<RecordDelimiter>'.$lineEnd.'</RecordDelimiter>',
            (string) $document->saveXML(),
        );
    }

    /**
     * Refuses tables the export cannot write side by side or the description cannot link: two with one
     * name or one file, one written to the `index.xml` or to the document type definition, which the
     * export writes beside the tables, and a reference to a table that is not exported, that has no key,
     * or whose key column the referencing table does not carry.
     *
     * A file name is compared without the case of its letters, because a file system may not tell
     * `a.csv` from `A.csv`, as macOS and Windows do not by default.
     *
     * @param  list<Table>  $tables
     * @return array<string, Column> the key of each table a reference names, under the name of the table
     */
    private static function guard(array $tables): array
    {
        if ($tables === []) {
            throw InvalidDefinition::because('Index', 'there is no table to describe');
        }

        $names = [];
        $files = [];

        foreach ($tables as $table) {
            $file = strtolower($table->file);

            if (isset($names[$table->name])) {
                throw InvalidDefinition::because('Index', sprintf('two tables are named "%s"', $table->name));
            }

            if (strcasecmp($table->file, self::FILE) === 0 || strcasecmp($table->file, Dtd::FILE) === 0) {
                throw InvalidDefinition::because(sprintf('Table "%s"', $table->name), sprintf('it is written to "%s", a name the export keeps for a file of its own', $table->file));
            }

            if (isset($files[$file])) {
                throw InvalidDefinition::because('Index', sprintf('two tables are written to "%s"', $table->file));
            }

            $names[$table->name] = $table;
            $files[$file] = true;
        }

        $keys = [];

        foreach ($tables as $table) {
            foreach ($table->references as $reference) {
                $referenced = $names[$reference] ?? null;

                if ($referenced === null) {
                    throw InvalidDefinition::because(sprintf('Table "%s"', $table->name), sprintf('it references "%s", which is not exported', $reference));
                }

                $key = $referenced->key();

                if ($key === null) {
                    throw InvalidDefinition::because(sprintf('Table "%s"', $table->name), sprintf('it references "%s", a table without a key, which no foreign key can name', $reference));
                }

                if (! $table->hasColumn($key->name)) {
                    throw InvalidDefinition::because(sprintf('Table "%s"', $table->name), sprintf('it references "%s" but carries no column "%s", the key of that table', $reference, $key->name));
                }

                $keys[$reference] = $key;
            }
        }

        return $keys;
    }

    /**
     * One table: its file, the format it is written in, a column for each field of its header and its links.
     *
     * @param  array<string, Column>  $keys  the key of each table a reference names, under the name of the table
     */
    private static function table(DOMDocument $document, Table $table, array $keys): DOMElement
    {
        $format = [
            self::element($document, 'ColumnDelimiter', CsvFormat::DELIMITER),
            self::element($document, 'RecordDelimiter', self::PLACEHOLDER),
            self::element($document, 'TextEncapsulator', CsvFormat::ENCLOSURE),
        ];

        foreach ($table->columns as $column) {
            $format[] = self::element($document, $column === $table->key() ? 'VariablePrimaryKey' : 'VariableColumn', null, [
                self::element($document, 'Name', $column->name),
                self::element($document, 'Description', $column->description),
                ...self::type($document, $column),
            ]);
        }

        foreach ($table->references as $reference) {
            $format[] = self::element($document, 'ForeignKey', null, [
                self::element($document, 'Name', $keys[$reference]->name),
                self::element($document, 'References', $reference),
            ]);
        }

        return self::element($document, 'Table', null, [
            self::element($document, 'URL', $table->file),
            self::element($document, 'Name', $table->name),
            self::element($document, 'Description', $table->description),
            self::element($document, 'UTF8'),
            self::element($document, 'DecimalSymbol', CsvFormat::DECIMAL_SYMBOL),
            self::element($document, 'DigitGroupingSymbol', CsvFormat::DIGIT_GROUPING_SYMBOL),
            self::element($document, 'Range', null, [self::element($document, 'From', (string) CsvFormat::FIRST_RECORD)]),
            self::element($document, 'VariableLength', null, $format),
        ]);
    }

    /**
     * A number with its decimal places, or a text with its greatest length where one is known.
     *
     * @return non-empty-list<DOMElement>
     */
    private static function type(DOMDocument $document, Column $column): array
    {
        if ($column->isNumeric()) {
            return $column->accuracy === null
                ? [self::element($document, 'Numeric')]
                : [self::element($document, 'Numeric', null, [self::element($document, 'Accuracy', (string) $column->accuracy)])];
        }

        return $column->maxLength === null
            ? [self::element($document, 'AlphaNumeric')]
            : [self::element($document, 'AlphaNumeric'), self::element($document, 'MaxLength', (string) $column->maxLength)];
    }

    /**
     * @param  list<DOMNode>  $children
     */
    private static function element(DOMDocument $document, string $name, ?string $text = null, array $children = []): DOMElement
    {
        $element = $document->createElement($name);

        if ($text !== null) {
            $element->append($text);
        }

        $element->append(...$children);

        return $element;
    }
}
