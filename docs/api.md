# API reference

## `CompoundFile`

| Method | Description |
| --- | --- |
| `open(string $path): self` | Open a filesystem file. |
| `fromResource(resource $resource): self` | Parse an existing seekable resource. |
| `close(): void` | Release the parser handle without closing caller-owned resources. |
| `release(): void` | Close the parser when its last open stream is destroyed. |
| `getMajorVersion(): int` | Return the CFBF major version (`3` or `4`). |
| `getHeader(): Header` | Return immutable header metadata. |
| `getAllocationTable(): AllocationTable` | Return allocation-table snapshots. |
| `getEntries(): array` | Return entries reachable from the root directory tree, including the root. |
| `getChildren(string $storage = ''): array` | Return direct children of a storage. |
| `getEntryById(int $id): ?DirectoryEntry` | Find an entry by raw SID. |
| `findEntry(string $path): ?DirectoryEntry` | Find an entry by path. |
| `hasStream(string $path): bool` | Check whether a stream exists. |
| `openStream(string\|DirectoryEntry $entry): Stream` | Open a seekable logical stream. |
| `getStreamContents(string\|DirectoryEntry $entry): string` | Read a complete stream. |

## `DirectoryEntry`

Provides identity, name, path, type, size, CLSID, state bits, timestamps,
exact `getCreationFileTimeTicks()` and `getModifiedFileTimeTicks()` values,
red/black tree metadata, and raw or object navigation for sibling and child
entries.

## `Stream`

Provides `getSize()`, `tell()`, `eof()`, `read()`, `getContents()`, and
`seek()`.

## `StreamWrapper`

Provides `register()`, `url()`, and `directoryUrl()`.

## `CompoundFileWriter`

| Method | Description |
| --- | --- |
| `create(int $version = 3): self` | Create an empty writer model. |
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
| `setTimestamps(string $path, ?DateTimeInterface $created, ?DateTimeInterface $modified): self` | Set FILETIME metadata. |
| `save(string $path): void` | Atomically save to a filesystem path. |
| `saveToResource(resource $resource): void` | Save to an open seekable resource. |
| `close(): void` | Release the source opened by `open()` or parsed by `fromResource()`. |

## `FileTime`

| Method | Description |
| --- | --- |
| `decode(string $bytes): ?DateTimeImmutable` | Decode eight little-endian FILETIME bytes. |
| `encode(?DateTimeInterface $time): string` | Encode eight little-endian FILETIME bytes. |
| `ticks(int $low, int $high): ?int` | Combine the unsigned 32-bit halves of a FILETIME into a tick count. |
| `fromTicks(?int $ticks): ?DateTimeImmutable` | Convert a tick count to UTC. |
| `toTicks(DateTimeInterface $time): int` | Convert a date to a tick count. |

## Error handling

Malformed or unsupported files throw
`DK\CompoundFile\Exception\CfbfException`. This includes:

- an invalid signature or a byte order other than little-endian;
- truncated data and out-of-range sector references;
- invalid directory trees and cyclic allocation or directory chains;
- entry names that are empty, not null-terminated, not valid UTF-16, or that
  contain a null or reserved character;
- reading from a parser that has been closed.

The parser is strict: it rejects such a file instead of repairing it.

Invalid caller arguments throw `InvalidArgumentException`. Wrapper open
failures follow PHP conventions and return `false` from `fopen()`.

[Back to the README](../README.md)
