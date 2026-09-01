<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
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

    public function testRejectsMiniFatCycle(): void
    {
        $bytes = substr_replace(FixtureBuilder::mini(), pack('V', 0), 1024, 4);

        $this->expectException(CfbfException::class);
        $this->parse($bytes)->getStreamContents('Small');
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
}
