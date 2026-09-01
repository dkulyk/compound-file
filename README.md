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
- Little-endian and big-endian files
- 64-bit stream sizes
- UTF-16LE/BE names converted to UTF-8
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

## Writing compound files

### Creating a file

`CompoundFileWriter::create()` creates a version 3 file with 512-byte sectors
and little-endian integers by default:

```php
use DK\CompoundFile\CompoundFileWriter;
use DK\CompoundFile\Header;

$writer = CompoundFileWriter::create();
$writer->setStreamContents('Data', $contents);
$writer->save('container.ole');

// Version 4 uses 4096-byte sectors.
$version4 = CompoundFileWriter::create(4);

// Big-endian output is supported for compatible consumers.
$bigEndian = CompoundFileWriter::create(3, Header::BIG_ENDIAN);
```

`createStorage()` creates all missing parents. A stream's parent must already
exist, which prevents an accidental typo from silently changing the directory
structure:

```php
$writer->createStorage('ObjectPool/Object 1');
$writer->setStreamContents('ObjectPool/Object 1/Data', $contents);
```

Paths use `/` or `\` separators and are matched case-insensitively. Each entry
name is limited to 31 UTF-16 code units and cannot contain `:`, `!`, `/`, `\`,
or a null byte, as required by CFBF.

### Modifying an existing file

Opening an existing container imports its directory and metadata. Stream bytes
are copied lazily when `save()` runs, so unchanged large streams are not loaded
into memory as a single string:

```php
$writer = CompoundFileWriter::open('template.doc');
$writer->setStreamContents('WordDocument', $wordDocument);
$writer->remove('ObjectPool/Obsolete Object');
$writer->save('result.doc');
```

An existing seekable resource can be imported with
`CompoundFileWriter::fromResource($resource)`. The caller retains ownership and
must keep the source open until saving finishes.

Saving to the original path is supported. Filesystem saves are flushed and
synchronized to storage, written to a temporary file in the destination
directory, and then replaced atomically. Existing POSIX permissions are
preserved.

### Resource-backed streams and output

Large stream contents can come from a seekable PHP resource:

```php
$source = fopen('payload.bin', 'rb');
$writer->setStreamResource('Payload', $source);
$writer->save('container.ole');
fclose($source);
```

The caller retains ownership and must keep the source open until the save has
finished. The complete resource from byte zero is stored, regardless of its
current cursor position.

Write a container to an existing resource with `saveToResource()`:

```php
$output = fopen('php://temp', 'w+b');
$writer->saveToResource($output);
rewind($output);
```

The output must be writable and seekable. It is truncated before writing and
remains open afterward. Do not use the same resource as both an imported source
and the output.

### Entry metadata

```php
$writer->setClassId('ObjectPool/Object 1', '00020906-0000-0000-c000-000000000046');
$writer->setStateBits('ObjectPool/Object 1', 0x00000001);
$writer->setTimestamps(
    'ObjectPool/Object 1',
    new DateTimeImmutable('2025-01-01 00:00:00 UTC'),
    new DateTimeImmutable('2025-01-02 00:00:00 UTC'),
);
```

The writer rebuilds FAT, DIFAT, mini-FAT, mini-stream, directory sectors, and
red-black directory trees on every save. Stream sizes below 4096 bytes use the
mini-stream; streams at or above that boundary use the regular FAT.

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

### `CompoundFileWriter`

| Method | Description |
| --- | --- |
| `create(int $version = 3, string $byteOrder = Header::LITTLE_ENDIAN): self` | Create an empty writer model. |
| `open(string $path): self` | Import an existing compound file lazily. |
| `fromResource(resource $resource): self` | Import a compound file from an existing resource. |
| `fromCompoundFile(CompoundFile $file): self` | Import an existing parsed container. |
| `getEntryPaths(): array` | Return all logical entry paths. |
| `hasEntry(string $path): bool` | Check whether a stream or storage exists. |
| `createStorage(string $path): self` | Create a storage and missing parents. |
| `setStreamContents(string $path, string $contents): self` | Create or replace a stream from a string. |
| `setStreamResource(string $path, resource $resource): self` | Create or replace a stream from a resource. |
| `remove(string $path): bool` | Remove a stream or complete storage subtree. |
| `setClassId(string $path, string $classId): self` | Set entry CLSID metadata. |
| `setStateBits(string $path, int $bits): self` | Set application state bits. |
| `setTimestamps(string $path, ?DateTimeImmutable $created, ?DateTimeImmutable $modified): self` | Set FILETIME metadata. |
| `save(string $path): void` | Atomically save to a filesystem path. |
| `saveToResource(resource $resource): void` | Save to an open seekable resource. |

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
composer benchmark
```

The quality gate includes Composer validation, PHP syntax checks, PSR-12
formatting, PHPStan level 8, and PHPUnit. Apply formatting with
`composer format`.

The benchmark performs repeated random reads from the largest stream in the
LibreOffice fixture. Pass another CFBF file with
`composer benchmark -- /path/to/document.doc`.

Run the optional writer interoperability test through LibreOffice with:

```bash
SOFFICE=/path/to/soffice vendor/bin/phpunit tests/WriterInteropTest.php
```

Tests run on PHP 8.1 through PHP 8.5 and cover allocation chains, both byte
orders, seeking, Unicode names, malformed files, wrapper streams/directories,
metadata, writer round-trips, stream-size boundaries, multi-sector FAT and
mini-FAT tables, DIFAT output, nested directory trees, resource I/O, atomic
replacement, and rewriting a real LibreOffice document without changing its
stream bytes.

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
