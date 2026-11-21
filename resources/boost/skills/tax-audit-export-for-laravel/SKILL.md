---
name: tax-audit-export-for-laravel
description: >
  Install, configure, and apply the Tax Audit Export for Laravel package in a Laravel
  application.
license: MIT
metadata:
  author: pushery
---

# Tax Audit Export for Laravel

Use this skill when a Laravel application installs or integrates the
`pushery/tax-audit-export-for-laravel` package. The full reference is at
<https://docs.pushery.com/tax-audit-export-for-laravel/>.

## Primary Goal

Apply the package's public API in the smallest correct way for the consuming
application.

## Workflow

### 1. Install

```bash
composer require pushery/tax-audit-export-for-laravel
```

The service provider is registered automatically through package discovery.

### 2. Configure

Publish everything at once, or one group at a time:

```bash
php artisan vendor:publish --tag="tax-audit-export"
php artisan vendor:publish --tag="tax-audit-export-config"
php artisan vendor:publish --tag="tax-audit-export-lang"
```

Every option in `config/tax-audit-export.php` is documented inline. An export started from
`app(Pushery\TaxAuditExport\ExportSettings::class)->export()` names the data supplier that
`TAX_AUDIT_EXPORT_SUPPLIER_NAME`, `TAX_AUDIT_EXPORT_SUPPLIER_LOCATION` and
`TAX_AUDIT_EXPORT_SUPPLIER_COMMENT` set. An export built as `new Export(new DataSupplier(...))`
names the supplier it is handed and reads none of them. `ExportSettings` reads the configuration
once per request or queued job, the first time it is resolved there. To name another supplier per
request or job, under Octane or in a long-running queue worker as well, set it in the
configuration before that. The package ships no migrations, routes or views.

### 3. Apply the package

Describe each table with `Pushery\TaxAuditExport\Table` and its columns with
`Pushery\TaxAuditExport\Column::text()` or `Column::numeric()`. The first column is the key,
unless the table is built with `keyed: false`, as the tables of the DSFinV-K are, and then no
other table can reference it. A table that names a record of another carries that table's key
column under the same name and lists the table's name in its references. Write the export with
`app(Pushery\TaxAuditExport\ExportSettings::class)->export()`, add each table with its rows, and
call `writeTo()` with a directory of its own. Read an export with `Reader::open()` or
`ExportSettings::reader()`, and validate an `index.xml` in a test with
`Pushery\TaxAuditExport\Testing\IndexValidator::errors()`.

## Examples

```php
use Pushery\TaxAuditExport\Column;
use Pushery\TaxAuditExport\ExportSettings;
use Pushery\TaxAuditExport\Table;

$invoices = new Table('invoices.csv', 'Invoices', 'The invoices of the period', [
    Column::text('invoice_number', 'Number of the invoice', 30),
    Column::numeric('gross_amount', 'Gross amount in euros', 2),
]);

// $rows is any iterable of arrays keyed by column name, a lazy collection included.
$rows = [
    ['invoice_number' => 'I-1', 'gross_amount' => '99.90'],
];

app(ExportSettings::class)
    ->export()
    ->add($invoices, $rows)
    ->writeTo(storage_path('app/tax-audit/2025'));
```

## Anti-Patterns

- Formatting a number with a comma or with grouping, such as `1.250,00`. The `index.xml`
  states a point as the decimal symbol (and a comma as the grouping symbol, which the files
  never use), and the package refuses such a value. Pass the number as an integer, as a string
  such as `'1250.00'`, or as a float in a column that names an accuracy.
- Writing more decimals than the column declares. A string carries at most as many decimals
  as the accuracy of its column, and a column without an accuracy holds whole numbers, so
  `'1250.00'` needs `Column::numeric($name, $description, 2)`. The package refuses the value
  otherwise, because an import may cut the decimals off or stop.
- Writing a text longer than the greatest length of its column. The package counts the
  characters and refuses a longer text, because an import may size the column by that length.
  Give a column no length where its values may run longer.
- Opening the CSV files of an export directly in a spreadsheet. A text is written as given for
  the tax authority, so one that begins with `=`, `+`, `-`, `@` or a tab is a formula to a
  spreadsheet. Read the files through an import that follows the `index.xml` and takes such a
  column as text.
- Loading a whole table into an array before writing it, or passing a query's `cursor()`. A
  cursor yields objects, which the export refuses, and the PDO driver buffers its whole result
  all the same. Select the `id` with the columns, call `lazyById(1000)` and `map()` each row to
  an array that names the columns of the table, and each record is written as it arrives.
- Writing a large export inside a web request. It can run longer than the request is allowed to
  take, so start it from a queued job or an Artisan command.
- Changing the shipped `gdpdu-01-09-2004.dtd`, or writing a definition of one's own beside the
  `index.xml`. The tax authority reads the published file, and the package writes it unchanged.
- Writing an export into a directory that already holds one. Give every export a directory of
  its own; the package refuses a directory with an `index.xml`.
