# Writing compound files

## Creating a file

`CompoundFileWriter::create()` creates a version 3 file with 512-byte sectors
by default:

```php
use DK\CompoundFile\CompoundFileWriter;

$writer = CompoundFileWriter::create();
$writer->setStreamContents('Data', $contents);
$writer->save('container.ole');

// Version 4 uses 4096-byte sectors.
$version4 = CompoundFileWriter::create(4);
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

## Modifying an existing file

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

A writer made by `open()` keeps the source file open so it can copy streams
when saving. The handle is released when the writer is destroyed; call
`close()` to release it earlier, for example before replacing or deleting the
source file:

```php
$writer = CompoundFileWriter::open('template.doc');
try {
    $writer->setStreamContents('WordDocument', $wordDocument);
    $writer->save('result.doc');
} finally {
    $writer->close();
}
```

`close()` never closes a resource or parser supplied by the caller. After it,
saving fails for streams that still come from the source.

Saving to the original path is supported. Filesystem saves are flushed and
synchronized to storage, written to a temporary file in the destination
directory, and then replaced atomically. Existing POSIX permissions are
preserved.

## Resource-backed streams and output

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

## Entry metadata

```php
$writer->setClassId('ObjectPool/Object 1', '00020906-0000-0000-c000-000000000046');
$writer->setStateBits('ObjectPool/Object 1', 0x00000001);
$writer->setTimestamps(
    'ObjectPool/Object 1',
    new DateTimeImmutable('2025-01-01 00:00:00 UTC'),
    new DateTimeImmutable('2025-01-02 00:00:00 UTC'),
);
```

Compound files store timestamps as Windows FILETIME, which covers 1601-01-01
to 30828-09-14 UTC in 100-nanosecond steps. A date outside that range throws
`CfbfException`. Property set streams such
as `\x05SummaryInformation` use the same encoding for their `VT_FILETIME`
values, so `FileTime` is public for code that parses those payloads:

```php
use DK\CompoundFile\FileTime;

$created = FileTime::decode(substr($summaryInformation, $offset, 8));
$bytes = FileTime::encode(new DateTimeImmutable('2025-01-01 00:00:00 UTC'));
```

The writer rebuilds FAT, DIFAT, mini-FAT, mini-stream, directory sectors, and
red-black directory trees on every save. Stream sizes below 4096 bytes use the
mini-stream; streams at or above that boundary use the regular FAT.

[Back to the README](../README.md)
