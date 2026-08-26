#!/usr/bin/env sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
fixture_dir="$project_dir/tests/fixtures"

if [ -n "${SOFFICE:-}" ]; then
    soffice_binary=$SOFFICE
elif command -v soffice >/dev/null 2>&1; then
    soffice_binary=$(command -v soffice)
else
    echo 'Cannot find soffice. Install LibreOffice or set SOFFICE explicitly.' >&2
    exit 1
fi

"$soffice_binary" --headless \
    --infilter='Markdown' \
    --convert-to 'doc:MS Word 97' \
    --outdir "$fixture_dir" \
    "$project_dir/README.md"
