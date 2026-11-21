<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tax Audit Export for Laravel
    |--------------------------------------------------------------------------
    |
    | Configuration for the package. Published to config/tax-audit-export.php with
    | `php artisan vendor:publish --tag=tax-audit-export-config`.
    |
    | Read environment values with env() here and nowhere else. Outside a config
    | file env() returns null the moment the host app caches its config, so the
    | value silently collapses to its default in production and nowhere else.
    |
    */

    /*
    | The business that hands the data over, as the `DataSupplier` element of the
    | `index.xml` names it. ExportSettings::fromConfig() builds it from these lines,
    | and an export started from the settings, app(ExportSettings::class)->export(),
    | names it. An export built with a DataSupplier of its own names that one and
    | reads none of these lines. A name left empty in the `.env`, such as
    | `TAX_AUDIT_EXPORT_SUPPLIER_NAME=` with nothing after it, names the
    | application instead: the value of `app.name`, or `Laravel` where that is
    | empty as well.
    */
    'supplier' => [
        'name' => env('TAX_AUDIT_EXPORT_SUPPLIER_NAME', env('APP_NAME', 'Laravel')),
        'location' => env('TAX_AUDIT_EXPORT_SUPPLIER_LOCATION', ''),
        'comment' => env('TAX_AUDIT_EXPORT_SUPPLIER_COMMENT', ''),
    ],

    /*
    | Limits of the reader, for an export that was written somewhere else.
    |
    | `max_record_bytes` is the longest record the reader takes, in bytes without its
    | line end, 1 MiB unless set. A longer one is refused instead of being read into
    | memory whole, and a shorter one takes the memory of its own length. A value
    | below 1024, or one that is no number, keeps the default, because `(int) 'abc'`
    | is 0, and a typo in a `.env` must not leave a limit that refuses every record.
    |
    | `max_index_bytes` is the largest `index.xml` the reader parses, 8 MiB unless
    | set. A larger one is refused before it is read whole, because parsing holds
    | the whole description in memory. A value below 1024, or one that is no
    | number, keeps the default.
    |
    | `encodings` lists the encodings a table may declare, out of the two the
    | reader decodes: `UTF8` is read as it stands and `ANSI` is converted from
    | Windows-1252. A table in an encoding the list leaves out is refused, and so
    | is one in `OEM`, `UTF16`, `UTF7` or `Macintosh` even where the list names
    | it, because its text would come out garbled. A host that publishes this file
    | and trims the list keeps its own list: a list is replaced whole on a merge,
    | never merged.
    */
    'reader' => [
        'max_record_bytes' => is_numeric($maxRecordBytes = env('TAX_AUDIT_EXPORT_MAX_RECORD_BYTES')) && (int) $maxRecordBytes >= 1024
            ? (int) $maxRecordBytes
            : 1048576,
        'max_index_bytes' => is_numeric($maxIndexBytes = env('TAX_AUDIT_EXPORT_MAX_INDEX_BYTES')) && (int) $maxIndexBytes >= 1024
            ? (int) $maxIndexBytes
            : 8388608,
        'encodings' => ['UTF8', 'ANSI'],
    ],

];
