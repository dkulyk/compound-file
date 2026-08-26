<?php

declare(strict_types=1);

namespace DK\CompoundFile;

/** A seekable, read-only view of one stream inside a compound file. */
final class Stream
{
    private CompoundFile $file;
    private DirectoryEntry $entry;
    private int $position = 0;

    /** @internal */
    public function __construct(CompoundFile $file, DirectoryEntry $entry)
    {
        $this->file = $file;
        $this->entry = $entry;
    }
    /** Returns the total stream size in bytes. */
    public function getSize(): int
    {
        return $this->entry->getSize();
    }
    /** Returns the current byte offset. */
    public function tell(): int
    {
        return $this->position;
    }
    /** Returns true when the current position is at the end. */
    public function eof(): bool
    {
        return $this->position >= $this->getSize();
    }
    /** Reads at most $length bytes and advances the current position. */
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Read length cannot be negative.');
        }
        $data = $this->file->readEntry($this->entry, $this->position, $length);
        $this->position += strlen($data);
        return $data;
    }
    /** Reads the complete stream without changing the current position. */
    public function getContents(): string
    {
        return $this->file->readEntry($this->entry, 0, $this->getSize());
    }
    /** Moves the current position; returns false when the target is outside the stream. */
    public function seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($whence === SEEK_SET) {
            $target = $offset;
        } elseif ($whence === SEEK_CUR) {
            $target = $this->position + $offset;
        } elseif ($whence === SEEK_END) {
            $target = $this->getSize() + $offset;
        } else {
            return false;
        }
        if ($target < 0 || $target > $this->getSize()) {
            return false;
        }
        $this->position = $target;
        return true;
    }
}
