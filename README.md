# Compound File

[![Latest Stable Version](https://img.shields.io/packagist/v/dkulyk/compound-file.svg)](https://packagist.org/packages/dkulyk/compound-file)
[![Total Downloads](https://img.shields.io/packagist/dt/dkulyk/compound-file.svg)](https://packagist.org/packages/dkulyk/compound-file)
[![Tests](https://github.com/dkulyk/compound-file/actions/workflows/tests.yml/badge.svg)](https://github.com/dkulyk/compound-file/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/dkulyk/compound-file/php)](https://packagist.org/packages/dkulyk/compound-file)
[![License](https://img.shields.io/github/license/dkulyk/compound-file)](LICENSE)

A PHP library for reading and writing Microsoft Compound File Binary Format
(CFBF), also known as OLE2 or Compound Document File. It provides structured
access to the storages and streams inside legacy Microsoft Office files such as
`.doc`, `.xls`, `.ppt`, and `.msg`.

## Features

- CFBF version 3 and version 4
- FAT, DIFAT, and mini-FAT chains
- 512-byte and 4096-byte sectors
- Little-endian files, the only byte order MS-CFB allows
- 64-bit stream sizes
- UTF-16LE names converted to UTF-8
- Nested storages and case-insensitive path lookup
- Incremental and seekable stream reading
- Creation and full-file rewriting of compound files
- Lazy copying of unchanged streams from existing files
- Atomic filesystem saves
- Native read-only `ole2://` PHP stream wrapper
- Header, directory metadata, and allocation-table inspection
- Validation of signatures, bounds, references, and cyclic chains

## Requirements

- PHP 8.1 or newer
- `mbstring`
- A 64-bit PHP build

## Installation

```bash
composer require dkulyk/compound-file
```

## Quick start

```php
use DK\CompoundFile\CompoundFile;

$file = CompoundFile::open('document.xls');

foreach ($file->getEntries() as $entry) {
    printf("%s (%d bytes)\n", $entry->getPath(), $entry->getSize());
}

$workbook = $file->getStreamContents('Workbook');
```

Create a new compound file:

```php
use DK\CompoundFile\CompoundFileWriter;

$writer = CompoundFileWriter::create();
$writer->createStorage('ObjectPool/Object 1');
$writer->setStreamContents('Workbook', $workbook);
$writer->setStreamContents('ObjectPool/Object 1/Data', $objectData);
$writer->save('result.xls');
```

Paths use `/` between nested storages. Backslashes are accepted as well:

```php
$stream = $file->openStream('ObjectPool/Object 1/Data');
```

## Documentation

| Guide | What it covers |
| --- | --- |
| [Reading](docs/reading.md) | Whole and incremental stream reads, PHP resources, closing a file, memory use, header and directory inspection |
| [Writing](docs/writing.md) | Creating and modifying files, resource-backed streams, entry metadata |
| [Stream wrapper](docs/stream-wrapper.md) | The read-only `ole2://` wrapper for `fopen()` and `scandir()` |
| [API reference](docs/api.md) | Public classes and methods, and what the library throws |

Malformed or unsupported files throw
`DK\CompoundFile\Exception\CfbfException`; invalid arguments throw
`InvalidArgumentException`. The parser is strict: it rejects a malformed file
instead of repairing it.

When a parser is no longer needed in a long-running process, call
`$file->close()`, or `$file->release()` to keep already open streams readable.
See [Closing a file](docs/reading.md#closing-a-file).

## Development

```bash
composer install
composer check
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the contribution workflow,
benchmarks, and pull-request conventions. Notable changes are recorded in
[CHANGELOG.md](CHANGELOG.md), and vulnerabilities should be reported according
to [SECURITY.md](SECURITY.md).

## License

Released under the [MIT License](LICENSE).
