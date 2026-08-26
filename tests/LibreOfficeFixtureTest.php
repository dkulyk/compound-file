<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\StreamWrapper;
use PHPUnit\Framework\TestCase;

final class LibreOfficeFixtureTest extends TestCase
{
    private const FIXTURE = __DIR__.'/fixtures/README.doc';

    public function testParsesDocumentCreatedByLibreOffice(): void
    {
        $file = CompoundFile::open(self::FIXTURE);

        self::assertSame(3, $file->getMajorVersion());
        self::assertTrue($file->hasStream('WordDocument'));
        self::assertTrue($file->hasStream('1Table'));
        self::assertTrue($file->hasStream("\x05SummaryInformation"));
        self::assertTrue($file->hasStream("\x05DocumentSummaryInformation"));

        $wordDocument = $file->getStreamContents('WordDocument');
        self::assertSame("\xEC\xA5", substr($wordDocument, 0, 2));
        self::assertGreaterThan(4096, strlen($wordDocument));
    }

    public function testListsRealDocumentThroughWrapper(): void
    {
        StreamWrapper::register();

        $entries = scandir(StreamWrapper::directoryUrl(self::FIXTURE));

        self::assertIsArray($entries);
        self::assertContains('WordDocument', $entries);
        self::assertContains('1Table', $entries);
    }
}
