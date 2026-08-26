<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

use DK\CompoundFile\Exception\CfbfException;

/** @internal Provides checked random access to a seekable PHP stream. */
final class RandomAccessReader
{
    private mixed $resource;
    private bool $closeWhenDone;
    private int $size;

    /** @param resource $resource */
    private function __construct($resource, bool $closeWhenDone)
    {
        $metadata = stream_get_meta_data($resource);
        $stat = fstat($resource);
        if (empty($metadata['seekable']) || $stat === false) {
            throw new CfbfException('The input must be a seekable stream with a known size.');
        }
        $this->resource = $resource;
        $this->closeWhenDone = $closeWhenDone;
        $this->size = (int) $stat['size'];
    }

    public static function open(string $path): self
    {
        $resource = @fopen($path, 'rb');
        if ($resource === false) {
            throw new CfbfException(sprintf('Cannot open compound file "%s".', $path));
        }
        return new self($resource, true);
    }

    /** @param resource $resource */
    public static function wrap($resource): self
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Expected a PHP stream resource.');
        }
        return new self($resource, false);
    }

    public function size(): int
    {
        return $this->size;
    }

    public function read(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset > $this->size || $length > $this->size - $offset) {
            throw new CfbfException('Attempted to read outside the compound file.');
        }
        $position = ftell($this->resource);
        if ($position !== $offset && fseek($this->resource, $offset) !== 0) {
            throw new CfbfException('Cannot seek in the compound file.');
        }
        $result = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($this->resource, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new CfbfException('Unexpected end of compound file.');
            }
            $result .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $result;
    }

    public function __destruct()
    {
        if ($this->closeWhenDone && is_resource($this->resource)) {
            fclose($this->resource);
        }
    }
}
