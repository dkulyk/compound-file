<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

use DK\CompoundFile\CompoundFile;
use DK\CompoundFile\DirectoryEntry;

/** @internal Mutable logical entry used while writing a compound file. */
final class WritableEntry
{
    private ?string $contents;
    private ?CompoundFile $sourceFile;
    private ?DirectoryEntry $sourceEntry;
    private ?RandomAccessReader $sourceReader;

    private function __construct(
        public string $path,
        public string $name,
        public int $type,
        public string $classId,
        public int $stateBits,
        public ?\DateTimeImmutable $creationTime,
        public ?\DateTimeImmutable $modifiedTime,
        public ?int $creationFileTimeTicks,
        public ?int $modifiedFileTimeTicks,
        private int $size,
        ?string $contents,
        ?CompoundFile $sourceFile,
        ?DirectoryEntry $sourceEntry,
        ?RandomAccessReader $sourceReader,
    ) {
        $this->contents = $contents;
        $this->sourceFile = $sourceFile;
        $this->sourceEntry = $sourceEntry;
        $this->sourceReader = $sourceReader;
    }

    public static function root(): self
    {
        return new self(
            '',
            'Root Entry',
            DirectoryEntry::TYPE_ROOT,
            '00000000-0000-0000-0000-000000000000',
            0,
            null,
            null,
            null,
            null,
            0,
            null,
            null,
            null,
            null,
        );
    }

    public static function storage(string $path, string $name): self
    {
        return new self(
            $path,
            $name,
            DirectoryEntry::TYPE_STORAGE,
            '00000000-0000-0000-0000-000000000000',
            0,
            null,
            null,
            null,
            null,
            0,
            null,
            null,
            null,
            null,
        );
    }

    public static function stream(string $path, string $name, string $contents): self
    {
        return new self(
            $path,
            $name,
            DirectoryEntry::TYPE_STREAM,
            '00000000-0000-0000-0000-000000000000',
            0,
            null,
            null,
            null,
            null,
            strlen($contents),
            $contents,
            null,
            null,
            null,
        );
    }

    public static function imported(CompoundFile $file, DirectoryEntry $entry): self
    {
        return new self(
            $entry->getPath(),
            $entry->getName(),
            $entry->getType(),
            $entry->getClassId(),
            $entry->getStateBits(),
            $entry->getCreationTime(),
            $entry->getModifiedTime(),
            $entry->getCreationFileTimeTicks(),
            $entry->getModifiedFileTimeTicks(),
            $entry->getSize(),
            null,
            $entry->isStream() ? $file : null,
            $entry->isStream() ? $entry : null,
            null,
        );
    }

    public static function resource(string $path, string $name, RandomAccessReader $reader): self
    {
        return new self(
            $path,
            $name,
            DirectoryEntry::TYPE_STREAM,
            '00000000-0000-0000-0000-000000000000',
            0,
            null,
            null,
            null,
            null,
            $reader->size(),
            null,
            null,
            null,
            $reader,
        );
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function isStream(): bool
    {
        return $this->type === DirectoryEntry::TYPE_STREAM;
    }

    public function isStorage(): bool
    {
        return $this->type === DirectoryEntry::TYPE_STORAGE || $this->type === DirectoryEntry::TYPE_ROOT;
    }

    public function read(int $offset, int $length): string
    {
        if (!$this->isStream() || $offset < 0 || $length < 0 || $offset > $this->size) {
            throw new \InvalidArgumentException('Invalid writable stream range.');
        }

        $length = min($length, $this->size - $offset);
        if ($this->contents !== null) {
            return substr($this->contents, $offset, $length);
        }
        if ($this->sourceFile !== null && $this->sourceEntry !== null) {
            return $this->sourceFile->readEntry($this->sourceEntry, $offset, $length);
        }
        if ($this->sourceReader !== null) {
            return $this->sourceReader->read($offset, $length);
        }

        return '';
    }

    public function rebindSource(CompoundFile $previous, CompoundFile $replacement): void
    {
        if ($this->sourceFile !== $previous || $this->sourceEntry === null) {
            return;
        }
        $entry = $replacement->findEntry($this->sourceEntry->getPath());
        if ($entry === null || !$entry->isStream()) {
            throw new \LogicException('Cannot rebind an imported compound stream.');
        }
        $this->sourceFile = $replacement;
        $this->sourceEntry = $entry;
    }
}
