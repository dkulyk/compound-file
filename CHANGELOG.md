# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.7] - 2026-09-09

### Fixed

- Close the owned file handle immediately when parsing fails. Directory entries
  form a reference cycle back to the parser, so the handle previously stayed
  open until cyclic garbage collection ran. Caller-owned resources passed to
  `fromResource()` are still left open.
- Reject a `DirectoryEntry` that belongs to a different parser. Passing a
  foreign entry to `openStream()` or `getStreamContents()` previously applied
  its sector metadata to the receiving file and silently returned bytes from
  the wrong container. Misuse now raises `InvalidArgumentException`.
- Reject directory entry names containing the reserved characters `/`, `\`,
  `:` and `!`. Such names fabricated a hierarchy that no directory entry
  described, which raised a confusing writer error on import or, for a name
  whose fabricated parent was a stream, silently dropped the entry. Files the
  reader previously accepted are now rejected.

## [0.2.6] - 2026-09-07

### Added

- Synthetic time and memory benchmark scenarios covering many storages, many
  mini-streams, and one large stream. Each measured operation runs in a fresh
  process with Xdebug disabled, and re-reading is measured separately from the
  first read.

### Changed

- Read the mini-stream payload on demand in 64 KiB blocks, capped at 1 MiB per
  parser, instead of loading it in full when opening a container.
- Bound the resolved sector-chain caches to 1,024 chains and 32,768 sectors,
  evicting the least recently inserted chains first. Reading 8,000 mini-streams
  from one parser now retains 4 MiB instead of 35 MiB.
- Drop the chain and mini-stream caches in `CompoundFile::close()`, so the
  retained memory is released without waiting for cycle collection.
- Index storage children while parsing, so `getChildren()` no longer scans
  every directory entry.

### Fixed

- Release the compound file handle as soon as a wrapper stream is closed, and
  when a stat call or directory listing finishes.
- Return `false` from the stream wrapper `url_stat()` for missing entries, so
  `file_exists()` no longer reports absent streams and storages as present.
- Decode FILETIME values outside the PHP integer range as unset instead of
  rejecting the whole container with a misleading stream-size error.
- Index the directory tree with an explicit stack, so long degenerate sibling
  lists cannot exhaust the call stack or trip Xdebug nesting limits.

## [0.2.5] - 2026-09-03

### Added

- Expose the version 4 directory-sector count through `Header`.
- Expose raw 100-nanosecond FILETIME values on directory entries and preserve
  them exactly when rewriting imported containers.
- Include full writer rewrite time and throughput in the benchmark output.

### Changed

- Use consistent Unicode-aware path lookup in the reader and writer.
- Copy imported regular streams in 1 MiB blocks instead of one sector at a
  time, substantially improving full-file rewrite throughput.

### Fixed

- Tolerate a root mini-stream size larger than its available FAT chain while
  retaining strict bounds checks for individual mini-stream reads.
- Use the same CFBF uppercase folding for path registries and directory-tree
  ordering, so equivalent names such as `Straße` and `STRASSE` cannot diverge.

## [0.2.4] - 2026-09-03

### Security

- Bound declared FAT data by the input file size and reject duplicate FAT
  sector references, preventing disproportionate memory allocation from a
  small malicious container.

### Fixed

- Reject regular FAT chains that end before the requested stream range.
- Exclude unreachable directory entries so rewriting cannot resurrect orphaned
  or shadowing streams.
- Apply `0666 & ~umask()` permissions when saving a new filesystem file.
- Reject version 3 streams larger than the 2 GiB specification limit before
  allocating tables or copying stream bytes.

## [0.2.3] - 2026-09-02

### Added

- Deterministic mutation coverage for bit flips, integer overwrites, and file
  truncation across regular, mini-FAT, and big-endian containers.
- Repeated writer round-trip coverage for versions 3 and 4 in both byte orders.
- Reproducible real-world benchmark corpus pinned to checksum-verified
  LibreOffice DOC, XLS, and PPT fixtures.

### Changed

- Report median parser-open time, first extraction, random-read throughput, and
  warm extraction throughput for multiple files in text or JSON format.

## [0.2.2] - 2026-09-02

### Fixed

- Read fragmented mini-stream chains without crossing physical mini-sector
  boundaries.
- Reject mini-sector references outside the root mini-stream.

### Changed

- Validate CFBF version/sector-size pairs, mini-sector size, mini-stream cutoff,
  directory entry types and colors, duplicate roots, and duplicate paths.
- Add scheduled LibreOffice interoperability and reader benchmark workflows.
- Avoid duplicate CI runs for pushes to pull-request branches.

## [0.2.1] - 2026-08-26

### Added

- Explicit `CompoundFile::close()` lifecycle API.
- PHP 8.1 through 8.5, lowest-dependency, and Windows CI coverage.

### Fixed

- Durable atomic saves with flushing and synchronization.
- Repeated replacement of an imported source file on Windows.
- Preservation of existing POSIX file permissions during replacement.

## [0.2.0] - 2026-08-26

### Added

- Creation and full-file rewriting of CFBF version 3 and version 4 files.
- Storage and stream creation, replacement, removal, metadata editing, lazy
  copying, resource-backed streams, and atomic filesystem saves.
- Writer interoperability and round-trip tests.

## [0.1.6] - 2026-08-26

### Fixed

- Removed quadratic chain-cache copying for large streams.

## [0.1.5] - 2026-08-26

### Changed

- Reduced directory parsing overhead.

## [0.1.4] - 2026-08-26

### Changed

- Reduced DIFAT decoding overhead.

## [0.1.3] - 2026-08-26

### Changed

- Decode FAT and mini-FAT arrays with bulk `unpack()` operations.

## [0.1.2] - 2026-08-26

### Changed

- Coalesce contiguous sector reads and reuse the current input position.

## [0.1.1] - 2026-08-26

### Added

- Seekable random-access stream reading and reader benchmarks.

## [0.1.0] - 2026-08-26

### Added

- Initial CFBF reader with FAT, DIFAT, mini-FAT, endian-aware parsing, Unicode
  names, resource input, and the read-only `ole2://` stream wrapper.

[Unreleased]: https://github.com/dkulyk/compound-file/compare/v0.2.7...HEAD
[0.2.7]: https://github.com/dkulyk/compound-file/compare/v0.2.6...v0.2.7
[0.2.6]: https://github.com/dkulyk/compound-file/compare/v0.2.5...v0.2.6
[0.2.5]: https://github.com/dkulyk/compound-file/compare/v0.2.4...v0.2.5
[0.2.4]: https://github.com/dkulyk/compound-file/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/dkulyk/compound-file/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/dkulyk/compound-file/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/dkulyk/compound-file/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/dkulyk/compound-file/compare/v0.1.6...v0.2.0
[0.1.6]: https://github.com/dkulyk/compound-file/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/dkulyk/compound-file/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/dkulyk/compound-file/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/dkulyk/compound-file/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/dkulyk/compound-file/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/dkulyk/compound-file/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/dkulyk/compound-file/releases/tag/v0.1.0
