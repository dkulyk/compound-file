<?php

declare(strict_types=1);

namespace DK\CompoundFile;

/** Immutable metadata for a storage or stream in a compound file. */
final class DirectoryEntry
{
    public const COLOR_RED = 0;
    public const COLOR_BLACK = 1;
    public const TYPE_STORAGE = 1;
    public const TYPE_STREAM = 2;
    public const TYPE_ROOT = 5;

    private int $id;
    private string $name;
    private string $path;
    private int $type;
    private int $startSector;
    private int $size;
    private CompoundFile $owner;
    private int $nameByteLength;
    private int $color;
    private string $classId;
    private int $stateBits;
    private ?\DateTimeImmutable $creationTime;
    private ?\DateTimeImmutable $modifiedTime;
    /** @internal */
    public int $leftId;
    /** @internal */
    public int $rightId;
    /** @internal */
    public int $childId;

    /** @internal */
    public function __construct(
        CompoundFile $owner,
        int $id,
        string $name,
        int $nameByteLength,
        int $type,
        int $color,
        int $leftId,
        int $rightId,
        int $childId,
        string $classId,
        int $stateBits,
        ?\DateTimeImmutable $creationTime,
        ?\DateTimeImmutable $modifiedTime,
        int $startSector,
        int $size,
    ) {
        $this->owner = $owner;
        $this->id = $id;
        $this->name = $name;
        $this->nameByteLength = $nameByteLength;
        $this->type = $type;
        $this->color = $color;
        $this->leftId = $leftId;
        $this->rightId = $rightId;
        $this->childId = $childId;
        $this->startSector = $startSector;
        $this->size = $size;
        $this->classId = $classId;
        $this->stateBits = $stateBits;
        $this->creationTime = $creationTime;
        $this->modifiedTime = $modifiedTime;
        $this->path = $name;
    }

    /** Returns the zero-based directory entry identifier. */
    public function getId(): int
    {
        return $this->id;
    }
    /** Returns the decoded UTF-8 entry name. */
    public function getName(): string
    {
        return $this->name;
    }
    /** Returns the encoded name length in bytes, including its UTF-16 terminator. */
    public function getNameByteLength(): int
    {
        return $this->nameByteLength;
    }
    /** Returns the red/black directory-tree color. */
    public function getColor(): int
    {
        return $this->color;
    }
    /** Returns the storage CLSID as a canonical UUID string. */
    public function getClassId(): string
    {
        return $this->classId;
    }
    /** Returns application-defined storage state bits. */
    public function getStateBits(): int
    {
        return $this->stateBits;
    }
    /** Returns the creation FILETIME converted to UTC, or null when unset. */
    public function getCreationTime(): ?\DateTimeImmutable
    {
        return $this->creationTime;
    }
    /** Returns the modification FILETIME converted to UTC, or null when unset. */
    public function getModifiedTime(): ?\DateTimeImmutable
    {
        return $this->modifiedTime;
    }
    /** Returns the raw left-sibling directory identifier. */
    public function getLeftSiblingId(): int
    {
        return $this->leftId;
    }
    /** Returns the raw right-sibling directory identifier. */
    public function getRightSiblingId(): int
    {
        return $this->rightId;
    }
    /** Returns the raw child directory identifier. */
    public function getChildId(): int
    {
        return $this->childId;
    }
    /** Returns the left sibling, or null when absent. */
    public function getLeftSibling(): ?self
    {
        return $this->owner->getEntryById($this->leftId);
    }
    /** Returns the right sibling, or null when absent. */
    public function getRightSibling(): ?self
    {
        return $this->owner->getEntryById($this->rightId);
    }
    /** Returns the root child entry, or null when absent. */
    public function getChild(): ?self
    {
        return $this->owner->getEntryById($this->childId);
    }
    /** Returns the slash-separated path from the root storage. */
    public function getPath(): string
    {
        return $this->path;
    }
    /** Returns one of the TYPE_* constants. */
    public function getType(): int
    {
        return $this->type;
    }
    /** Returns the stream size in bytes. */
    public function getSize(): int
    {
        return $this->size;
    }
    /** Returns true for normal stream entries. */
    public function isStream(): bool
    {
        return $this->type === self::TYPE_STREAM;
    }
    /** Returns true for storage and root entries. */
    public function isStorage(): bool
    {
        return $this->type === self::TYPE_STORAGE || $this->type === self::TYPE_ROOT;
    }
    /** @internal */ public function getStartSector(): int
    {
        return $this->startSector;
    }
    /** @internal */ public function setPath(string $path): void
    {
        $this->path = $path;
    }
}
