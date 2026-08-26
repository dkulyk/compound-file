<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\Exception\CfbfException;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
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

    public function testRejectsDirectoryTreeCycle(): void
    {
        $bytes = FixtureBuilder::regular();
        // Point the Data entry's right sibling back to itself.
        $bytes = substr_replace($bytes, pack('V', 1), 512 + 128 + 72, 4);

        $this->expectException(CfbfException::class);
        $this->parse($bytes);
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
