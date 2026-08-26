# OLE2 / CFBF reader for PHP

A small, read-only implementation of the OLE2 Compound File Binary Format
(CFBF) for PHP 8.1 and newer. It parses version 3 and version 4 files, FAT,
DIFAT, mini-FAT, nested storages, UTF-16 names, and both byte orders.

The library deliberately exposes only four public classes. Format bookkeeping
stays internal, so application code works with named entries and streams.

## Installation

```bash
composer require dkulyk/compound-file
```

The `mbstring` extension is required. A 64-bit PHP build is required only when a
version 4 stream is larger than the integer range of a 32-bit build.

## Quick start

```php
use DK\CompoundFile\CompoundFile;

$document = CompoundFile::open('/documents/example.xls');

foreach ($document->getEntries() as $entry) {
    printf("%s (%d bytes)\n", $entry->getPath(), $entry->getSize());
}

if ($document->hasStream('Workbook')) {
    $bytes = $document->getStreamContents('Workbook');
}
```

Paths use `/` between nested storages. Lookups are case-insensitive, as CFBF
directory-name comparisons are. Both `Storage/Stream` and
`Storage\\Stream` are accepted.

## Incremental reading

`Stream` avoids copying the complete logical stream when only a range is
needed:

```php
$stream = $document->openStream('ObjectPool/Object 1');
$header = $stream->read(32);
$stream->seek(-16, SEEK_END);
$trailer = $stream->read(16);
```

`read()` returns no more than the requested number of bytes. `seek()` accepts
`SEEK_SET`, `SEEK_CUR`, and `SEEK_END`, and returns `false` for a position
outside `[0, size]`.

## Existing PHP resources

The supplied resource must be seekable and remains owned by the caller:

```php
$handle = fopen('/documents/example.doc', 'rb');
$document = CompoundFile::fromResource($handle);
// $handle is still open when $document is destroyed.
```

## Native PHP stream wrapper

```php
use DK\CompoundFile\StreamWrapper;

StreamWrapper::register();
$url = StreamWrapper::url('/documents/example.xls', 'Workbook');
$handle = fopen($url, 'rb');

$firstKilobyte = fread($handle, 1024);
fseek($handle, 0);

// Compact syntax for simple paths and stream names:
$handle = fopen('ole2://document.doc#WordDocument', 'rb');

$rootEntries = scandir(StreamWrapper::directoryUrl('/documents/example.xls'));
$poolEntries = scandir(StreamWrapper::directoryUrl(
    '/documents/example.xls',
    'ObjectPool'
));
```

The wrapper is read-only. `register()` may be called repeatedly and accepts a
custom scheme name. Fragment syntax is convenient for simple names. Use
`url()` when paths or stream names contain `#`, `?`, spaces, control characters,
or non-ASCII characters so every component is encoded correctly.

## Public API

### `DK\CompoundFile\CompoundFile`

- `open(string $path): CompoundFile` — opens and parses a filesystem file.
- `fromResource(resource $resource): CompoundFile` — parses a seekable stream
  without taking ownership of it.
- `getMajorVersion(): int` — returns CFBF major version 3 or 4.
- `getHeader(): Header` — returns parsed, immutable header metadata.
- `getAllocationTable(): AllocationTable` — returns a diagnostic snapshot of
  DIFAT, FAT, and mini-FAT.
- `getEntries(): array` — returns all non-empty `DirectoryEntry` objects,
  including the root entry.
- `getEntryById(int $id): ?DirectoryEntry` — performs a raw SID lookup.
- `findEntry(string $path): ?DirectoryEntry` — finds a storage or stream.
- `hasStream(string $path): bool` — checks whether a path names a stream.
- `openStream(string|DirectoryEntry $entry): Stream` — creates a seekable
  logical stream.
- `getStreamContents(string|DirectoryEntry $entry): string` — reads a complete
  logical stream.

### `DK\CompoundFile\DirectoryEntry`

- `getId(): int` — zero-based directory identifier.
- `getName(): string` — UTF-8 name.
- `getPath(): string` — path relative to the root storage.
- `getType(): int` — one of `TYPE_STORAGE`, `TYPE_STREAM`, or `TYPE_ROOT`.
- `getSize(): int` — logical size in bytes.
- `isStream(): bool` and `isStorage(): bool` — convenient type checks.
- `getNameByteLength()`, `getColor()`, `getClassId()`, `getStateBits()`,
  `getCreationTime()`, and `getModifiedTime()` expose directory metadata.
- `getLeftSiblingId()`, `getRightSiblingId()`, and `getChildId()` expose raw
  tree identifiers; corresponding object navigation methods omit the `Id`
  suffix.

### `DK\CompoundFile\Header`

Provides byte order, major/minor version, sector shifts and sizes, transaction
signature, mini-stream cutoff, and declared FAT, mini-FAT, and DIFAT locations
and counts. `hasMiniFat()` and `hasDifatSectors()` are convenience checks.

### `DK\CompoundFile\AllocationTable`

`getDifat()`, `getFat()`, and `getMiniFat()` return copies of the parsed raw
sector-ID tables for diagnostics and format inspection.

### `DK\CompoundFile\Stream`

- `getSize(): int`, `tell(): int`, and `eof(): bool` — stream state.
- `read(int $length): string` — reads and advances.
- `getContents(): string` — reads all bytes without changing the position.
- `seek(int $offset, int $whence = SEEK_SET): bool` — changes position.

### `DK\CompoundFile\StreamWrapper`

- `register(string $scheme = 'ole2'): void` — registers the PHP wrapper.
- `url(string $file, string $stream, string $scheme = 'ole2'): string` — builds
  a safe wrapper URL.
- `directoryUrl(string $file, string $storage = '', string $scheme = 'ole2'):
  string` — builds a URL usable with `opendir()`, `readdir()`, `scandir()`,
  `is_dir()`, and `stat()`.

## Errors and validation

Malformed signatures, invalid sector references, truncated reads, cyclic
FAT/DIFAT/directory chains, invalid UTF-16 names, and unsupported versions
throw `DK\CompoundFile\Exception\CfbfException`. Invalid caller arguments throw standard
`InvalidArgumentException`. The PHP wrapper reports open failures through the
normal `fopen()` `false` result.

## Compatibility and testing

The source uses native PHP 8.1 type declarations. CI runs the test suite on
PHP 8.1, 8.2, 8.3, 8.4, and current PHP:

```bash
composer install
composer check
```

`composer check` validates Composer metadata, checks PHP syntax and PSR-12
formatting, runs PHPStan at level 8, and executes PHPUnit. To apply formatting
automatically, run `composer format`.

Unit and integration tests cover regular FAT streams, mini-FAT streams,
seeking, UTF-16 names, equivalent little/big-endian input, invalid and
truncated files, cyclic chains, missing streams, wrapper directories, and the
native PHP stream wrapper.

The suite also includes a Word 97 `.doc` produced independently by LibreOffice
from this README through its native CommonMark filter. Regenerate it with
`composer fixtures`. The script uses `soffice` from `PATH`; set
`SOFFICE=/custom/path/soffice` to use another binary.

## License

MIT. See [LICENSE](LICENSE).
