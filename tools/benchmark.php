<?php

declare(strict_types=1);

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\DirectoryEntry;

require dirname(__DIR__).'/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$json = in_array('--json', $arguments, true);
$paths = array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--json'));
if ($paths === []) {
    $paths[] = dirname(__DIR__).'/tests/fixtures/README.doc';
}

$results = [];
foreach ($paths as $path) {
    try {
        $results[] = benchmark($path);
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("%s: %s\n", $path, $exception->getMessage()));
        exit(1);
    }
}

if ($json) {
    echo json_encode(
        ['php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY, 'results' => $results],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n";
    exit(0);
}

printf("PHP %s on %s\n", PHP_VERSION, PHP_OS_FAMILY);
foreach ($results as $result) {
    printf(
        "\n%s (%d bytes)\nLargest stream: %s (%d bytes)\n",
        $result['path'],
        $result['file_bytes'],
        $result['stream'],
        $result['stream_bytes'],
    );
    printf("Open median: %.3f ms\n", $result['open_median_ms']);
    printf(
        "First complete extraction: %.3f ms, %.0f MiB/s\n",
        $result['first_extraction_ms'],
        $result['first_extraction_mib_s'],
    );
    printf(
        "%d random reads: %.3f s, %.0f reads/s\n",
        $result['random_reads'],
        $result['random_read_seconds'],
        $result['random_reads_s'],
    );
    printf(
        "%d warm extractions: %.3f s, %.0f MiB/s\n",
        $result['warm_extractions'],
        $result['warm_extraction_seconds'],
        $result['warm_extraction_mib_s'],
    );
}

/**
 * @return array{
 *     path: string,
 *     file_bytes: int,
 *     stream: string,
 *     stream_bytes: int,
 *     open_median_ms: float,
 *     first_extraction_ms: float,
 *     first_extraction_mib_s: float,
 *     random_reads: int,
 *     random_read_seconds: float,
 *     random_reads_s: float,
 *     warm_extractions: int,
 *     warm_extraction_seconds: float,
 *     warm_extraction_mib_s: float
 * }
 */
function benchmark(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Benchmark file does not exist.');
    }

    $warmup = CompoundFile::open($path);
    $warmup->close();

    $openSamples = [];
    for ($iteration = 0; $iteration < 25; $iteration++) {
        $started = hrtime(true);
        $sample = CompoundFile::open($path);
        $openSamples[] = elapsedSeconds($started);
        $sample->close();
    }
    sort($openSamples);
    $openMedian = $openSamples[intdiv(count($openSamples), 2)];

    $file = CompoundFile::open($path);
    $streams = array_values(array_filter(
        $file->getEntries(),
        static fn (DirectoryEntry $entry): bool => $entry->isStream(),
    ));
    usort(
        $streams,
        static fn (DirectoryEntry $left, DirectoryEntry $right): int => $right->getSize() <=> $left->getSize(),
    );
    $entry = $streams[0] ?? null;
    if ($entry === null || $entry->getSize() === 0) {
        throw new RuntimeException('The compound file does not contain a non-empty stream.');
    }

    $stream = $file->openStream($entry);
    $started = hrtime(true);
    $contents = $stream->getContents();
    $firstExtractionSeconds = elapsedSeconds($started);
    if (strlen($contents) !== $entry->getSize()) {
        throw new RuntimeException('The complete extraction returned an unexpected number of bytes.');
    }

    $randomReads = 10_000;
    $readSize = min(64, $entry->getSize());
    $maximumOffset = $entry->getSize() - $readSize;
    $started = hrtime(true);
    for ($iteration = 0; $iteration < $randomReads; $iteration++) {
        $offset = $maximumOffset === 0 ? 0 : ($iteration * 104_729) % ($maximumOffset + 1);
        $stream->seek($offset);
        $stream->read($readSize);
    }
    $randomReadSeconds = elapsedSeconds($started);

    $warmExtractions = max(10, min(1000, intdiv(64 * 1024 * 1024, $entry->getSize())));
    $started = hrtime(true);
    for ($iteration = 0; $iteration < $warmExtractions; $iteration++) {
        $stream->getContents();
    }
    $warmExtractionSeconds = elapsedSeconds($started);
    $warmMegabytes = $warmExtractions * $entry->getSize() / 1024 / 1024;
    $file->close();

    $fileSize = filesize($path);
    if ($fileSize === false) {
        throw new RuntimeException('Cannot determine benchmark file size.');
    }

    return [
        'path' => $path,
        'file_bytes' => $fileSize,
        'stream' => $entry->getPath(),
        'stream_bytes' => $entry->getSize(),
        'open_median_ms' => $openMedian * 1000,
        'first_extraction_ms' => $firstExtractionSeconds * 1000,
        'first_extraction_mib_s' => throughput($entry->getSize(), $firstExtractionSeconds),
        'random_reads' => $randomReads,
        'random_read_seconds' => $randomReadSeconds,
        'random_reads_s' => $randomReads / $randomReadSeconds,
        'warm_extractions' => $warmExtractions,
        'warm_extraction_seconds' => $warmExtractionSeconds,
        'warm_extraction_mib_s' => $warmMegabytes / $warmExtractionSeconds,
    ];
}

function elapsedSeconds(int $started): float
{
    return max((hrtime(true) - $started) / 1_000_000_000, PHP_FLOAT_EPSILON);
}

function throughput(int $bytes, float $seconds): float
{
    return $bytes / 1024 / 1024 / $seconds;
}
