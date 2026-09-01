# Security policy

## Supported versions

Security fixes are provided for the latest stable release. Upgrade to the most
recent version before reporting an issue or requesting a backport.

## Reporting a vulnerability

Do not disclose a suspected vulnerability in a public issue. Use GitHub's
**Security** tab and select **Report a vulnerability** to open a private
security advisory:

https://github.com/dkulyk/compound-file/security/advisories/new

Include a minimal reproducer or sample file, affected versions, expected and
actual behavior, and any known impact. Remove confidential document contents
whenever possible.

You should receive an initial response within seven days. Confirmed issues will
be assessed, fixed on a private branch, and released before coordinated public
disclosure.

## Scope

The package parses untrusted binary containers, so crashes, unbounded resource
consumption, out-of-bounds reads, and incorrect stream boundaries are security
relevant. Encryption and format-specific interpretation of stream contents are
outside this package's scope.
