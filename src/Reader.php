<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Generator;
use Pushery\TaxAuditExport\Exceptions\InvalidDefinition;
use Pushery\TaxAuditExport\Exceptions\UnreadableExport;

/**
 * Reads an export the way an import does: through its `index.xml` alone. The tables, their columns and
 * types come from the description, never from the classes that wrote it, and so do the column delimiter,
 * the text encapsulator, the record delimiter, the first record and the encoding of each file. So a test
 * that reads an export back proves what the description says of them.
 *
 * Records are split at a line feed, a carriage return before it dropped, so a description that states
 * another record delimiter is refused. A value comes back as the text the file holds, so the decimal and
 * the grouping symbol are not read, and neither is `SkipNumBytes`. A table of fixed length names none of
 * the columns the reader takes and is refused.
 */
final readonly class Reader
{
    /** The encoding elements of the description standard, the default first: a table that names none is ANSI. */
    private const array ENCODINGS = ['ANSI', 'Macintosh', 'OEM', 'UTF16', 'UTF7', 'UTF8'];

    /** The encodings the reader decodes: `UTF8` as it stands, `ANSI` from Windows-1252. */
    private const array DECODED = ['UTF8', 'ANSI'];

    /** The record delimiters the reader splits at: a line feed, with a carriage return before it or without. */
    private const array RECORD_DELIMITERS = ["\n", "\r\n"];

    /**
     * @param  array<string, array{table: Table, delimiter: string, enclosure: string, from: int, encoding: string}>  $tables
     * @param  positive-int  $maxRecordBytes
     */
    private function __construct(
        private string $directory,
        public DataSupplier $supplier,
        private array $tables,
        private int $maxRecordBytes,
    ) {}

    /**
     * @param  list<string>  $encodings  the encoding elements a table may declare, out of the two the
     *                                   reader decodes: `UTF8` is read as it stands, `ANSI` is converted
     *                                   from Windows-1252, and any other is refused even where it is listed
     * @param  int  $maxIndexBytes  the largest `index.xml` the reader parses; a larger one is refused
     *                              before it is read whole
     */
    public static function open(string $directory, int $maxRecordBytes = ExportSettings::MAX_RECORD_BYTES, array $encodings = ExportSettings::ENCODINGS, int $maxIndexBytes = ExportSettings::MAX_INDEX_BYTES): self
    {
        $directory = rtrim($directory, '/');
        $path = $directory.'/'.Index::FILE;
        // One byte past the limit is read to tell a larger file from one that ends at it, so the limit
        // stays below the largest integer.
        $maxIndexBytes = min(max(1, $maxIndexBytes), PHP_INT_MAX - 1);
        $xml = is_file($path) ? @file_get_contents($path, false, null, 0, $maxIndexBytes + 1) : false;

        if ($xml === false) {
            throw UnreadableExport::because($path, 'there is no index.xml to read');
        }

        if (strlen($xml) > $maxIndexBytes) {
            throw UnreadableExport::because($path, sprintf('it is longer than %d bytes', $maxIndexBytes));
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // An empty string is no document either, and loadXML() answers it with a ValueError, not with false.
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw UnreadableExport::because($path, 'it is no XML document');
        }

        $xpath = new DOMXPath($document);
        // The name may be empty, as the document type definition allows, but the element has to be there.
        $supplierName = self::rawOptional($xpath, '/DataSet/DataSupplier/Name');

        if ($supplierName === null) {
            throw UnreadableExport::because($path, 'the element /DataSet/DataSupplier/Name is missing');
        }

        $supplier = new DataSupplier(
            trim($supplierName),
            self::optional($xpath, '/DataSet/DataSupplier/Location'),
            self::optional($xpath, '/DataSet/DataSupplier/Comment'),
        );

        $tables = [];

        foreach (self::elements($xpath, '/DataSet/Media/Table') as $element) {
            try {
                $entry = self::table($xpath, $element, $path);
            } catch (InvalidDefinition $refused) {
                // A table and its columns are built with the rules an export is written by, so a description
                // that breaks one cannot be read the way it says.
                throw UnreadableExport::because($path, $refused->getMessage(), $refused);
            }

            // The list narrows the two encodings the reader decodes and adds none, since a text in another
            // one would come back as the bytes of the file.
            if (! in_array($entry['encoding'], self::DECODED, true)) {
                throw UnreadableExport::because($path, sprintf('the table of "%s" is written in %s, which the reader cannot decode', $entry['table']->file, $entry['encoding']));
            }

            if (! in_array($entry['encoding'], $encodings, true)) {
                throw UnreadableExport::because($path, sprintf('the table of "%s" is written in %s, which the reader does not take', $entry['table']->file, $entry['encoding']));
            }

            // A table is read by its name, so of two tables with one name only the last could be read.
            if (isset($tables[$entry['table']->name])) {
                throw UnreadableExport::because($path, sprintf('it describes two tables named "%s"', $entry['table']->name));
            }

            $tables[$entry['table']->name] = $entry;
        }

        // rows() reads up to two bytes past the record limit, so the limit stays two below the largest integer.
        return new self($directory, $supplier, $tables, min(max(1, $maxRecordBytes), PHP_INT_MAX - 2));
    }

    /**
     * The tables the description names, in its order.
     *
     * @return list<Table>
     */
    public function tables(): array
    {
        return array_values(array_map(static fn (array $entry): Table => $entry['table'], $this->tables));
    }

    /**
     * The records of a table, each keyed by the names of its columns as the description lists them, in
     * the order of the file. A value is the text the file holds; an empty field reads as an empty string.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(string $table): Generator
    {
        $entry = $this->tables[$table] ?? throw UnreadableExport::because($this->directory.'/'.Index::FILE, sprintf('it describes no table "%s"', $table));
        $path = $this->directory.'/'.$entry['table']->file;
        $handle = is_file($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            throw UnreadableExport::because($path, 'the file the description names is not there');
        }

        $names = $entry['table']->header();
        $line = 0;

        try {
            // A record is read up to its line feed, which is not kept, and at most two bytes past the limit:
            // one for a carriage return before the line feed, and one only a longer record reaches. Where
            // fgets() with a length would hold the whole limit for every record, stream_get_line() holds
            // what it found, so a short record costs its own length under any limit.
            while (($read = stream_get_line($handle, $this->maxRecordBytes + 2, "\n")) !== false) {
                $line++;
                $record = rtrim($read, "\r");

                if (strlen($read) > $this->maxRecordBytes + 1 || strlen($record) > $this->maxRecordBytes) {
                    throw UnreadableExport::because($path, sprintf('record %d is longer than %d bytes', $line, $this->maxRecordBytes));
                }

                if ($line < $entry['from'] || $record === '') {
                    continue;
                }

                $fields = str_getcsv($record, $entry['delimiter'], $entry['enclosure'], '');

                if (count($fields) !== count($names)) {
                    throw UnreadableExport::because($path, sprintf('record %d holds %d fields, the description names %d columns', $line, count($fields), count($names)));
                }

                yield array_combine($names, array_map(
                    static fn (?string $field): string => $entry['encoding'] === 'ANSI' ? mb_convert_encoding((string) $field, 'UTF-8', 'Windows-1252') : (string) $field,
                    $fields,
                ));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{table: Table, delimiter: string, enclosure: string, from: int, encoding: string}
     */
    private static function table(DOMXPath $xpath, DOMElement $element, string $path): array
    {
        $file = self::text($xpath, 'URL', $path, $element);
        $columns = [];

        foreach (self::elements($xpath, 'VariableLength/VariablePrimaryKey | VariableLength/VariableColumn', $element) as $column) {
            $name = self::text($xpath, 'Name', $path, $column);
            $description = self::optional($xpath, 'Description', $column);

            if (self::elements($xpath, 'Numeric', $column) !== []) {
                $accuracy = self::optional($xpath, 'Numeric/Accuracy', $column);
                $columns[] = Column::numeric($name, $description === '' ? $name : $description, $accuracy === '' ? null : (int) $accuracy);

                continue;
            }

            $maxLength = self::optional($xpath, 'MaxLength', $column);
            $columns[] = Column::text($name, $description === '' ? $name : $description, $maxLength === '' ? null : (int) $maxLength);
        }

        if ($columns === []) {
            throw UnreadableExport::because($path, sprintf('the table of "%s" names no column', $file));
        }

        $references = array_map(
            static fn (DOMElement $reference): string => trim($reference->textContent),
            self::elements($xpath, 'VariableLength/ForeignKey/References', $element),
        );

        $name = self::optional($xpath, 'Name', $element);
        $description = self::optional($xpath, 'Description', $element);
        $from = self::optional($xpath, 'Range/From', $element);
        $delimiter = self::rawOptional($xpath, 'VariableLength/ColumnDelimiter', $element) ?? ';';
        $enclosure = self::optional($xpath, 'VariableLength/TextEncapsulator', $element);
        $enclosure = $enclosure === '' ? '"' : $enclosure;

        // str_getcsv() takes a delimiter and an enclosure of one byte each. An empty symbol, two characters,
        // a character UTF-8 writes in two bytes, or the white space of an indented element around a symbol
        // is therefore refused here, before a record is read.
        foreach (['column delimiter' => $delimiter, 'text encapsulator' => $enclosure] as $symbol => $value) {
            if (strlen($value) !== 1) {
                throw UnreadableExport::because($path, sprintf('the %s of the table of "%s" is %d bytes long, not one', $symbol, $file, strlen($value)));
            }
        }

        // rows() splits records at a line feed and drops a carriage return before one. A description that
        // names no record delimiter states the default of the standard, a carriage return and a line feed.
        $recordDelimiter = self::rawOptional($xpath, 'VariableLength/RecordDelimiter', $element) ?? "\r\n";

        if (! in_array($recordDelimiter, self::RECORD_DELIMITERS, true)) {
            throw UnreadableExport::because($path, sprintf('the record delimiter of the table of "%s" is neither a line feed nor a carriage return and a line feed', $file));
        }

        // A table without a VariablePrimaryKey names every column as a VariableColumn and has no key.
        $keyed = self::elements($xpath, 'VariableLength/VariablePrimaryKey', $element) !== [];

        return [
            'table' => new Table($file, $name === '' ? $file : $name, $description === '' ? $file : $description, $columns, $references, $keyed),
            'delimiter' => $delimiter,
            'enclosure' => $enclosure,
            'from' => $from === '' ? 1 : max(1, (int) $from),
            'encoding' => self::encoding($xpath, $element),
        ];
    }

    /** The encoding a table declares, or ANSI, the one the standard assumes when it names none. */
    private static function encoding(DOMXPath $xpath, DOMElement $element): string
    {
        foreach (self::ENCODINGS as $encoding) {
            if (self::elements($xpath, $encoding, $element) !== []) {
                return $encoding;
            }
        }

        return self::ENCODINGS[0];
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(DOMXPath $xpath, string $query, ?DOMElement $context = null): array
    {
        $nodes = $xpath->query($query, $context);
        $elements = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $elements[] = $node;
                }
            }
        }

        return $elements;
    }

    private static function text(DOMXPath $xpath, string $query, string $path, ?DOMElement $context = null): string
    {
        $text = self::optional($xpath, $query, $context);

        if ($text === '') {
            throw UnreadableExport::because($path, sprintf('the element %s is missing or empty', $query));
        }

        return $text;
    }

    private static function optional(DOMXPath $xpath, string $query, ?DOMElement $context = null): string
    {
        return trim(self::rawOptional($xpath, $query, $context) ?? '');
    }

    /** The text of the first element the query finds, untrimmed, because a delimiter may be a space. */
    private static function rawOptional(DOMXPath $xpath, string $query, ?DOMElement $context = null): ?string
    {
        $elements = self::elements($xpath, $query, $context);

        return $elements === [] ? null : $elements[0]->textContent;
    }
}
