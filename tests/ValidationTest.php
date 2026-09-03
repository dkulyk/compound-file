<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use DK\CompoundFile\Exception\CfbfException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    private const HEADER_MAJOR_VERSION = 26;
    private const HEADER_SECTOR_SHIFT = 30;
    private const HEADER_MINI_SECTOR_SHIFT = 32;
    private const HEADER_MINI_STREAM_CUTOFF = 56;
    private const DIRECTORY_SECOND_ENTRY = 512 + 128;

    public function testRejectsTruncatedFile(): void
    {
        $bytes = substr(FixtureBuilder::regular(), 0, -100);

        $this->expectException(CfbfException::class);
        $this->parse($bytes)->getStreamContents('Data');
    }

    public function testRejectsFatCycle(): void
    {
        $bytes = FixtureBuilder::regular();
        // FAT entry zero describes the directory chain. Make it self-referential.
        $bytes = substr_replace($bytes, pack('V', 0), 1024, 4);

        $this->expectException(CfbfException::class);
        $this->parse($bytes);
    }

    public function testRejectsFatReferenceOutsideFile(): void
    {
        $bytes = substr_replace(FixtureBuilder::regular(), pack('V', 127), 1024, 4);

        $this->expectException(CfbfException::class);
        $this->parse($bytes);
    }

    public function testRejectsDeclaredFatLargerThanTheFile(): void
    {
        $bytes = substr_replace(FixtureBuilder::regular(), pack('V', 12), 44, 4);

        $this->expectException(CfbfException::class);
        $this->expectExceptionMessage('Declared FAT size');
        $this->parse($bytes);
    }

    public function testRejectsDuplicateFatSectorReferences(): void
    {
        $bytes = substr_replace(FixtureBuilder::regular(), pack('V', 2), 44, 4);
        $bytes = substr_replace($bytes, pack('V', 1), 80, 4);

        $this->expectException(CfbfException::class);
        $this->expectExceptionMessage('duplicate FAT sector');
        $this->parse($bytes);
    }

    public function testRejectsRegularChainShorterThanDeclaredStreamSize(): void
    {
        $bytes = substr_replace(FixtureBuilder::regular(), pack('V', 0xFFFFFFFE), 1024 + 5 * 4, 4);
        $stream = $this->parse($bytes)->openStream('Data');

        self::assertSame(2048, strlen($stream->read(2048)));
        self::assertFalse($stream->eof());
        $this->expectException(CfbfException::class);
        $this->expectExceptionMessage('shorter than the requested byte range');
        $stream->read(2048);
    }

    public function testRejectsMiniFatCycle(): void
    {
        $bytes = substr_replace(FixtureBuilder::mini(), pack('V', 0), 1024, 4);

        $this->expectException(CfbfException::class);
        $this->parse($bytes)->getStreamContents('Small');
    }

    public function testAllowsRootMiniStreamSizeLargerThanItsFatChain(): void
    {
        $bytes = substr_replace(FixtureBuilder::mini(), pack('V2', 1024, 0), 512 + 120, 8);
        $file = $this->parse($bytes);

        self::assertSame(str_repeat('mini-', 20), $file->getStreamContents('Small'));
    }

    public function testRejectsMiniFatReferenceOutsideMiniStream(): void
    {
        $bytes = substr_replace(FixtureBuilder::mini(), pack('V', 127), 1024, 4);

        $this->expectException(CfbfException::class);
        $this->parse($bytes)->getStreamContents('Small');
    }

    public function testReadsFragmentedMiniFatChainWithoutCrossingPhysicalMiniSectors(): void
    {
        self::assertSame(str_repeat('fragmented-', 10), $this->parse(FixtureBuilder::fragmentedMini())->getStreamContents('Small'));
    }

    public function testIgnoresFileTimeValuesOutsideThePhpIntegerRange(): void
    {
        $bytes = substr_replace(FixtureBuilder::regular(), pack('V2', 0xFFFFFFFF, 0xFFFFFFFF), self::DIRECTORY_SECOND_ENTRY + 100, 8);
        $file = $this->parse($bytes);
        $entry = $file->findEntry('Data');

        self::assertNotNull($entry);
        self::assertNull($entry->getCreationTime());
        self::assertNull($entry->getCreationFileTimeTicks());
        self::assertSame(str_repeat('OLE2', 1024), $file->getStreamContents('Data'));
    }

    public function testIndexesDegenerateSiblingListsWithoutRecursion(): void
    {
        $count = 1500;
        $writer = CompoundFileWriter::create();
        for ($index = 0; $index < $count; $index++) {
            $writer->setStreamContents(sprintf('Entry %04d', $index), (string) $index);
        }
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        $writer->saveToResource($resource);
        rewind($resource);
        $bytes = stream_get_contents($resource);
        self::assertIsString($bytes);
        $directory = 512 + CompoundFile::fromResource($resource)->getHeader()->getDirectoryStartSector() * 512;

        // Relink every entry as one long right-sibling list under the root.
        $bytes = substr_replace($bytes, pack('V', 1), $directory + 76, 4);
        for ($id = 1; $id <= $count; $id++) {
            $links = pack('V2', 0xFFFFFFFF, $id < $count ? $id + 1 : 0xFFFFFFFF);
            $bytes = substr_replace($bytes, $links, $directory + $id * 128 + 68, 8);
        }

        $file = $this->parse($bytes);
        self::assertCount($count + 1, $file->getEntries());
        self::assertSame('1499', $file->getStreamContents('Entry 1499'));
    }

    public function testRejectsDirectoryTreeCycle(): void
    {
        $bytes = FixtureBuilder::regular();
        // Point the Data entry's right sibling back to itself.
        $bytes = substr_replace($bytes, pack('V', 1), 512 + 128 + 72, 4);

        $this->expectException(CfbfException::class);
        $this->parse($bytes);
    }

    #[DataProvider('invalidHeaderProvider')]
    public function testRejectsInvalidHeaderFields(int $offset, string $replacement): void
    {
        $bytes = substr_replace(FixtureBuilder::regular(), $replacement, $offset, strlen($replacement));

        $this->expectException(CfbfException::class);
        $this->parse($bytes);
    }

    /** @return iterable<string, array{int, string}> */
    public static function invalidHeaderProvider(): iterable
    {
        yield 'version 3 with 4096-byte sectors' => [self::HEADER_SECTOR_SHIFT, pack('v', 12)];
        yield 'version 4 with 512-byte sectors' => [self::HEADER_MAJOR_VERSION, pack('v', 4)];
        yield 'unsupported mini-sector size' => [self::HEADER_MINI_SECTOR_SHIFT, pack('v', 7)];
        yield 'unsupported mini-stream cutoff' => [self::HEADER_MINI_STREAM_CUTOFF, pack('V', 2048)];
    }

    #[DataProvider('invalidDirectoryEntryProvider')]
    public function testRejectsInvalidDirectoryEntry(int $relativeOffset, string $replacement): void
    {
        $bytes = substr_replace(
            FixtureBuilder::regular(),
            $replacement,
            self::DIRECTORY_SECOND_ENTRY + $relativeOffset,
            strlen($replacement),
        );

        $this->expectException(CfbfException::class);
        $this->parse($bytes);
    }

    public function testRejectsDuplicateDirectoryPaths(): void
    {
        $bytes = FixtureBuilder::regular();
        $dataEntry = substr($bytes, self::DIRECTORY_SECOND_ENTRY, 128);
        $bytes = substr_replace($bytes, pack('V', 2), self::DIRECTORY_SECOND_ENTRY + 72, 4);
        $bytes = substr_replace($bytes, $dataEntry, self::DIRECTORY_SECOND_ENTRY + 128, 128);

        $this->expectException(CfbfException::class);
        $this->parse($bytes);
    }

    public function testIgnoresDirectoryEntriesUnreachableFromTheRootTree(): void
    {
        $bytes = FixtureBuilder::regular();
        $orphan = substr($bytes, self::DIRECTORY_SECOND_ENTRY, 128);
        $orphan = substr_replace($orphan, pack('V', 0), 116, 4);
        $bytes = substr_replace($bytes, $orphan, self::DIRECTORY_SECOND_ENTRY + 128, 128);

        $file = $this->parse($bytes);
        self::assertSame(['', 'Data'], array_map(static fn ($entry): string => $entry->getPath(), $file->getEntries()));
        self::assertNull($file->getEntryById(2));

        $rewritten = $this->roundTrip(CompoundFileWriter::fromCompoundFile($file));
        self::assertSame(str_repeat('OLE2', 1024), $rewritten->getStreamContents('Data'));
        self::assertCount(2, $rewritten->getEntries());
    }

    /** @return iterable<string, array{int, string}> */
    public static function invalidDirectoryEntryProvider(): iterable
    {
        yield 'unknown object type' => [66, chr(3)];
        yield 'unknown tree color' => [67, chr(2)];
        yield 'second root entry' => [66, chr(5)];
    }

    public function testMissingStreamReturnsNullAndFalse(): void
    {
        $file = $this->parse(FixtureBuilder::regular());

        self::assertNull($file->findEntry('Missing'));
        self::assertFalse($file->hasStream('Missing'));
    }

    private function parse(string $bytes): CompoundFile
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, $bytes);
        rewind($resource);

        return CompoundFile::fromResource($resource);
    }

    private function roundTrip(CompoundFileWriter $writer): CompoundFile
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        $writer->saveToResource($resource);
        rewind($resource);

        return CompoundFile::fromResource($resource);
    }
}
