<?php

declare(strict_types=1);

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;

require dirname(__DIR__).'/vendor/autoload.php';

// Every measured operation gets a fresh process; fixture creation is separate.
function child(array $arguments): array
{
    $environment = getenv();
    $environment['XDEBUG_MODE'] = 'off';
    $process = proc_open(
        [PHP_BINARY, __FILE__, ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start benchmark worker.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Benchmark worker failed: '.$error);
    }
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

function worker(string $operation, string $scenario, string $path, int $count): array
{
    if (extension_loaded('xdebug') && version_compare(phpversion('xdebug'), '3.1', '<')) {
        throw new RuntimeException('Benchmark workers require Xdebug 3.1+ or no Xdebug extension.');
    }
    // The mode category returns an array without output (Xdebug 3.1+).
    // https://xdebug.org/docs/all_functions#xdebug_info
    if (function_exists('xdebug_info') && xdebug_info('mode') !== []) {
        throw new RuntimeException('Benchmark workers require XDEBUG_MODE=off.');
    }
    if ($operation === 'generate') {
        $writer = CompoundFileWriter::create();
        $source = null;
        try {
            if ($scenario === 'large-stream') {
                $source = tmpfile();
                if ($source === false || !ftruncate($source, $count)) {
                    throw new RuntimeException('Cannot create large-stream source.');
                }
                $writer->setStreamResource('Payload', $source);
            } else {
                for ($i = 0; $i < $count; $i++) {
                    if ($scenario === 'directories') {
                        $writer->createStorage('D'.$i);
                        $writer->setStreamContents('D'.$i.'/Data', 'x');
                    } else {
                        $writer->setStreamContents('S'.$i, str_repeat('m', 3000));
                    }
                }
            }
            $writer->save($path);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
        return ['file_bytes' => filesize($path)];
    }

    $started = hrtime(true);
    $file = CompoundFile::open($path);
    $openMs = (hrtime(true) - $started) / 1e6;
    $started = hrtime(true);
    $bytes = 0;
    try {
        if ($operation === 'read') {
            if ($scenario === 'directories') {
                for ($i = 0; $i < $count; $i++) {
                    if (count($file->getChildren('D'.$i)) !== 1) {
                        throw new RuntimeException('Unexpected directory child count.');
                    }
                }
            } elseif ($scenario === 'mini-streams') {
                $expected = str_repeat('m', 3000);
                for ($i = 0; $i < $count; $i++) {
                    $data = $file->getStreamContents('S'.$i);
                    if ($data !== $expected) {
                        throw new RuntimeException('Incorrect mini-stream contents.');
                    }
                    $bytes += strlen($data);
                }
            } else {
                $stream = $file->openStream('Payload');
                while (!$stream->eof()) {
                    $data = $stream->read(1048576);
                    if ($data === '' || strspn($data, "\0") !== strlen($data)) {
                        throw new RuntimeException('Incorrect large-stream contents.');
                    }
                    $bytes += strlen($data);
                }
                if ($bytes !== $count) {
                    throw new RuntimeException('Incorrect large-stream byte count.');
                }
            }
        } elseif ($operation === 'rewrite') {
            $output = tmpfile();
            if ($output === false) {
                throw new RuntimeException('Cannot create rewrite output.');
            }
            try {
                CompoundFileWriter::fromCompoundFile($file)->saveToResource($output);
                $bytes = ftell($output);
            } finally {
                fclose($output);
            }
        } elseif ($operation !== 'open') {
            throw new RuntimeException('Unknown operation.');
        }
        $operationMs = (hrtime(true) - $started) / 1e6;
        return [
            'open_ms' => $openMs,
            'operation_ms' => $operation === 'open' ? $openMs : $operationMs,
            'bytes' => $bytes,
            'peak_bytes' => memory_get_peak_usage(true),
        ];
    } finally {
        $file->close();
    }
}

try {
    if (($argv[1] ?? '') === '--worker') {
        echo json_encode(worker($argv[2], $argv[3], $argv[4], (int) $argv[5]), JSON_THROW_ON_ERROR);
        exit(0);
    }
    $arguments = array_slice($argv, 1);
    if (array_diff($arguments, ['--json', '--quick']) !== []) {
        throw new InvalidArgumentException('Usage: php tools/benchmark-scenarios.php [--json] [--quick]');
    }
    $quick = in_array('--quick', $arguments, true);
    $scenarios = [
        'directories' => $quick ? 100 : 2000,
        'mini-streams' => $quick ? 100 : 10000,
        'large-stream' => ($quick ? 4 : 64) * 1048576,
    ];
    $results = [];
    foreach ($scenarios as $scenario => $count) {
        $path = tempnam(sys_get_temp_dir(), 'ole-benchmark-');
        if ($path === false) {
            throw new RuntimeException('Cannot create benchmark fixture.');
        }
        try {
            $fixture = child(['--worker', 'generate', $scenario, $path, (string) $count]);
            foreach (['open', 'read', 'rewrite'] as $operation) {
                $samples = [];
                for ($i = 0; $i < 3; $i++) {
                    $samples[] = child(['--worker', $operation, $scenario, $path, (string) $count]);
                }
                $times = array_column($samples, 'operation_ms');
                sort($times);
                $results[] = [
                    'scenario' => $scenario,
                    'count' => $count,
                    'file_bytes' => $fixture['file_bytes'],
                    'operation' => $operation,
                    'median_ms' => $times[1],
                    'max_peak_bytes' => max(array_column($samples, 'peak_bytes')),
                    'samples' => $samples,
                ];
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
    if (in_array('--json', $arguments, true)) {
        echo json_encode([
            'php' => PHP_VERSION,
            'platform' => PHP_OS_FAMILY,
            'quick' => $quick,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    } else {
        printf("PHP %s / %s; fresh workers, Xdebug off; median of 3 runs\n", PHP_VERSION, PHP_OS_FAMILY);
        foreach ($results as $result) {
            printf(
                "%s (%d) / %s: %.3f ms; peak %.2f MiB\n",
                $result['scenario'],
                $result['count'],
                $result['operation'],
                $result['median_ms'],
                $result['max_peak_bytes'] / 1048576,
            );
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
