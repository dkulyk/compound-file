# Compound File

[![Latest release](https://img.shields.io/github/v/release/dkulyk/compound-file)](https://github.com/dkulyk/compound-file/releases)
[![Tests](https://github.com/dkulyk/compound-file/actions/workflows/tests.yml/badge.svg)](https://github.com/dkulyk/compound-file/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/dkulyk/compound-file/php)](https://packagist.org/packages/dkulyk/compound-file)
[![License](https://img.shields.io/github/license/dkulyk/compound-file)](LICENSE)

A read-only PHP library for Microsoft Compound File Binary Format (CFBF), also
known as OLE2 or Compound Document File. It provides structured access to the
storages and streams inside legacy Microsoft Office files such as `.doc`,
`.xls`, `.ppt`, and `.msg`.

## Features

- CFBF version 3 and version 4
- FAT, DIFAT, and mini-FAT chains
- 512-byte and 4096-byte sectors
- Little-endian and big-endian files
- 64-bit stream sizes
- UTF-16LE/BE names converted to UTF-8
- Nested storages and case-insensitive path lookup
- Incremental and seekable stream reading
- Native read-only `ole2://` PHP stream wrapper
- Header, directory metadata, and allocation-table inspection
- Validation of signatures, bounds, references, and cyclic chains

## Requirements

- PHP 8.1 or newer
- `mbstring`
- A 64-bit PHP build for streams outside the 32-bit integer range

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

Paths use `/` between nested storages. Backslashes are accepted as well:

```php
$stream = $file->openStream('ObjectPool/Object 1');
```

## Reading streams

### Complete stream

```php
$contents = $file->getStreamContents('WordDocument');
```

The method accepts either a path or an existing `DirectoryEntry`:

```php
$entry = $file->findEntry('WordDocument');

if ($entry !== null && $entry->isStream()) {
    $contents = $file->getStreamContents($entry);
}
```

### Incremental reading

```php
$stream = $file->openStream('WordDocument');

$header = $stream->read(32);
$stream->seek(-16, SEEK_END);
$trailer = $stream->read(16);
```

`Stream::seek()` supports `SEEK_SET`, `SEEK_CUR`, and `SEEK_END`. It returns
`false` when the requested position is outside the logical stream.

### Existing PHP resource

```php
$handle = fopen('document.doc', 'rb');
$file = CompoundFile::fromResource($handle);
```

The resource must be seekable. Ownership remains with the caller, so the
library does not close it.

## PHP stream wrapper

Register the wrapper once and use ordinary PHP stream functions:

```php
use DK\CompoundFile\StreamWrapper;

StreamWrapper::register();

$handle = fopen('ole2://document.doc#WordDocument', 'rb');
$contents = stream_get_contents($handle);
```

For arbitrary paths and Unicode or reserved characters, build an encoded URL:

```php
$url = StreamWrapper::url('/documents/example.xls', 'ObjectPool/Object 1');
$handle = fopen($url, 'rb');
```

Storages are exposed as read-only directories:

```php
$root = StreamWrapper::directoryUrl('/documents/example.xls');
$entries = scandir($root);

$objectPool = StreamWrapper::directoryUrl(
    '/documents/example.xls',
    'ObjectPool',
);
```

The wrapper supports `fread()`, `feof()`, `ftell()`, `fseek()`, `stat()`,
`is_file()`, `is_dir()`, `opendir()`, `readdir()`, and `scandir()`.

## Inspecting the container

### Header

```php
$header = $file->getHeader();

echo $header->getMajorVersion();
echo $header->getSectorSize();
echo $header->getByteOrder();
```

`Header` exposes the CFBF version, byte order, sector shifts and sizes,
transaction signature, mini-stream cutoff, and declared FAT, mini-FAT, and
DIFAT locations and counts.

### Directory entries

```php
$entry = $file->findEntry('ObjectPool');

if ($entry !== null) {
    echo $entry->getName();
    echo $entry->getPath();
    echo $entry->getClassId();

    $children = $file->getChildren($entry->getPath());
}
```

Directory metadata includes type, tree color, sibling and child IDs, CLSID,
state bits, stream size, and creation/modification timestamps.

### Allocation tables

```php
$tables = $file->getAllocationTable();

$difat = $tables->getDifat();
$fat = $tables->getFat();
$miniFat = $tables->getMiniFat();
```

The returned arrays are diagnostic snapshots and cannot mutate parser state.

## API reference

### `CompoundFile`

| Method | Description |
| --- | --- |
| `open(string $path): self` | Open a filesystem file. |
| `fromResource(resource $resource): self` | Parse an existing seekable resource. |
| `getHeader(): Header` | Return immutable header metadata. |
| `getAllocationTable(): AllocationTable` | Return allocation-table snapshots. |
| `getEntries(): array` | Return all non-empty entries, including the root. |
| `getChildren(string $storage = ''): array` | Return direct children of a storage. |
| `getEntryById(int $id): ?DirectoryEntry` | Find an entry by raw SID. |
| `findEntry(string $path): ?DirectoryEntry` | Find an entry by path. |
| `hasStream(string $path): bool` | Check whether a stream exists. |
| `openStream(string\|DirectoryEntry $entry): Stream` | Open a seekable logical stream. |
| `getStreamContents(string\|DirectoryEntry $entry): string` | Read a complete stream. |

### `DirectoryEntry`

Provides identity, name, path, type, size, CLSID, state bits, timestamps,
red/black tree metadata, and raw or object navigation for sibling and child
entries.

### `Stream`

Provides `getSize()`, `tell()`, `eof()`, `read()`, `getContents()`, and
`seek()`.

### `StreamWrapper`

Provides `register()`, `url()`, and `directoryUrl()`.

## Error handling

Malformed or unsupported files throw
`DK\CompoundFile\Exception\CfbfException`. This includes invalid signatures,
truncated data, out-of-range sector references, invalid directory trees, and
cyclic allocation or directory chains.

Invalid caller arguments throw `InvalidArgumentException`. Wrapper open
failures follow PHP conventions and return `false` from `fopen()`.

## Development

```bash
composer install
composer check
```

The quality gate includes Composer validation, PHP syntax checks, PSR-12
formatting, PHPStan level 8, and PHPUnit. Apply formatting with
`composer format`.

Tests run on PHP 8.1 through PHP 8.5 and cover allocation chains, both byte
orders, seeking, Unicode names, malformed files, wrapper streams/directories,
and metadata inspection.

### Integration fixture

The Word 97 fixture is generated from this README through LibreOffice's
CommonMark importer:

```bash
composer fixtures
```

The command uses `soffice` from `PATH`. Set `SOFFICE=/custom/path/soffice` to
select another LibreOffice binary.

## License

Released under the [MIT License](LICENSE).
