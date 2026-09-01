<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\Exception\CfbfException;
use PHPUnit\Framework\TestCase;

final class MutationTest extends TestCase
{
    public function testDeterministicMutationsFailCleanlyOrRemainReadable(): void
    {
        $fixtures = [
            'regular' => FixtureBuilder::regular(),
            'mini' => FixtureBuilder::mini(),
            'big-endian' => FixtureBuilder::regular('Data', false),
        ];
        $handled = 0;

        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            },
        );
        try {
            foreach ($fixtures as $fixtureName => $bytes) {
                foreach ($this->mutations($bytes) as $mutationName => $mutated) {
                    try {
                        $file = $this->parse($mutated);
                        foreach ($file->getEntries() as $entry) {
                            if ($entry->isStream() && $entry->getSize() > 0) {
                                $file->openStream($entry)->read(min(8192, $entry->getSize()));
                            }
                        }
                    } catch (CfbfException) {
                        // Rejecting malformed input is the expected controlled outcome.
                    } catch (\Throwable $exception) {
                        self::fail(sprintf(
                            '%s/%s raised uncontrolled %s: %s',
                            $fixtureName,
                            $mutationName,
                            $exception::class,
                            $exception->getMessage(),
                        ));
                    }
                    $handled++;
                }
            }
        } finally {
            restore_error_handler();
        }

        self::assertSame(999, $handled);
    }

    /** @return array<string, string> */
    private function mutations(string $bytes): array
    {
        $mutations = [];
        $length = strlen($bytes);
        $state = 0x4F4C4532;

        for ($index = 0; $index < 96; $index++) {
            $offset = $this->random($state, $length);
            $bit = 1 << $this->random($state, 8);
            $mutated = $bytes;
            $mutated[$offset] = chr(ord($mutated[$offset]) ^ $bit);
            $mutations[sprintf('bit-%03d-at-%d', $index, $offset)] = $mutated;
        }

        $values = [0, 1, 0x7FFFFFFF, 0xFFFFFFFC, 0xFFFFFFFD, 0xFFFFFFFE, 0xFFFFFFFF];
        for ($index = 0; $index < 32; $index++) {
            $offset = $this->random($state, intdiv($length - 4, 4) + 1) * 4;
            foreach ($values as $value) {
                $mutations[sprintf('u32-%03d-%08x-at-%d', $index, $value, $offset)] = substr_replace(
                    $bytes,
                    pack('V', $value),
                    $offset,
                    4,
                );
            }
        }

        foreach ([0, 1, 7, 8, 27, 28, 63, 127, 511, 512, 513, $length - 1, $length] as $size) {
            $mutations['truncate-'.$size] = substr($bytes, 0, $size);
        }

        return $mutations;
    }

    private function random(int &$state, int $limit): int
    {
        $state = (int) (($state * 1103515245 + 12345) & 0x7FFFFFFF);

        return $state % $limit;
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
