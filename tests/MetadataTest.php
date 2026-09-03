<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use DK\CompoundFile\DirectoryEntry;
use DK\CompoundFile\Header;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    public function testExposesHeaderAndAllocationTables(): void
    {
        $file = $this->parse(FixtureBuilder::mini());
        $header = $file->getHeader();

        self::assertSame(3, $header->getMajorVersion());
        self::assertSame(0x003E, $header->getMinorVersion());
        self::assertSame(Header::LITTLE_ENDIAN, $header->getByteOrder());
        self::assertSame(512, $header->getSectorSize());
        self::assertSame(64, $header->getMiniSectorSize());
        self::assertTrue($header->hasMiniFat());
        self::assertSame(0, $header->getDirectorySectorCount());

        $tables = $file->getAllocationTable();
        self::assertSame([3], $tables->getDifat());
        self::assertSame(0xFFFFFFFE, $tables->getFat()[0]);
        self::assertSame([1, 0xFFFFFFFE], array_slice($tables->getMiniFat(), 0, 2));
    }

    public function testExposesDirectoryMetadataAndNavigation(): void
    {
        $file = $this->parse(FixtureBuilder::regular());
        $root = $file->findEntry('');
        self::assertInstanceOf(DirectoryEntry::class, $root);
        $stream = $root->getChild();

        self::assertInstanceOf(DirectoryEntry::class, $stream);
        self::assertSame('Data', $stream->getName());
        self::assertSame(10, $stream->getNameByteLength());
        self::assertSame(DirectoryEntry::COLOR_BLACK, $stream->getColor());
        self::assertSame('00000000-0000-0000-0000-000000000000', $stream->getClassId());
        self::assertSame(0, $stream->getStateBits());
        self::assertNull($stream->getCreationTime());
        self::assertNull($stream->getModifiedTime());
        self::assertSame(str_repeat('OLE2', 1024), $file->openStream($stream)->getContents());
    }

    public function testRewritePreservesExactFileTimeTicks(): void
    {
        $ticks = 116_444_736_000_000_007;
        $encoded = pack('V2', $ticks % 4_294_967_296, intdiv($ticks, 4_294_967_296));
        $bytes = substr_replace(FixtureBuilder::regular(), $encoded, 512 + 128 + 100, 8);
        $source = $this->parse($bytes);
        $sourceEntry = $source->findEntry('Data');
        self::assertInstanceOf(DirectoryEntry::class, $sourceEntry);
        self::assertSame($ticks, $sourceEntry->getCreationFileTimeTicks());
        self::assertSame('1970-01-01 00:00:00.000000', $sourceEntry->getCreationTime()?->format('Y-m-d H:i:s.u'));

        $rewritten = $this->roundTrip(CompoundFileWriter::fromCompoundFile($source));
        self::assertSame($ticks, $rewritten->findEntry('Data')?->getCreationFileTimeTicks());
    }

    public function testExplicitTimestampsPreserveMicroseconds(): void
    {
        $created = new \DateTimeImmutable('2026-09-03 12:34:56.123456 UTC');
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Data', 'value');
        $writer->setTimestamps('Data', $created, null);

        $entry = $this->roundTrip($writer)->findEntry('Data');
        self::assertSame($created->format('U.u'), $entry?->getCreationTime()?->format('U.u'));
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
