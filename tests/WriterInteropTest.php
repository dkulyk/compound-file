<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFileWriter;
use PHPUnit\Framework\TestCase;

final class WriterInteropTest extends TestCase
{
    public function testLibreOfficeOpensARewrittenDocument(): void
    {
        $soffice = getenv('SOFFICE');
        if ($soffice === false || $soffice === '') {
            self::markTestSkipped('Set SOFFICE to run the writer interoperability test.');
        }

        $directory = sys_get_temp_dir().'/compound-writer-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $document = $directory.'/rewritten.doc';
        $profile = $directory.'/profile';
        self::assertTrue(mkdir($profile));

        try {
            CompoundFileWriter::open(__DIR__.'/fixtures/README.doc')->save($document);
            $command = [
                $soffice,
                '-env:UserInstallation='.$this->fileUri($profile),
                '--headless',
                '--convert-to',
                'docx',
                '--outdir',
                $directory,
                $document,
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $standardOutput = stream_get_contents($pipes[1]);
            $standardError = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $standardOutput."\n".$standardError);
            self::assertFileExists($directory.'/rewritten.docx');
            self::assertGreaterThan(0, filesize($directory.'/rewritten.docx'));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function fileUri(string $path): string
    {
        return 'file://'.str_replace('%2F', '/', rawurlencode($path));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
