<?php

declare(strict_types=1);

namespace Pushery\TaxAuditExport;

use Illuminate\Contracts\Config\Repository;

/**
 * The configuration of the package, read from the host's config: the data supplier every export names,
 * and the limits of the reader. An application resolves it from the container and starts an export or a
 * reader from it. The service provider binds it scoped: it is read the first time a request or a queued
 * job resolves it and kept until that request or job ends, so under Octane or a long-running queue
 * worker the next request or job reads the config again.
 *
 * Each value is read with the type it needs, and a value of another type falls back to the one the
 * package ships, the way the published config would have it. A supplier name left empty, such as
 * `TAX_AUDIT_EXPORT_SUPPLIER_NAME=` with nothing after it, names the application instead: the value
 * of `app.name`, or `Laravel` where that is empty as well.
 */
final readonly class ExportSettings
{
    public const int MAX_RECORD_BYTES = 1048576;

    public const array ENCODINGS = ['UTF8', 'ANSI'];

    public const int MAX_INDEX_BYTES = 8388608;

    /**
     * @param  list<string>  $encodings
     */
    public function __construct(
        public DataSupplier $supplier,
        public int $maxRecordBytes = self::MAX_RECORD_BYTES,
        public array $encodings = self::ENCODINGS,
        public int $maxIndexBytes = self::MAX_INDEX_BYTES,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $name = $config->get('tax-audit-export.supplier.name');
        $location = $config->get('tax-audit-export.supplier.location');
        $comment = $config->get('tax-audit-export.supplier.comment');
        $maxRecordBytes = $config->get('tax-audit-export.reader.max_record_bytes');
        $encodings = $config->get('tax-audit-export.reader.encodings');
        $maxIndexBytes = $config->get('tax-audit-export.reader.max_index_bytes');

        // The index.xml names a supplier in every export, so an empty name names the application.
        $name = is_string($name) && trim($name) !== '' ? $name : $config->get('app.name');

        return new self(
            new DataSupplier(
                is_string($name) && trim($name) !== '' ? $name : 'Laravel',
                is_string($location) ? $location : '',
                is_string($comment) ? $comment : '',
            ),
            is_int($maxRecordBytes) && $maxRecordBytes >= 1024 ? $maxRecordBytes : self::MAX_RECORD_BYTES,
            is_array($encodings) ? array_values(array_filter($encodings, is_string(...))) : self::ENCODINGS,
            is_int($maxIndexBytes) && $maxIndexBytes >= 1024 ? $maxIndexBytes : self::MAX_INDEX_BYTES,
        );
    }

    /** A new export that names the configured supplier. */
    public function export(string $media = 'Datenüberlassung'): Export
    {
        return new Export($this->supplier, $media);
    }

    /** A reader of the export in the directory, held to the configured limits. */
    public function reader(string $directory): Reader
    {
        return Reader::open($directory, $this->maxRecordBytes, $this->encodings, $this->maxIndexBytes);
    }
}
