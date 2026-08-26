<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use PHPUnit\Framework\TestCase;

final class StreamTest extends TestCase
{
    public function testEmptyReadDoesNotMovePosition(): void
    {
        $stream = $this->openRegularStream();

        self::assertSame('', $stream->read(0));
        self::assertSame(0, $stream->tell());
    }

    public function testSeekRejectsPositionsOutsideStream(): void
    {
        $stream = $this->openRegularStream();

        self::assertFalse($stream->seek(-1));
        self::assertFalse($stream->seek(1, 12345));
        self::assertSame(0, $stream->tell());
    }

    public function testReadingPastEndReturnsOnlyRemainingBytes(): void
    {
        $stream = $this->openRegularStream();
        self::assertTrue($stream->seek(-4, SEEK_END));

        self::assertSame('OLE2', $stream->read(100));
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(1));
    }

    public function testRepeatedRandomAccessAcrossManySectors(): void
    {
        $payload = '';
        for ($sector = 0; $sector < 120; $sector++) {
            $payload .= str_repeat(chr($sector), 512);
        }
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, FixtureBuilder::regularWithPayload($payload));
        rewind($resource);
        $stream = CompoundFile::fromResource($resource)->openStream('Data');

        foreach ([118, 3, 87, 0, 117, 42, 87] as $sector) {
            self::assertTrue($stream->seek($sector * 512 + 500));
            self::assertSame(
                str_repeat(chr($sector), 12).str_repeat(chr($sector + 1), 12),
                $stream->read(24),
            );
        }
    }

    private function openRegularStream(): \DK\CompoundFile\Stream
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, FixtureBuilder::regular());
        rewind($resource);

        return CompoundFile::fromResource($resource)->openStream('Data');
    }
}
