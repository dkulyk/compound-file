<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
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

    private function parse(string $bytes): CompoundFile
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, $bytes);
        rewind($resource);

        return CompoundFile::fromResource($resource);
    }
}
