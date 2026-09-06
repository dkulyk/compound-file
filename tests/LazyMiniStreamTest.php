<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use PHPUnit\Framework\TestCase;

final class LazyMiniStreamTest extends TestCase
{
    public function testReadsAcrossBlocksAndAfterCacheEviction(): void
    {
        foreach ([3, 4] as $version) {
            $writer = CompoundFileWriter::create($version);
            // More than 1 MiB of mini-stream data, with streams crossing 64 KiB blocks.
            for ($i = 0; $i < 400; $i++) {
                $writer->setStreamContents(sprintf('S%03d', $i), str_repeat(chr($i % 251), 3000));
            }
            $resource = tmpfile();
            $writer->saveToResource($resource);
            if (!in_array('ole-test-read-counter', stream_get_filters(), true)) {
                stream_filter_register('ole-test-read-counter', MiniStreamReadCounter::class);
            }
            MiniStreamReadCounter::$bytes = 0;
            $filter = stream_filter_append($resource, 'ole-test-read-counter', STREAM_FILTER_READ);
            $file = CompoundFile::fromResource($resource);
            try {
                self::assertLessThan(300000, MiniStreamReadCounter::$bytes, 'Opening must not read the 1.2 MiB mini-stream payload.');
                foreach ([range(0, 399), range(399, 0)] as $order) {
                    foreach ($order as $i) {
                        $stream = $file->openStream(sprintf('S%03d', $i));
                        self::assertSame(str_repeat(chr($i % 251), 3000), $stream->getContents());
                        self::assertTrue($stream->seek(61));
                        self::assertSame(str_repeat(chr($i % 251), 1000), $stream->read(1000));
                    }
                    if ($order[0] === 0) {
                        $before = MiniStreamReadCounter::$bytes;
                        self::assertSame(str_repeat("\0", 3000), $file->getStreamContents('S000'));
                        self::assertGreaterThan($before, MiniStreamReadCounter::$bytes, 'Old blocks must be evicted and read again.');
                    }
                }
                // Reading still relies on the caller-owned resource remaining open.
                self::assertIsResource($resource);
            } finally {
                $file->close();
                stream_filter_remove($filter);
                fclose($resource);
            }
        }
    }

    public function testReadsFragmentedRootChain(): void
    {
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Data', str_repeat('abc', 1000));
        $resource = tmpfile();
        $writer->saveToResource($resource);
        $original = CompoundFile::fromResource($resource);
        $root = $original->findEntry('');
        $start = $root->getStartSector();
        $fatSector = $original->getAllocationTable()->getDifat()[0];
        $original->close();
        // Swap the first two physical root sectors and update FAT/directory links.
        fseek($resource, ($start + 1) * 512);
        $first = fread($resource, 512);
        $second = fread($resource, 512);
        fseek($resource, ($start + 1) * 512);
        fwrite($resource, $second.$first);
        fseek($resource, ($fatSector + 1) * 512 + $start * 4);
        fwrite($resource, pack('V2', $start + 2, $start));
        fseek($resource, 512 + 116);
        fwrite($resource, pack('V', $start + 1));
        $file = CompoundFile::fromResource($resource);
        try {
            self::assertSame(str_repeat('abc', 1000), $file->getStreamContents('Data'));
        } finally {
            $file->close();
            fclose($resource);
        }
    }
}

/** Counts underlying input bytes without inspecting the parser's private cache. */
final class MiniStreamReadCounter extends \php_user_filter
{
    public static int $bytes = 0;

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$bytes += $bucket->datalen;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
