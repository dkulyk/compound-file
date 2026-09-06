<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use DK\CompoundFile\Exception\CfbfException;
use PHPUnit\Framework\TestCase;

final class CompoundFileTest extends TestCase
{
    public function testChildrenPreserveRecordOrderAndNormalizedStorageLookup(): void
    {
        $writer = CompoundFileWriter::create();
        $writer->createStorage('Ünicode/Nested');
        $writer->createStorage('Empty');
        foreach (['Zulu', 'Alpha', 'Beta'] as $name) {
            $writer->setStreamContents('Ünicode/'.$name, $name);
        }
        $writer->setStreamContents('Ünicode/Nested/Leaf', 'leaf');
        $resource = fopen('php://temp', 'w+b');
        $writer->saveToResource($resource);
        $file = CompoundFile::fromResource($resource);
        try {
            $expected = array_values(array_filter(
                $file->getEntries(),
                static fn ($entry): bool => in_array($entry->getPath(), [
                    'Ünicode/Zulu', 'Ünicode/Alpha', 'Ünicode/Beta', 'Ünicode/Nested',
                ], true),
            ));
            self::assertCount(4, $expected);
            self::assertSame($expected, $file->getChildren('/ünICODE/'));
            self::assertSame([$file->findEntry('Ünicode/Nested/Leaf')], $file->getChildren('ünicode\\nested'));
            self::assertSame([], $file->getChildren('Empty'));
            self::assertCount(2, $file->getChildren());
            $children = $file->getChildren('Ünicode');
            array_pop($children);
            self::assertSame($expected, $file->getChildren('Ünicode'));
            foreach (['Missing', 'Ünicode/Alpha'] as $path) {
                try {
                    $file->getChildren($path);
                    self::fail('Missing entries and streams must not be accepted as storages.');
                } catch (CfbfException $exception) {
                    self::assertStringContainsString('does not exist', $exception->getMessage());
                }
            }
        } finally {
            $file->close();
            fclose($resource);
        }
    }

    public function testReadsRegularFatStreamAndSeeks(): void
    {
        $resource = fopen('php://temp', 'w+b');
        fwrite($resource, FixtureBuilder::regular('Résumé'));
        rewind($resource);
        $file = CompoundFile::fromResource($resource);
        self::assertSame(3, $file->getMajorVersion());
        self::assertTrue($file->hasStream('résumé'));
        $stream = $file->openStream('Résumé');
        self::assertSame('OLE2OLE2', $stream->read(8));
        self::assertTrue($stream->seek(-4, SEEK_END));
        self::assertSame('OLE2', $stream->read(10));
    }
    public function testReadsMiniFatStream(): void
    {
        $resource = fopen('php://temp', 'w+b');
        fwrite($resource, FixtureBuilder::mini());
        rewind($resource);
        self::assertSame(str_repeat('mini-', 20), CompoundFile::fromResource($resource)->getStreamContents('Small'));
    }
    public function testReadsBigEndianNamesAndData(): void
    {
        $resource = fopen('php://temp', 'w+b');
        fwrite($resource, FixtureBuilder::regular('Дані', false));
        rewind($resource);
        self::assertSame(str_repeat('OLE2', 1024), CompoundFile::fromResource($resource)->getStreamContents('Дані'));
    }

    public function testUnicodePathLookupUsesTheSameCaseFoldingAsTheWriter(): void
    {
        $file = $this->parse(FixtureBuilder::regular('Ünicode'));

        self::assertTrue($file->hasStream('ünicode'));
        self::assertSame(str_repeat('OLE2', 1024), $file->getStreamContents('ÜNICODE'));
    }

    public function testLittleAndBigEndianFilesExposeEquivalentStreams(): void
    {
        $littleEndian = $this->parse(FixtureBuilder::regular('Equivalent', true));
        $bigEndian = $this->parse(FixtureBuilder::regular('Equivalent', false));

        self::assertSame(
            array_map(static fn ($entry): string => $entry->getPath(), $littleEndian->getEntries()),
            array_map(static fn ($entry): string => $entry->getPath(), $bigEndian->getEntries())
        );
        self::assertSame(
            $littleEndian->getStreamContents('Equivalent'),
            $bigEndian->getStreamContents('Equivalent')
        );
    }
    public function testRejectsInvalidSignature(): void
    {
        $resource = fopen('php://temp', 'w+b');
        fwrite($resource, str_repeat("\0", 512));
        rewind($resource);
        $this->expectException(CfbfException::class);
        CompoundFile::fromResource($resource);
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
