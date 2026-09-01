#!/bin/sh

set -eu

target=${1:-"${TMPDIR:-/tmp}/compound-file-benchmark-corpus"}
revision=f86a6409c71697097bccf5d7fb98933e5121d68e
base="https://raw.githubusercontent.com/LibreOffice/core/$revision"

mkdir -p "$target"

download() {
    path=$1
    name=$2
    checksum=$3
    curl --fail --location --silent --show-error "$base/$path" --output "$target/$name"
    printf '%s  %s\n' "$checksum" "$target/$name" | shasum -a 256 --check
}

download \
    sc/qa/extras/testdocuments/vba_endFunction.xls \
    vba_endFunction.xls \
    4503ce6b858870de644dd8fdb48fb6fc586c0cc58daa6b80cd7f5ccc8b5b2db7
download \
    sc/qa/unit/data/xls/opencl/logical/not.xls \
    not.xls \
    a13254897c10702fb2b8e9095227a3949132fa5e859fdb85979c2c48563eb055
download \
    sc/qa/unit/data/xls/forum-mso-en4-243595.xls \
    forum-mso-en4-243595.xls \
    30a5cbb77bb2a7472f25866f91dbeccd46a76e566ec9419c26d8bf4410536a77
download \
    sd/qa/unit/data/ppt/tdf169705.ppt \
    tdf169705.ppt \
    262df470f0a25ef8439a0e9cedbb73deacce97ff65f45752423c2ca2e2313dc7

printf '%s\n' "$target"
