<?php

declare(strict_types=1);

use DK\CompoundFile\CompoundFile;

require dirname(__DIR__).'/vendor/autoload.php';

$path = $argv[1] ?? dirname(__DIR__).'/tests/fixtures/README.doc';
$file = CompoundFile::open($path);
$streams = array_values(array_filter($file->getEntries(), static fn ($entry): bool => $entry->isStream()));
usort($streams, static fn ($left, $right): int => $right->getSize() <=> $left->getSize());
$entry = $streams[0] ?? null;

if ($entry === null || $entry->getSize() === 0) {
    fwrite(STDERR, "The compound file does not contain a non-empty stream.\n");
    exit(1);
}

$stream = $file->openStream($entry);
$iterations = 10_000;
$readSize = min(64, $entry->getSize());
$maximumOffset = $entry->getSize() - $readSize;
$started = hrtime(true);

for ($i = 0; $i < $iterations; $i++) {
    $offset = $maximumOffset === 0 ? 0 : ($i * 104_729) % ($maximumOffset + 1);
    $stream->seek($offset);
    $stream->read($readSize);
}

$seconds = (hrtime(true) - $started) / 1_000_000_000;
printf(
    "%d random reads from %s (%d bytes): %.3f s, %.0f reads/s\n",
    $iterations,
    $entry->getPath(),
    $entry->getSize(),
    $seconds,
    $iterations / $seconds,
);
