<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use DK\CompoundFile\DirectoryEntry;
use DK\CompoundFile\Header;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoundTripPropertyTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function formatProvider(): iterable
    {
        yield 'v3 little-endian' => [3, Header::LITTLE_ENDIAN];
        yield 'v3 big-endian' => [3, Header::BIG_ENDIAN];
        yield 'v4 little-endian' => [4, Header::LITTLE_ENDIAN];
        yield 'v4 big-endian' => [4, Header::BIG_ENDIAN];
    }

    #[DataProvider('formatProvider')]
    public function testGeneratedTreesSurviveRepeatedRoundTrips(int $version, string $byteOrder): void
    {
        $writer = CompoundFileWriter::create($version, $byteOrder);
        $expected = [];
        foreach (['A', 'A/Nested', 'Unicode/Сховище'] as $storage) {
            $writer->createStorage($storage);
        }

        $sizes = [0, 1, 63, 64, 65, 511, 512, 1023, 4095, 4096, 4097, 16_777];
        foreach ($sizes as $index => $size) {
            $parent = match ($index % 4) {
                0 => '',
                1 => 'A/',
                2 => 'A/Nested/',
                default => 'Unicode/Сховище/',
            };
            $path = $parent.'Stream-'.$index;
            $contents = $this->deterministicBytes($size, 'seed-'.$version.'-'.$byteOrder.'-'.$index);
            $writer->setStreamContents($path, $contents);
            $expected[$path] = hash('sha256', $contents);
        }

        $first = $this->roundTrip($writer);
        $second = $this->roundTrip(CompoundFileWriter::fromCompoundFile($first));

        self::assertSame($this->snapshot($first), $this->snapshot($second));
        foreach ($expected as $path => $hash) {
            self::assertSame($hash, hash('sha256', $second->getStreamContents($path)), $path);
        }
    }

    /** @return array<string, array{type: int, size: int, hash: string|null}> */
    private function snapshot(CompoundFile $file): array
    {
        $snapshot = [];
        foreach ($file->getEntries() as $entry) {
            $snapshot[$entry->getPath()] = [
                'type' => $entry->getType(),
                'size' => $entry->getSize(),
                'hash' => $entry->isStream() ? hash('sha256', $file->getStreamContents($entry)) : null,
            ];
        }
        ksort($snapshot);

        return $snapshot;
    }

    private function deterministicBytes(int $size, string $seed): string
    {
        $contents = '';
        for ($counter = 0; strlen($contents) < $size; $counter++) {
            $contents .= hash('sha256', $seed.'-'.$counter, true);
        }

        return substr($contents, 0, $size);
    }

    private function roundTrip(CompoundFileWriter $writer): CompoundFile
    {
        $resource = fopen('php://temp/maxmemory:1048576', 'w+b');
        self::assertIsResource($resource);
        $writer->saveToResource($resource);
        rewind($resource);

        return CompoundFile::fromResource($resource);
    }
}
