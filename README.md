<p align="center">
  <a href="https://github.com/pushery/tax-audit-export-for-laravel">
    <img src="https://raw.githubusercontent.com/pushery/tax-audit-export-for-laravel/main/art/header.png" alt="Tax Audit Export for Laravel" width="100%">
  </a>
</p>

# Tax Audit Export for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/tax-audit-export-for-laravel.svg)](https://packagist.org/packages/pushery/tax-audit-export-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/tax-audit-export-for-laravel/php.svg)](https://packagist.org/packages/pushery/tax-audit-export-for-laravel)
[![Laravel Versions](https://badge.laravel.cloud/badge/pushery/tax-audit-export-for-laravel?style=flat)](https://packagist.org/packages/pushery/tax-audit-export-for-laravel)
[![License](https://img.shields.io/packagist/l/pushery/tax-audit-export-for-laravel.svg)](LICENSE)

[![Tests: Pest 5](https://img.shields.io/badge/tests-Pest%205-8BC34A.svg)](https://pestphp.com)
![Coverage: 100%](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)
![Type coverage: 100%](https://img.shields.io/badge/types-100%25-brightgreen.svg)
[![PHPStan: level 10](https://img.shields.io/badge/PHPStan-10-blue.svg)](https://phpstan.org)
[![Code style: Pint](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)

Writes the index.xml, DTD and CSV files of a tax audit data export (GoBD, GDPdU, DSFinV-K) from table definitions, with a reader and a test helper.

## Documentation

**Full documentation at
[docs.pushery.com/tax-audit-export-for-laravel](https://docs.pushery.com/tax-audit-export-for-laravel/).**
This README is the quick start; the guides and the configuration reference live there.

## Requirements

- PHP 8.4+ with the `dom`, `libxml` and `mbstring` extensions, which a standard PHP build carries
- Laravel 13+

The package keeps no table and needs no database: it writes files from the rows your
application hands it.

Tested on the floor of the declared PHP range, because a `>=` promise is only proven
by running on the boundary, and on the minor version above it, so the range above the
floor is exercised here rather than first by a consumer.

## Installation

```bash
composer require pushery/tax-audit-export-for-laravel
```

The service provider is registered automatically through package discovery.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=tax-audit-export-config
```

This writes `config/tax-audit-export.php`, where every option is documented inline. An export
started from `Pushery\TaxAuditExport\ExportSettings` names the data supplier that
`TAX_AUDIT_EXPORT_SUPPLIER_NAME`, `TAX_AUDIT_EXPORT_SUPPLIER_LOCATION` and
`TAX_AUDIT_EXPORT_SUPPLIER_COMMENT` set. An export built with a `DataSupplier` of its own names that
one and reads none of them. The package ships no migrations, routes or views.

## Usage

Describe each table with its file, its name, a description and its columns; the first column is
the key. Then start the export from the settings, so that it names the configured data supplier,
and write it into a directory of its own:

```php
use Pushery\TaxAuditExport\Column;
use Pushery\TaxAuditExport\ExportSettings;
use Pushery\TaxAuditExport\Table;

$invoices = new Table('invoices.csv', 'Invoices', 'The invoices of the period', [
    Column::text('invoice_number', 'Number of the invoice', 30),
    Column::numeric('gross_amount', 'Gross amount in euros', 2),
]);

app(ExportSettings::class)->export()
    ->add($invoices, [
        ['invoice_number' => 'I-1', 'gross_amount' => '99.90'],
    ])
    ->writeTo(storage_path('app/tax-audit/2025'));
```

The directory then holds `invoices.csv`, the `index.xml` after the description standard of the
GoBD, and the document type definition `gdpdu-01-09-2004.dtd`, byte for byte as the BZSt publishes
it. `Pushery\TaxAuditExport\Reader` opens an export through its `index.xml` alone, and
`Pushery\TaxAuditExport\Testing\IndexValidator` checks an `index.xml` against the definition in a
test. The [documentation](https://docs.pushery.com/tax-audit-export-for-laravel/) covers columns
and types, the format of the files, links between tables and the limits of the reader.

## Testing

The test suite is not part of the published package: the tests and their PHPUnit
configuration stay in the development repository, so a clone of this repository has no
`composer test`. [CONTRIBUTING.md](https://github.com/pushery/tax-audit-export-for-laravel/blob/main/CONTRIBUTING.md) says how a change is tested on its way in.

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities
privately rather than opening a public issue.

## Built by Pushery

This package is built and maintained by [Pushery](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://www.wirekit.app) is Pushery's open-source
Livewire component kit.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
