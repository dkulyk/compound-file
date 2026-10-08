# Reading streams

## Complete stream

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

## Incremental reading

```php
$stream = $file->openStream('WordDocument');

$header = $stream->read(32);
$stream->seek(-16, SEEK_END);
$trailer = $stream->read(16);
```

`Stream::seek()` supports `SEEK_SET`, `SEEK_CUR`, and `SEEK_END`. It returns
`false` when the requested position is outside the logical stream.

## Memory use

Opening a file loads the allocation tables and directory metadata and
validates the root sector chain. Stream contents are read only on request.

Two caches are bounded per parser:

- Streams below 4096 bytes share a mini-stream cache, filled on demand in
  64 KiB blocks and limited to 1 MiB.
- Resolved sector chains are cached up to 1,024 chains and 32,768 sectors. The
  chain being read is exempt, so one very long chain can exceed the limit
  while it is in use.

Neither limit bounds total parser memory: the allocation tables themselves
scale with the file size.

## Existing PHP resource

```php
$handle = fopen('document.doc', 'rb');
$file = CompoundFile::fromResource($handle);
```

The resource must be seekable. Ownership remains with the caller, so the
library does not close it.

## Closing a file

Directory entries reference the parser, so an abandoned parser is reclaimed
only by PHP's cycle collector. In a long-running process, close it yourself.
There are two ways:

| Method | When the file closes | Streams already open |
| --- | --- | --- |
| `close()` | At once | Stop working |
| `release()` | When the last open stream is destroyed, or at once if there is none | Stay readable |

Closing drops the caches. Neither method closes a resource supplied by the
caller.

Use `close()` when you are done with the file, and always before replacing or
deleting it on Windows:

```php
$file = CompoundFile::open('document.doc');
try {
    $contents = $file->getStreamContents('WordDocument');
} finally {
    $file->close();
}
```

Use `release()` to hand a stream to a caller that never sees the parser:

```php
use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\Stream;

function wordDocument(string $path): Stream
{
    $file = CompoundFile::open($path);
    $stream = $file->openStream('WordDocument');
    $file->release();

    return $stream;
}
```

Only `Stream` objects keep a released parser open. A writer made by
`CompoundFileWriter::fromCompoundFile()` does not, so release the parser after
saving.

## Inspecting the container

### Header

```php
$header = $file->getHeader();

echo $header->getMajorVersion();
echo $header->getSectorSize();
echo $header->getDirectorySectorCount();
```

`Header` exposes the CFBF version, sector shifts and sizes,
transaction signature, mini-stream cutoff, and declared FAT, mini-FAT, and
DIFAT locations and counts. Version 4 headers also expose the declared
directory-sector count.

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
state bits, stream size, and creation/modification timestamps. The raw
100-nanosecond FILETIME tick values are available when exact round trips are
required.

### Allocation tables

```php
$tables = $file->getAllocationTable();

$difat = $tables->getDifat();
$fat = $tables->getFat();
$miniFat = $tables->getMiniFat();
```

The returned arrays are diagnostic snapshots and cannot mutate parser state.

[Back to the README](../README.md)
