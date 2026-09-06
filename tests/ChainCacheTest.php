<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use PHPUnit\Framework\TestCase;

final class ChainCacheTest extends TestCase
{
    // Unbounded caches hold about 35 MiB at this stream count; bounded ones stay at 4 MiB.
    private const MINI_STREAMS = 8000;
    private const CACHE_LIMIT_BYTES = 16_777_216;
    private const REGULAR_STREAMS = 1200;

    public function testMiniChainCacheStaysBoundedAndCorrectAfterEviction(): void
    {
        $resource = $this->fixture(self::MINI_STREAMS, 3000);
        $file = CompoundFile::fromResource($resource);
        try {
            $baseline = memory_get_usage();
            $this->assertReadsMatch($file, range(0, self::MINI_STREAMS - 1), 3000);
            $forward = memory_get_usage() - $baseline;
            // Reverse order defeats the FIFO cache, so every chain is resolved again.
            $this->assertReadsMatch($file, range(self::MINI_STREAMS - 1, 0), 3000);
            $reverse = memory_get_usage() - $baseline;

            self::assertLessThan(self::CACHE_LIMIT_BYTES, $forward, 'Chain caches must not grow with the stream count.');
            self::assertLessThan(self::CACHE_LIMIT_BYTES, $reverse, 'Re-reading evicted chains must not grow the cache.');
        } finally {
            $file->close();
            fclose($resource);
        }
    }

    public function testRegularChainEvictionPreservesRandomAccess(): void
    {
        $resource = $this->fixture(self::REGULAR_STREAMS, 5000);
        $file = CompoundFile::fromResource($resource);
        try {
            $this->assertReadsMatch($file, range(0, self::REGULAR_STREAMS - 1), 5000);
            $mismatches = 0;
            // Seek into chains that the first pass has already evicted.
            foreach ([0, 7, 511, 1199] as $index) {
                $stream = $file->openStream(sprintf('S%04d', $index));
                self::assertTrue($stream->seek(4096));
                if ($stream->read(400) !== str_repeat(chr(65 + $index % 26), 400)) {
                    $mismatches++;
                }
            }
            self::assertSame(0, $mismatches);
        } finally {
            $file->close();
            fclose($resource);
        }
    }

    public function testCloseReleasesCachedChains(): void
    {
        $resource = $this->fixture(self::MINI_STREAMS, 3000);
        $file = CompoundFile::fromResource($resource);
        try {
            $this->assertReadsMatch($file, range(0, self::MINI_STREAMS - 1), 3000);
            $held = memory_get_usage();
            $file->close();

            self::assertLessThan($held, memory_get_usage(), 'close() must drop cached chains and blocks.');
        } finally {
            fclose($resource);
        }
    }

    /** @return resource */
    private function fixture(int $streams, int $size)
    {
        $writer = CompoundFileWriter::create();
        for ($index = 0; $index < $streams; $index++) {
            $writer->setStreamContents(sprintf('S%04d', $index), str_repeat(chr(65 + $index % 26), $size));
        }
        $resource = tmpfile();
        self::assertIsResource($resource);
        $writer->saveToResource($resource);

        return $resource;
    }

    /** @param list<int> $indexes */
    private function assertReadsMatch(CompoundFile $file, array $indexes, int $size): void
    {
        $mismatches = 0;
        foreach ($indexes as $index) {
            if ($file->getStreamContents(sprintf('S%04d', $index)) !== str_repeat(chr(65 + $index % 26), $size)) {
                $mismatches++;
            }
        }
        self::assertSame(0, $mismatches, 'Every stream must read back byte for byte.');
    }
}
