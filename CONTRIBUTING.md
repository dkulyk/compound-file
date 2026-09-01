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
