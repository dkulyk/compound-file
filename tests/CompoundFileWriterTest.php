<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\CompoundFileWriter;
use DK\CompoundFile\DirectoryEntry;
use DK\CompoundFile\Exception\CfbfException;
use DK\CompoundFile\Header;
use PHPUnit\Framework\TestCase;

final class CompoundFileWriterTest extends TestCase
{
    public function testCreatesEmptyVersionThreeFile(): void
    {
        $file = $this->roundTrip(CompoundFileWriter::create());

        self::assertSame(3, $file->getMajorVersion());
        self::assertSame([''], array_map(static fn (DirectoryEntry $entry): string => $entry->getPath(), $file->getEntries()));
        self::assertSame([], $file->getChildren());
        self::assertFalse($file->getHeader()->hasMiniFat());
        self::assertFalse($file->getHeader()->hasDifatSectors());
    }

    public function testWritesAllMiniAndRegularStreamBoundaries(): void
    {
        $writer = CompoundFileWriter::create();
        $expected = [];
        foreach ([0, 1, 63, 64, 65, 511, 512, 4095, 4096, 4097, 8193] as $size) {
            $name = 'Size'.$size;
            $contents = $this->contents($size);
            $expected[$name] = $contents;
            $writer->setStreamContents($name, $contents);
        }

        $file = $this->roundTrip($writer);
        foreach ($expected as $name => $contents) {
            self::assertSame(strlen($contents), $file->findEntry($name)?->getSize(), $name);
            self::assertSame($contents, $file->getStreamContents($name), $name);
        }
        self::assertTrue($file->getHeader()->hasMiniFat());
    }

    public function testWritesNestedUnicodeStoragesAndBalancedDirectoryTrees(): void
    {
        $writer = CompoundFileWriter::create();
        $writer->createStorage('Обʼєкти/Рівень 2');
        $writer->setStreamContents('Обʼєкти/Рівень 2/Дані', 'unicode');
        $writer->setStreamContents("\u{E000}", 'private-use');
        $writer->setStreamContents('😀', 'supplementary');
        for ($index = 0; $index < 80; $index++) {
            $writer->setStreamContents(sprintf('Entry %02d', $index), 'value-'.$index);
        }

        $file = $this->roundTrip($writer);
        self::assertSame('unicode', $file->getStreamContents('Обʼєкти/Рівень 2/Дані'));
        self::assertCount(83, $file->getChildren());
        self::assertSame('private-use', $file->getStreamContents("\u{E000}"));
        self::assertSame('supplementary', $file->getStreamContents('😀'));
        $this->assertValidDirectoryTrees($file);
        for ($index = 0; $index < 80; $index++) {
            self::assertSame('value-'.$index, $file->getStreamContents(sprintf('Entry %02d', $index)));
        }
    }

    public function testWritesVersionFourWith4096ByteSectors(): void
    {
        $writer = CompoundFileWriter::create(4);
        $writer->setStreamContents('Mini', str_repeat('m', 777));
        $writer->setStreamContents('Regular', str_repeat('r', 12_345));

        $file = $this->roundTrip($writer);
        self::assertSame(4, $file->getMajorVersion());
        self::assertSame(4096, $file->getHeader()->getSectorSize());
        self::assertSame(1, $file->getHeader()->getDirectorySectorCount());
        self::assertSame(str_repeat('m', 777), $file->getStreamContents('Mini'));
        self::assertSame(str_repeat('r', 12_345), $file->getStreamContents('Regular'));
    }

    public function testWritesBigEndianContainer(): void
    {
        $writer = CompoundFileWriter::create(3, Header::BIG_ENDIAN);
        $writer->createStorage('Сховище');
        $writer->setStreamContents('Малий', str_repeat('m', 333));
        $writer->setStreamContents('Сховище/Потік', str_repeat('BE', 2500));

        $file = $this->roundTrip($writer);
        self::assertTrue($file->getHeader()->isBigEndian());
        self::assertSame(str_repeat('m', 333), $file->getStreamContents('Малий'));
        self::assertSame(str_repeat('BE', 2500), $file->getStreamContents('Сховище/Потік'));
    }

    public function testUnicodePathRegistryUsesCfbfCaseFolding(): void
    {
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Ünicode', 'value');

        self::assertTrue($writer->hasEntry('ünicode'));
        self::assertSame('value', $this->roundTrip($writer)->getStreamContents('ÜNICODE'));
    }

    public function testMiniFatSpansMultipleSectors(): void
    {
        $writer = CompoundFileWriter::create();
        for ($index = 0; $index < 5; $index++) {
            $writer->setStreamContents('Mini'.$index, str_repeat(chr(65 + $index), 3000));
        }

        $file = $this->roundTrip($writer);
        self::assertGreaterThan(1, $file->getHeader()->getMiniFatSectorCount());
        for ($index = 0; $index < 5; $index++) {
            self::assertSame(str_repeat(chr(65 + $index), 3000), $file->getStreamContents('Mini'.$index));
        }
    }

    public function testFatSpansMultipleSectorsWithoutDifat(): void
    {
        $contents = $this->contents(300_000);
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Medium', $contents);

        $file = $this->roundTrip($writer);
        self::assertGreaterThan(1, $file->getHeader()->getFatSectorCount());
        self::assertFalse($file->getHeader()->hasDifatSectors());
        self::assertSame($contents, $file->getStreamContents('Medium'));
    }

    public function testRemovingLastSmallStreamRemovesMiniFat(): void
    {
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Temporary', 'small');
        self::assertTrue($writer->remove('Temporary'));

        $file = $this->roundTrip($writer);
        self::assertFalse($file->getHeader()->hasMiniFat());
        self::assertSame([''], $writer->getEntryPaths());
    }

    public function testRewritesExistingFileAndPreservesUnchangedStreams(): void
    {
        $original = $this->parse(FixtureBuilder::regularWithPayload(str_repeat('A', 8192), 'Original'));
        $writer = CompoundFileWriter::fromCompoundFile($original);
        $writer->createStorage('Added');
        $writer->setStreamContents('Added/Small', 'new');
        $writer->setStreamContents('Replacement', str_repeat('B', 5000));

        $file = $this->roundTrip($writer);
        self::assertSame(str_repeat('A', 8192), $file->getStreamContents('Original'));
        self::assertSame('new', $file->getStreamContents('Added/Small'));
        self::assertSame(str_repeat('B', 5000), $file->getStreamContents('Replacement'));
    }

    public function testImportsFromResourceWithoutTakingOwnership(): void
    {
        $source = fopen('php://temp', 'w+b');
        self::assertIsResource($source);
        fwrite($source, FixtureBuilder::mini());
        rewind($source);
        $writer = CompoundFileWriter::fromResource($source);
        $writer->setStreamContents('Added', 'value');

        $file = $this->roundTrip($writer);
        self::assertIsResource($source);
        self::assertSame(str_repeat('mini-', 20), $file->getStreamContents('Small'));
        self::assertSame('value', $file->getStreamContents('Added'));
    }

    public function testReplacesAndRemovesEntriesRecursively(): void
    {
        $writer = CompoundFileWriter::create();
        $writer->createStorage('A/B');
        $writer->setStreamContents('A/B/Old', 'old');
        $writer->setStreamContents('Keep', 'before');
        self::assertTrue($writer->hasEntry('a/b/old'));
        self::assertTrue($writer->remove('A'));
        self::assertFalse($writer->remove('Missing'));
        $writer->setStreamContents('keep', 'after');

        $file = $this->roundTrip($writer);
        self::assertNull($file->findEntry('A'));
        self::assertSame('after', $file->getStreamContents('keep'));
    }

    public function testCreatesAndReadsDifatSectors(): void
    {
        $contents = $this->contents(8 * 1024 * 1024);
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Large', $contents);

        $file = $this->roundTrip($writer);
        self::assertTrue($file->getHeader()->hasDifatSectors());
        self::assertGreaterThan(109, $file->getHeader()->getFatSectorCount());
        self::assertSame(strlen($contents), $file->findEntry('Large')?->getSize());
        self::assertSame(hash('sha256', $contents), hash('sha256', $file->getStreamContents('Large')));
    }

    public function testSaveCanAtomicallyReplaceItsSourceFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'compound-writer-');
        self::assertIsString($path);
        try {
            $initial = CompoundFileWriter::create();
            $initial->setStreamContents('Data', 'before');
            $initial->save($path);

            $rewrite = CompoundFileWriter::open($path);
            $rewrite->setStreamContents('Data', 'after');
            $rewrite->save($path);

            $check = CompoundFile::open($path);
            self::assertSame('after', $check->getStreamContents('Data'));
            $check->close();
            $rewrite->setStreamContents('Data', 'second save');
            $rewrite->save($path);
            $check = CompoundFile::open($path);
            self::assertSame('second save', $check->getStreamContents('Data'));
            $check->close();
        } finally {
            @unlink($path);
        }
    }

    public function testAtomicSavePreservesExistingPermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions are not available on Windows.');
        }
        $path = tempnam(sys_get_temp_dir(), 'compound-permissions-');
        self::assertIsString($path);
        self::assertTrue(chmod($path, 0o640));
        try {
            $writer = CompoundFileWriter::create();
            $writer->setStreamContents('Data', 'permissions');
            $writer->save($path);

            clearstatcache(true, $path);
            self::assertSame(0o640, fileperms($path) & 0o777);
            self::assertSame('permissions', CompoundFile::open($path)->getStreamContents('Data'));
        } finally {
            @unlink($path);
        }
    }

    public function testNewFilePermissionsRespectTheProcessUmask(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions are not available on Windows.');
        }
        $directory = sys_get_temp_dir().'/compound-permissions-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $path = $directory.'/new.ole';
        $previousUmask = umask(0o027);
        try {
            $writer = CompoundFileWriter::create();
            $writer->setStreamContents('Data', 'permissions');
            $writer->save($path);

            clearstatcache(true, $path);
            self::assertSame(0o640, fileperms($path) & 0o777);
        } finally {
            umask($previousUmask);
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testVersionThreeRejectsStreamsAtOrAboveTwoGibibytesBeforeWriting(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Creating a 2 GiB sparse stream requires 64-bit PHP.');
        }
        $path = tempnam(sys_get_temp_dir(), 'compound-large-stream-');
        self::assertIsString($path);
        $source = fopen($path, 'w+b');
        self::assertIsResource($source);
        self::assertTrue(ftruncate($source, 0x80000000));
        $output = fopen('php://temp', 'w+b');
        self::assertIsResource($output);

        try {
            $writer = CompoundFileWriter::create(3);
            $writer->setStreamResource('Large', $source);
            $this->expectException(CfbfException::class);
            $this->expectExceptionMessage('cannot exceed 2 GiB');
            $writer->saveToResource($output);
        } finally {
            fclose($source);
            fclose($output);
            @unlink($path);
        }
    }

    public function testFailedAtomicReplacementRemovesTemporaryFile(): void
    {
        $directory = sys_get_temp_dir().'/compound-target-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        try {
            $before = glob(sys_get_temp_dir().'/.compound-file-*');
            self::assertIsArray($before);
            $writer = CompoundFileWriter::create();
            $writer->setStreamContents('Data', 'value');
            try {
                $writer->save($directory);
                self::fail('Replacing a directory with a compound file succeeded.');
            } catch (CfbfException $exception) {
                self::assertStringContainsString('Cannot replace', $exception->getMessage());
            }
            $after = glob(sys_get_temp_dir().'/.compound-file-*');
            self::assertIsArray($after);
            self::assertSame($before, $after);
        } finally {
            rmdir($directory);
        }
    }

    public function testSaveToResourceKeepsResourceOpenAndTruncatesIt(): void
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, str_repeat('old', 10_000));
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Data', 'new');
        $writer->saveToResource($resource);

        self::assertIsResource($resource);
        $size = ftell($resource);
        self::assertIsInt($size);
        self::assertLessThan(30_000, $size);
        rewind($resource);
        self::assertSame('new', CompoundFile::fromResource($resource)->getStreamContents('Data'));
    }

    public function testWritesAStreamFromResourceWithoutTakingOwnership(): void
    {
        $source = fopen('php://temp', 'w+b');
        self::assertIsResource($source);
        $contents = $this->contents(12_345);
        fwrite($source, $contents);
        fseek($source, 100);

        $writer = CompoundFileWriter::create();
        $writer->setStreamResource('Resource', $source);
        $file = $this->roundTrip($writer);

        self::assertIsResource($source);
        self::assertSame($contents, $file->getStreamContents('Resource'));
    }

    public function testWritesEntryMetadata(): void
    {
        $created = new \DateTimeImmutable('2024-01-02 03:04:05 UTC');
        $modified = new \DateTimeImmutable('2025-06-07 08:09:10 UTC');
        $writer = CompoundFileWriter::create();
        $writer->createStorage('Object');
        $writer->setClassId('Object', '00020906-0000-0000-c000-000000000046');
        $writer->setStateBits('Object', 0x12345678);
        $writer->setTimestamps('Object', $created, $modified);

        $entry = $this->roundTrip($writer)->findEntry('Object');
        self::assertInstanceOf(DirectoryEntry::class, $entry);
        self::assertSame('00020906-0000-0000-c000-000000000046', $entry->getClassId());
        self::assertSame(0x12345678, $entry->getStateBits());
        self::assertEquals($created, $entry->getCreationTime());
        self::assertEquals($modified, $entry->getModifiedTime());
    }

    public function testRewritesLibreOfficeFixtureWithoutChangingStreamBytesOrMetadata(): void
    {
        $source = CompoundFile::open(__DIR__.'/fixtures/README.doc');
        $file = $this->roundTrip(CompoundFileWriter::fromCompoundFile($source));

        $sourceEntries = [];
        foreach ($source->getEntries() as $entry) {
            $sourceEntries[$entry->getPath()] = $entry;
        }
        foreach ($file->getEntries() as $entry) {
            self::assertArrayHasKey($entry->getPath(), $sourceEntries);
            $original = $sourceEntries[$entry->getPath()];
            self::assertSame($original->getType(), $entry->getType());
            self::assertSame($original->getClassId(), $entry->getClassId());
            self::assertSame($original->getStateBits(), $entry->getStateBits());
            self::assertEquals($original->getCreationTime(), $entry->getCreationTime());
            self::assertEquals($original->getModifiedTime(), $entry->getModifiedTime());
            if ($entry->isStream()) {
                self::assertSame(
                    hash('sha256', $source->getStreamContents($original)),
                    hash('sha256', $file->getStreamContents($entry)),
                    $entry->getPath(),
                );
            }
        }
        self::assertCount(count($sourceEntries), $file->getEntries());
        $this->assertValidDirectoryTrees($file);
    }

    public function testRejectsInvalidOperationsAndNames(): void
    {
        $writer = CompoundFileWriter::create();

        try {
            $writer->setStreamContents('Missing/Data', 'x');
            self::fail('Missing parent storage was accepted.');
        } catch (CfbfException $exception) {
            self::assertStringContainsString('Parent storage', $exception->getMessage());
        }

        foreach (['', 'Bad:Name', 'Bad!Name', "Bad\0Name", "Bad\xFFName", str_repeat('x', 32)] as $path) {
            try {
                $writer->setStreamContents($path, 'x');
                self::fail(sprintf('Invalid path "%s" was accepted.', $path));
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $writer->createStorage('Storage');
        $this->expectException(CfbfException::class);
        $writer->setStreamContents('Storage', 'x');
    }

    public function testRejectsEquivalentCfbfSiblingNames(): void
    {
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Straße', 'one');
        $writer->setStreamContents('STRASSE', 'two');

        $this->expectException(CfbfException::class);
        $this->roundTrip($writer);
    }

    public function testRejectsInvalidWriterConfigurationAndMetadata(): void
    {
        foreach (
            [
                static fn (): CompoundFileWriter => CompoundFileWriter::create(2),
                static fn (): CompoundFileWriter => CompoundFileWriter::create(3, 'middle'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Invalid writer configuration was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $writer = CompoundFileWriter::create();
        try {
            $writer->setClassId('', 'invalid');
            self::fail('Invalid CLSID was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('CLSID', $exception->getMessage());
        }
        try {
            $writer->setStateBits('', -1);
            self::fail('Negative state bits were accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('unsigned', $exception->getMessage());
        }
        $writer->setTimestamps('', new \DateTimeImmutable('1600-01-01 UTC'), null);
        $this->expectException(CfbfException::class);
        $this->roundTrip($writer);
    }

    public function testRejectsInvalidStorageAndOutputOperations(): void
    {
        $writer = CompoundFileWriter::create();
        $writer->setStreamContents('Stream', 'value');
        try {
            $writer->createStorage('Stream/Child');
            self::fail('A storage was created below a stream.');
        } catch (CfbfException $exception) {
            self::assertStringContainsString('stream exists', $exception->getMessage());
        }
        try {
            $writer->remove('');
            self::fail('The root storage was removed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('root', $exception->getMessage());
        }
        try {
            $writer->setClassId('Missing', '00000000-0000-0000-0000-000000000000');
            self::fail('Metadata was assigned to a missing entry.');
        } catch (CfbfException $exception) {
            self::assertStringContainsString('does not exist', $exception->getMessage());
        }
        try {
            $writer->saveToResource('not a resource');
            self::fail('A non-resource output was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('resource', $exception->getMessage());
        }

        $path = tempnam(sys_get_temp_dir(), 'compound-read-only-');
        self::assertIsString($path);
        $readOnly = fopen($path, 'rb');
        self::assertIsResource($readOnly);
        try {
            $writer->saveToResource($readOnly);
            self::fail('A read-only resource was accepted.');
        } catch (CfbfException $exception) {
            self::assertStringContainsString('writable', $exception->getMessage());
        } finally {
            fclose($readOnly);
            unlink($path);
        }
    }

    private function roundTrip(CompoundFileWriter $writer): CompoundFile
    {
        $resource = fopen('php://temp/maxmemory:1048576', 'w+b');
        self::assertIsResource($resource);
        $writer->saveToResource($resource);
        rewind($resource);

        return CompoundFile::fromResource($resource);
    }

    private function parse(string $bytes): CompoundFile
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, $bytes);
        rewind($resource);

        return CompoundFile::fromResource($resource);
    }

    private function contents(int $size): string
    {
        if ($size === 0) {
            return '';
        }
        $pattern = "\0writer-boundary\xFF";

        return substr(str_repeat($pattern, intdiv($size + strlen($pattern) - 1, strlen($pattern))), 0, $size);
    }

    private function assertValidDirectoryTrees(CompoundFile $file): void
    {
        foreach ($file->getEntries() as $storage) {
            if (!$storage->isStorage()) {
                continue;
            }
            $expectedIds = array_map(
                static fn (DirectoryEntry $entry): int => $entry->getId(),
                $file->getChildren($storage->getPath()),
            );
            sort($expectedIds);
            $root = $storage->getChild();
            if ($expectedIds === []) {
                self::assertNull($root, $storage->getPath());
                continue;
            }
            self::assertInstanceOf(DirectoryEntry::class, $root);
            self::assertSame(DirectoryEntry::COLOR_BLACK, $root->getColor(), $storage->getPath());
            $visited = [];
            $this->directoryBlackHeight($root, $visited);
            $visitedIds = array_keys($visited);
            sort($visitedIds);
            self::assertSame($expectedIds, $visitedIds, $storage->getPath());
        }
    }

    /** @param array<int, true> $visited */
    private function directoryBlackHeight(?DirectoryEntry $entry, array &$visited): int
    {
        if ($entry === null) {
            return 1;
        }
        self::assertArrayNotHasKey($entry->getId(), $visited);
        $visited[$entry->getId()] = true;
        $left = $entry->getLeftSibling();
        $right = $entry->getRightSibling();
        if ($entry->getColor() === DirectoryEntry::COLOR_RED) {
            self::assertTrue($left === null || $left->getColor() === DirectoryEntry::COLOR_BLACK);
            self::assertTrue($right === null || $right->getColor() === DirectoryEntry::COLOR_BLACK);
        }
        $leftHeight = $this->directoryBlackHeight($left, $visited);
        $rightHeight = $this->directoryBlackHeight($right, $visited);
        self::assertSame($leftHeight, $rightHeight, $entry->getPath());

        return $leftHeight + ($entry->getColor() === DirectoryEntry::COLOR_BLACK ? 1 : 0);
    }
}
