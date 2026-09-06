# Contributing

Bug reports, compatibility fixtures, performance measurements, documentation,
and pull requests are welcome.

## Before opening an issue

- Use the latest stable release.
- Reduce the problem to the smallest reproducible example when possible.
- For malformed or incompatible files, include the producing application and
  its version. Attach the file only when it contains no private information.
- For performance reports, include the PHP version, operating system, file size,
  stream size, and the exact benchmark command.

Report security issues privately as described in [SECURITY.md](SECURITY.md).

## Development setup

```bash
composer install
composer check
```

`composer check` validates Composer metadata, PHP syntax, formatting, PHPStan,
and PHPUnit. Apply formatting with `composer format`.

Run the reader benchmark against the bundled fixture or a representative local
file:

```bash
composer benchmark
composer benchmark -- /path/to/document.xls
```

Run the synthetic time and memory scenarios with:

```bash
composer benchmark:scenarios
composer benchmark:scenarios -- --json
composer benchmark:scenarios -- --quick
```

The default suite generates 2,000 storages with one stream each, 10,000
3,000-byte mini-streams, and one 64 MiB resource-backed stream. Each scenario
measures opening, reading (enumerating children for the directory scenario),
and rewriting. Each operation runs three times in fresh PHP processes with
`XDEBUG_MODE=off`; fixture generation runs separately and temporary files are
removed afterward. `--quick` uses 100 storages, 100 mini-streams, and 4 MiB.

Reports include median elapsed time and maximum PHP allocator peak memory,
plus all individual samples in JSON. Peaks include PHP startup, autoloading,
and opening the input, but exclude fixture generation; they are not OS RSS.
Read/rewrite timing excludes opening the input (reported separately in each
sample), but includes content validation for reads and output setup/flush/close
for rewrites. Large streams are read in 1 MiB chunks. Rewrite output uses a
temporary disk file. Filesystem caches are not cleared, so results do not
represent cold-disk performance. Compare runs on the same PHP build and machine;
there are no timing thresholds in unit tests. The scheduled benchmark workflow
uploads `benchmark-scenarios.json` alongside the real-file corpus results.

Run the optional LibreOffice writer interoperability test with:

```bash
SOFFICE=/path/to/soffice vendor/bin/phpunit tests/WriterInteropTest.php
```

## Pull requests

Keep each pull request focused and include tests for behavior changes. Preserve
reader performance: changes to parsing, allocation chains, stream reads, or I/O
must include before/after benchmark results on a representative compound file.

Use a descriptive branch prefix:

- `feature/` for new behavior
- `fix/` for correctness fixes
- `perf/` for performance work
- `test/` for test-only changes
- `docs/` for documentation
- `ci/` for automation and tooling
- `refactor/` for internal changes without an API change

Pull requests run the full test matrix on PHP 8.1 through 8.5, lowest supported
dependencies, and Windows. Merge only after every required check passes.
