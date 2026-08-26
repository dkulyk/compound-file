<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\StreamWrapper;
use PHPUnit\Framework\TestCase;

final class StreamWrapperTest extends TestCase
{
    public function testNativePhpStreamAccess(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ole2-');
        file_put_contents($file, FixtureBuilder::regular());
        StreamWrapper::register();
        $handle = fopen(StreamWrapper::url($file, 'Data'), 'rb');
        self::assertIsResource($handle);
        self::assertSame('OLE2', fread($handle, 4));
        fseek($handle, -4, SEEK_END);
        self::assertSame('OLE2', fread($handle, 4));
        fclose($handle);
        unlink($file);
    }

    public function testNativeDirectoryAccess(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ole2-');
        file_put_contents($file, FixtureBuilder::regular());
        StreamWrapper::register();

        $entries = scandir(StreamWrapper::directoryUrl($file));

        self::assertSame(['.', '..', 'Data'], $entries);
        self::assertTrue(is_dir(StreamWrapper::directoryUrl($file)));
        self::assertTrue(is_file(StreamWrapper::url($file, 'Data')));
        unlink($file);
    }

    public function testOpeningMissingCompoundFileFails(): void
    {
        StreamWrapper::register();

        self::assertFalse(@fopen(StreamWrapper::url('/file/does/not/exist.cfb', 'Data'), 'rb'));
    }

    public function testOpeningMissingInternalStreamFails(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ole2-');
        file_put_contents($file, FixtureBuilder::regular());
        StreamWrapper::register();

        self::assertFalse(@fopen(StreamWrapper::url($file, 'Missing'), 'rb'));
        unlink($file);
    }

    public function testSupportsFragmentStyleUrl(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ole2-');
        file_put_contents($file, FixtureBuilder::regular());
        StreamWrapper::register();

        $handle = fopen('ole2://' . $file . '#Data', 'rb');

        self::assertIsResource($handle);
        self::assertSame('OLE2', fread($handle, 4));
        fclose($handle);
        unlink($file);
    }
}
