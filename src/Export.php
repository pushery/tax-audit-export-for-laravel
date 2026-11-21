<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

use Pushery\TaxAuditExport\Exceptions\UnwritableExport;
use Throwable;

/**
 * An export for a tax audit, written into one directory: a CSV file for each table, the `index.xml` that
 * describes them and the document type definition it declares.
 *
 * The description is built before the first file is written, so a definition it refuses leaves the
 * directory as it was. The rows of a table are written one record at a time, so a table larger than the
 * memory of the process can be exported from a source that loads it in chunks and yields arrays, such as
 * a query's `lazyById()` with each row mapped to the columns of the table. A query's `cursor()` is none:
 * it yields objects, and the database driver buffers its whole result. An export that stops once it
 * has begun to write, at a value its column cannot hold, at a write that fails or at an exception of its
 * rows, removes the files and directories it created before the exception goes on, so it leaves the
 * directory as it was too.
 */
final class Export
{
    /** @var list<array{Table, iterable<array<string, int|float|string|null>>}> */
    private array $tables = [];

    public function __construct(
        private readonly DataSupplier $supplier,
        private readonly string $media = 'Datenüberlassung',
    ) {}

    /**
     * @param  iterable<array<string, int|float|string|null>>  $rows
     */
    public function add(Table $table, iterable $rows): self
    {
        $this->tables[] = [$table, $rows];

        return $this;
    }

    /**
     * Writes the export into the directory, which is created where it does not exist, and refused where
     * it holds an `index.xml` already. An export that stops before its `index.xml` is written removes the
     * files and directories it created, so the next attempt can write into the same directory.
     *
     * @return list<string> the paths written, the `index.xml` last
     */
    public function writeTo(string $directory): array
    {
        $directory = rtrim($directory, '/');
        $index = Index::describe($this->supplier, array_map(static fn (array $entry): Table => $entry[0], $this->tables), $this->media);

        if (is_file($directory.'/'.Index::FILE)) {
            throw UnwritableExport::because($directory, 'it holds an index.xml already, and an export is written into a directory of its own');
        }

        $made = $this->missingDirectories($directory);
        $created = [];
        $written = [];

        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
                throw UnwritableExport::because($directory, 'the directory cannot be created');
            }

            foreach ($this->tables as [$table, $rows]) {
                $path = $this->writeTable($directory.'/'.$table->file, $table, $rows);
                $created[] = $path;
                $written[] = $path;
            }

            // A file of this name the directory holds already is written over and stays where the export
            // stops, since only what the export created goes again.
            foreach ([Dtd::FILE => Dtd::contents(), Index::FILE => $index] as $file => $contents) {
                $path = $directory.'/'.$file;

                if (! file_exists($path)) {
                    $created[] = $path;
                }

                $written[] = $this->put($path, $contents);
            }
        } catch (Throwable $stopped) {
            $this->remove($created, $made);

            throw $stopped;
        }

        return $written;
    }

    /**
     * Creates the file of a table and writes its records. The file is this export's own from the moment
     * it is created, so a record that stops the writing takes the file with it. So does a write that
     * stores fewer bytes than it was given, as on a full disk or an exhausted quota, and a flush the
     * file system refuses, which is where a stream that buffers its writes reports a failed one.
     *
     * @param  iterable<array<string, int|float|string|null>>  $rows
     */
    private function writeTable(string $path, Table $table, iterable $rows): string
    {
        $handle = @fopen($path, 'xb');

        if ($handle === false) {
            throw UnwritableExport::because($path, 'the file cannot be created');
        }

        $complete = false;

        try {
            foreach ($this->lines($table, $rows) as $line) {
                if (@fwrite($handle, $line) !== strlen($line)) {
                    throw UnwritableExport::because($path, 'the file cannot be written');
                }
            }

            if (! @fflush($handle)) {
                throw UnwritableExport::because($path, 'the file cannot be written');
            }

            $complete = true;
        } finally {
            fclose($handle);

            if (! $complete) {
                @unlink($path);
            }
        }

        return $path;
    }

    /**
     * The lines of a table's file: its header, then a record for each row. `fwrite()` returns `false`
     * for a failed write and the count it stored for a short one, and neither is the length of the line
     * it was given, which is how the caller tells a stopped write.
     *
     * @param  iterable<array<string, int|float|string|null>>  $rows
     * @return iterable<int, string>
     */
    private function lines(Table $table, iterable $rows): iterable
    {
        yield Csv::header($table);

        foreach ($rows as $row) {
            yield Csv::record($table, $row);
        }
    }

    private function put(string $path, string $contents): string
    {
        if (@file_put_contents($path, $contents) === false) {
            throw UnwritableExport::because($path, 'the file cannot be written');
        }

        return $path;
    }

    /**
     * The directories that creating this one makes, the deepest first: the directory itself and each
     * parent up to the first that exists.
     *
     * @return list<string>
     */
    private function missingDirectories(string $directory): array
    {
        $missing = [];

        for ($path = $directory; $path !== dirname($path) && ! file_exists($path); $path = dirname($path)) {
            $missing[] = $path;
        }

        return $missing;
    }

    /**
     * Removes the files an export created, then the directories it made, the deepest first. A directory
     * that holds a file the export did not write is not empty and stays, with the file.
     *
     * @param  list<string>  $files
     * @param  list<string>  $directories
     */
    private function remove(array $files, array $directories): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }

        foreach ($directories as $directory) {
            @rmdir($directory);
        }
    }
}
