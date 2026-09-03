<?php

declare(strict_types=1);

namespace DK\CompoundFile;

use DK\CompoundFile\Exception\CfbfException;
use DK\CompoundFile\Internal\PathNormalizer;
use DK\CompoundFile\Internal\RandomAccessReader;
use DK\CompoundFile\Internal\WritableEntry;
use DK\CompoundFile\Internal\WritableTreeNode;

/**
 * Creates or rewrites an OLE2 Compound File Binary Format (CFBF) container.
 *
 * The writer builds a fresh allocation layout when saved. Opening an existing
 * file imports its directory and metadata, while unchanged stream contents are
 * copied lazily from the source container.
 */
final class CompoundFileWriter
{
    private const FREE = 0xFFFFFFFF;
    private const END = 0xFFFFFFFE;
    private const FAT = 0xFFFFFFFD;
    private const DIFAT = 0xFFFFFFFC;
    private const NONE = 0xFFFFFFFF;
    private const MINI_CUTOFF = 4096;
    private const MINI_SECTOR_SIZE = 64;
    private const COPY_BLOCK_SIZE = 1_048_576;

    private int $majorVersion;
    private bool $littleEndian;
    /** @var array<string, WritableEntry> */
    private array $entries = [];
    private ?CompoundFile $ownedSource = null;
    private ?string $sourcePath = null;

    private function __construct(int $majorVersion, bool $littleEndian)
    {
        if ($majorVersion !== 3 && $majorVersion !== 4) {
            throw new \InvalidArgumentException('CFBF major version must be 3 or 4.');
        }
        $this->majorVersion = $majorVersion;
        $this->littleEndian = $littleEndian;
        $this->entries[''] = WritableEntry::root();
    }

    /** Creates an empty compound file model. */
    public static function create(
        int $majorVersion = 3,
        string $byteOrder = Header::LITTLE_ENDIAN,
    ): self {
        if ($byteOrder !== Header::LITTLE_ENDIAN && $byteOrder !== Header::BIG_ENDIAN) {
            throw new \InvalidArgumentException('Byte order must be Header::LITTLE_ENDIAN or Header::BIG_ENDIAN.');
        }

        return new self($majorVersion, $byteOrder === Header::LITTLE_ENDIAN);
    }

    /** Opens an existing compound file as a mutable writer model. */
    public static function open(string $path): self
    {
        $file = CompoundFile::open($path);
        $writer = self::fromCompoundFile($file);
        $writer->ownedSource = $file;
        $writer->sourcePath = realpath($path) ?: $path;

        return $writer;
    }

    /**
     * Imports a compound file from a seekable PHP stream.
     *
     * @param resource $resource The caller retains ownership and must keep the resource open until saving.
     */
    public static function fromResource($resource): self
    {
        return self::fromCompoundFile(CompoundFile::fromResource($resource));
    }

    /** Imports an already parsed compound file without eagerly copying its streams. */
    public static function fromCompoundFile(CompoundFile $file): self
    {
        $writer = new self($file->getMajorVersion(), $file->getHeader()->isLittleEndian());
        $writer->entries = [];
        foreach ($file->getEntries() as $entry) {
            $writer->entries[$writer->normalizePath($entry->getPath())] = WritableEntry::imported($file, $entry);
        }

        return $writer;
    }

    /** Returns true when a stream or storage exists at the supplied path. */
    public function hasEntry(string $path): bool
    {
        return isset($this->entries[$this->normalizePath($path)]);
    }

    /** @return list<string> All entry paths, including the empty root path. */
    public function getEntryPaths(): array
    {
        return array_map(
            static fn (WritableEntry $entry): string => $entry->path,
            $this->orderedEntries(),
        );
    }

    /** Creates a storage and any missing parent storages. */
    public function createStorage(string $path): self
    {
        $parts = $this->pathParts($path);
        $current = '';
        foreach ($parts as $part) {
            $next = $current === '' ? $part : $current.'/'.$part;
            $key = $this->normalizePath($next);
            if (isset($this->entries[$key])) {
                if (!$this->entries[$key]->isStorage()) {
                    throw new CfbfException(sprintf('Cannot create storage "%s": a stream exists at that path.', $next));
                }
            } else {
                $this->entries[$key] = WritableEntry::storage($next, $part);
            }
            $current = $next;
        }

        return $this;
    }

    /** Creates or replaces a stream. Its parent storage must already exist. */
    public function setStreamContents(string $path, string $contents): self
    {
        [$canonicalPath, $name, $normalized] = $this->streamTarget($path);
        $this->entries[$normalized] = WritableEntry::stream($canonicalPath, $name, $contents);

        return $this;
    }

    /**
     * Creates or replaces a stream backed by an existing seekable resource.
     *
     * The complete resource, starting at offset zero, is used. Ownership stays
     * with the caller and the resource must remain open until the writer saves.
     *
     * @param resource $resource Seekable stream with a known size.
     */
    public function setStreamResource(string $path, $resource): self
    {
        [$canonicalPath, $name, $normalized] = $this->streamTarget($path);
        $this->entries[$normalized] = WritableEntry::resource(
            $canonicalPath,
            $name,
            RandomAccessReader::wrap($resource),
        );

        return $this;
    }

    /** Changes the CLSID of a stream or storage. */
    public function setClassId(string $path, string $classId): self
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $classId)) {
            throw new \InvalidArgumentException(sprintf('Invalid storage CLSID "%s".', $classId));
        }
        $this->entry($path)->classId = strtolower($classId);

        return $this;
    }

    /** Changes the application-defined state bits of a stream or storage. */
    public function setStateBits(string $path, int $stateBits): self
    {
        if ($stateBits < 0 || $stateBits > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('State bits must be an unsigned 32-bit integer.');
        }
        $this->entry($path)->stateBits = $stateBits;

        return $this;
    }

    /** Changes the creation and modification FILETIME metadata of an entry. */
    public function setTimestamps(
        string $path,
        ?\DateTimeImmutable $creationTime,
        ?\DateTimeImmutable $modifiedTime,
    ): self {
        $entry = $this->entry($path);
        $entry->creationTime = $creationTime;
        $entry->modifiedTime = $modifiedTime;
        $entry->creationFileTimeTicks = null;
        $entry->modifiedFileTimeTicks = null;

        return $this;
    }

    /** Removes a stream or a storage subtree. Returns false when it does not exist. */
    public function remove(string $path): bool
    {
        $key = $this->normalizePath($path);
        if ($key === '') {
            throw new \InvalidArgumentException('The root storage cannot be removed.');
        }
        if (!isset($this->entries[$key])) {
            return false;
        }
        foreach (array_keys($this->entries) as $candidate) {
            if ($candidate === $key || str_starts_with($candidate, $key.'/')) {
                unset($this->entries[$candidate]);
            }
        }

        return true;
    }

    /** Atomically writes the model to a filesystem path. */
    public function save(string $path): void
    {
        $directory = dirname($path);
        $permissions = is_file($path) ? @fileperms($path) : false;
        $temporary = @tempnam($directory, '.compound-file-');
        if ($temporary === false) {
            throw new CfbfException(sprintf('Cannot create a temporary file in "%s".', $directory));
        }
        $resource = @fopen($temporary, 'w+b');
        if ($resource === false) {
            @unlink($temporary);
            throw new CfbfException(sprintf('Cannot open temporary file "%s".', $temporary));
        }

        try {
            $this->write($resource);
            if (!fflush($resource)) {
                throw new CfbfException('Cannot flush the compound file.');
            }
            $targetPermissions = $permissions !== false ? $permissions & 0o777 : 0o666 & ~umask();
            if (!@chmod($temporary, $targetPermissions)) {
                throw new CfbfException(sprintf('Cannot set permissions for compound file "%s".', $path));
            }
            if (!fsync($resource)) {
                throw new CfbfException('Cannot synchronize the compound file with storage.');
            }
            fclose($resource);
            $resource = null;
            $closedSource = $this->closeSourceForReplacement($path);
            if (!@rename($temporary, $path)) {
                $this->restoreClosedSource($closedSource);
                throw new CfbfException(sprintf('Cannot replace compound file "%s".', $path));
            }
            if ($closedSource !== null) {
                $this->replaceEntriesFrom(CompoundFile::open($path));
                $this->sourcePath = realpath($path) ?: $path;
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Writes the model to an open seekable PHP stream and leaves it open.
     *
     * @param resource $resource Writable, seekable stream.
     */
    public function saveToResource($resource): void
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('Expected a PHP stream resource.');
        }
        $metadata = stream_get_meta_data($resource);
        if (empty($metadata['seekable']) || @fseek($resource, 0) !== 0 || !@ftruncate($resource, 0)) {
            throw new CfbfException('The output must be a writable, seekable stream.');
        }
        $this->write($resource);
        if (!fflush($resource)) {
            throw new CfbfException('Cannot flush the compound file.');
        }
    }

    /** @param resource $resource */
    private function write($resource): void
    {
        $sectorSize = $this->majorVersion === 3 ? 512 : 4096;
        $entriesPerFatSector = intdiv($sectorSize, 4);
        $orderedEntries = $this->orderedEntries();
        if ($this->majorVersion === 3) {
            foreach ($orderedEntries as $entry) {
                if ($entry->isStream() && $entry->getSize() > 0x7FFFFFFF) {
                    throw new CfbfException('CFBF version 3 streams cannot exceed 2 GiB.');
                }
            }
        }
        $directorySectorCount = max(1, intdiv(count($orderedEntries) * 128 + $sectorSize - 1, $sectorSize));

        $miniStreams = [];
        $regularStreams = [];
        $miniSectorCount = 0;
        foreach ($orderedEntries as $id => $entry) {
            if (!$entry->isStream() || $entry->getSize() === 0) {
                continue;
            }
            if ($entry->getSize() < self::MINI_CUTOFF) {
                $count = intdiv($entry->getSize() + self::MINI_SECTOR_SIZE - 1, self::MINI_SECTOR_SIZE);
                $miniStreams[$id] = ['start' => $miniSectorCount, 'count' => $count, 'entry' => $entry];
                $miniSectorCount += $count;
            } else {
                $regularStreams[$id] = [
                    'count' => intdiv($entry->getSize() + $sectorSize - 1, $sectorSize),
                    'entry' => $entry,
                ];
            }
        }

        $miniFatSectorCount = $miniSectorCount === 0 ? 0 : intdiv($miniSectorCount * 4 + $sectorSize - 1, $sectorSize);
        $miniStreamSize = $miniSectorCount * self::MINI_SECTOR_SIZE;
        $miniStreamSectorCount = $miniStreamSize === 0 ? 0 : intdiv($miniStreamSize + $sectorSize - 1, $sectorSize);
        $baseSectorCount = $directorySectorCount + $miniFatSectorCount + $miniStreamSectorCount;
        foreach ($regularStreams as $stream) {
            $baseSectorCount += $stream['count'];
        }

        [$fatSectorCount, $difatSectorCount] = $this->allocationSectorCounts($baseSectorCount, $entriesPerFatSector);
        $nextSector = 0;
        $directoryStart = $nextSector;
        $nextSector += $directorySectorCount;
        $miniFatStart = $miniFatSectorCount === 0 ? self::END : $nextSector;
        $nextSector += $miniFatSectorCount;
        $miniStreamStart = $miniStreamSectorCount === 0 ? self::END : $nextSector;
        $nextSector += $miniStreamSectorCount;
        foreach ($regularStreams as $id => $stream) {
            $regularStreams[$id]['start'] = $nextSector;
            $nextSector += $stream['count'];
        }
        $difatStart = $difatSectorCount === 0 ? self::END : $nextSector;
        $difatSectors = $difatSectorCount === 0 ? [] : range($nextSector, $nextSector + $difatSectorCount - 1);
        $nextSector += $difatSectorCount;
        $fatSectors = range($nextSector, $nextSector + $fatSectorCount - 1);
        $totalSectorCount = $nextSector + $fatSectorCount;

        $fat = array_fill(0, $fatSectorCount * $entriesPerFatSector, self::FREE);
        $this->markChain($fat, $directoryStart, $directorySectorCount);
        if ($miniFatSectorCount > 0) {
            $this->markChain($fat, $miniFatStart, $miniFatSectorCount);
        }
        if ($miniStreamSectorCount > 0) {
            $this->markChain($fat, $miniStreamStart, $miniStreamSectorCount);
        }
        foreach ($regularStreams as $stream) {
            $this->markChain($fat, $stream['start'], $stream['count']);
        }
        foreach ($difatSectors as $sector) {
            $fat[$sector] = self::DIFAT;
        }
        foreach ($fatSectors as $sector) {
            $fat[$sector] = self::FAT;
        }

        $tree = $this->directoryTree($orderedEntries);
        $streamLocations = [];
        foreach ($miniStreams as $id => $stream) {
            $streamLocations[$id] = ['start' => $stream['start'], 'size' => $stream['entry']->getSize()];
        }
        foreach ($regularStreams as $id => $stream) {
            $streamLocations[$id] = ['start' => $stream['start'], 'size' => $stream['entry']->getSize()];
        }
        $streamLocations[0] = ['start' => $miniStreamStart, 'size' => $miniStreamSize];

        $header = $this->header(
            $sectorSize,
            $directorySectorCount,
            $fatSectors,
            $directoryStart,
            $miniFatStart,
            $miniFatSectorCount,
            $difatStart,
            $difatSectorCount,
        );
        $this->writeAll($resource, str_pad($header, $sectorSize, "\0"));

        $directory = '';
        foreach ($orderedEntries as $id => $entry) {
            $directory .= $this->directoryEntry($entry, $tree[$id], $streamLocations[$id] ?? ['start' => self::END, 'size' => 0]);
        }
        $this->writeAll($resource, str_pad($directory, $directorySectorCount * $sectorSize, "\0"));

        if ($miniFatSectorCount > 0) {
            $miniFat = array_fill(0, $miniFatSectorCount * $entriesPerFatSector, self::FREE);
            foreach ($miniStreams as $stream) {
                $this->markChain($miniFat, $stream['start'], $stream['count']);
            }
            $this->writeAll($resource, $this->packUInt32Array(array_values($miniFat)));
        }

        if ($miniStreamSectorCount > 0) {
            $buffer = '';
            foreach ($miniStreams as $stream) {
                $entry = $stream['entry'];
                for ($offset = 0; $offset < $entry->getSize(); $offset += self::MINI_SECTOR_SIZE) {
                    $buffer .= str_pad($entry->read($offset, self::MINI_SECTOR_SIZE), self::MINI_SECTOR_SIZE, "\0");
                    if (strlen($buffer) >= $sectorSize) {
                        $this->writeAll($resource, substr($buffer, 0, $sectorSize));
                        $buffer = substr($buffer, $sectorSize);
                    }
                }
            }
            if ($buffer !== '') {
                $this->writeAll($resource, str_pad($buffer, $sectorSize, "\0"));
            }
        }

        foreach ($regularStreams as $stream) {
            $entry = $stream['entry'];
            for ($offset = 0; $offset < $entry->getSize(); $offset += self::COPY_BLOCK_SIZE) {
                $this->writeAll($resource, $entry->read($offset, self::COPY_BLOCK_SIZE));
            }
            $padding = ($sectorSize - $entry->getSize() % $sectorSize) % $sectorSize;
            if ($padding > 0) {
                $this->writeAll($resource, str_repeat("\0", $padding));
            }
        }

        if ($difatSectorCount > 0) {
            $remainingFatSectors = array_slice($fatSectors, 109);
            $capacity = $entriesPerFatSector - 1;
            foreach ($difatSectors as $index => $sector) {
                $values = array_splice($remainingFatSectors, 0, $capacity);
                $values = array_pad($values, $capacity, self::FREE);
                $values[] = $difatSectors[$index + 1] ?? self::END;
                $this->writeAll($resource, $this->packUInt32Array($values));
            }
        }
        $this->writeAll($resource, $this->packUInt32Array(array_values($fat)));

        $expectedSize = $sectorSize * (1 + $totalSectorCount);
        $position = ftell($resource);
        if ($position !== $expectedSize) {
            throw new CfbfException(sprintf('Writer produced %d bytes; expected %d.', $position, $expectedSize));
        }
    }

    /** @return list<WritableEntry> */
    private function orderedEntries(): array
    {
        if (!isset($this->entries[''])) {
            throw new CfbfException('Root storage is missing.');
        }
        $entries = $this->entries;
        $root = $entries[''];
        unset($entries['']);
        uasort($entries, fn (WritableEntry $left, WritableEntry $right): int => $this->compareNames($left->path, $right->path));

        return array_merge([$root], array_values($entries));
    }

    /**
     * @param list<WritableEntry> $entries
     * @return array<int, WritableTreeNode>
     */
    private function directoryTree(array $entries): array
    {
        $tree = [];
        $idsByParent = [];
        foreach ($entries as $id => $entry) {
            $tree[$id] = new WritableTreeNode();
            if ($id === 0) {
                continue;
            }
            $parent = $this->parentPath($entry->path);
            $idsByParent[$this->normalizePath($parent)][] = $id;
        }
        $idByPath = [];
        foreach ($entries as $id => $entry) {
            $idByPath[$this->normalizePath($entry->path)] = $id;
        }
        foreach ($idsByParent as $parent => $ids) {
            if (!isset($idByPath[$parent])) {
                throw new CfbfException(sprintf('Parent storage "%s" is missing.', $parent));
            }
            $parentId = $idByPath[$parent];
            if (!$entries[$parentId]->isStorage()) {
                throw new CfbfException(sprintf('Entry "%s" cannot contain children.', $entries[$parentId]->path));
            }
            $tree[$parentId]->child = $this->buildRedBlackTree($ids, $entries, $tree);
        }

        return $tree;
    }

    /**
     * @param list<int> $ids
     * @param list<WritableEntry> $entries
     * @param array<int, WritableTreeNode> $tree
     */
    private function buildRedBlackTree(array $ids, array $entries, array &$tree): int
    {
        usort($ids, fn (int $left, int $right): int => $this->compareEntryNames($entries[$left], $entries[$right]));
        for ($index = 1, $count = count($ids); $index < $count; $index++) {
            if ($this->compareEntryNames($entries[$ids[$index - 1]], $entries[$ids[$index]]) === 0) {
                throw new CfbfException(sprintf(
                    'Sibling entries "%s" and "%s" have equivalent CFBF names.',
                    $entries[$ids[$index - 1]]->name,
                    $entries[$ids[$index]]->name,
                ));
            }
        }
        $root = self::NONE;
        foreach ($ids as $id) {
            $tree[$id]->left = self::NONE;
            $tree[$id]->right = self::NONE;
            $tree[$id]->parent = self::NONE;
            $tree[$id]->color = DirectoryEntry::COLOR_RED;
            $previous = self::NONE;
            $current = $root;
            while ($current !== self::NONE) {
                $previous = $current;
                $current = $this->compareEntryNames($entries[$id], $entries[$current]) < 0
                    ? $tree[$current]->left
                    : $tree[$current]->right;
            }
            $tree[$id]->parent = $previous;
            if ($previous === self::NONE) {
                $root = $id;
            } elseif ($this->compareEntryNames($entries[$id], $entries[$previous]) < 0) {
                $tree[$previous]->left = $id;
            } else {
                $tree[$previous]->right = $id;
            }
            $this->fixRedBlackInsertion($id, $root, $tree);
        }
        if ($root !== self::NONE) {
            $tree[$root]->color = DirectoryEntry::COLOR_BLACK;
        }

        return $root;
    }

    /**
     * @param array<int, WritableTreeNode> $tree
     */
    private function fixRedBlackInsertion(int $node, int &$root, array &$tree): void
    {
        while (
            $node !== $root
            && $tree[$node]->parent !== self::NONE
            && $tree[$tree[$node]->parent]->color === DirectoryEntry::COLOR_RED
        ) {
            $parentId = $tree[$node]->parent;
            $grandparent = $tree[$parentId]->parent;
            if ($parentId === $tree[$grandparent]->left) {
                $uncle = $tree[$grandparent]->right;
                if ($uncle !== self::NONE && $tree[$uncle]->color === DirectoryEntry::COLOR_RED) {
                    $tree[$parentId]->color = DirectoryEntry::COLOR_BLACK;
                    $tree[$uncle]->color = DirectoryEntry::COLOR_BLACK;
                    $tree[$grandparent]->color = DirectoryEntry::COLOR_RED;
                    $node = $grandparent;
                    continue;
                }
                if ($node === $tree[$parentId]->right) {
                    $node = $parentId;
                    $this->rotateLeft($node, $root, $tree);
                    $parentId = $tree[$node]->parent;
                    $grandparent = $tree[$parentId]->parent;
                }
                $tree[$parentId]->color = DirectoryEntry::COLOR_BLACK;
                $tree[$grandparent]->color = DirectoryEntry::COLOR_RED;
                $this->rotateRight($grandparent, $root, $tree);
            } else {
                $uncle = $tree[$grandparent]->left;
                if ($uncle !== self::NONE && $tree[$uncle]->color === DirectoryEntry::COLOR_RED) {
                    $tree[$parentId]->color = DirectoryEntry::COLOR_BLACK;
                    $tree[$uncle]->color = DirectoryEntry::COLOR_BLACK;
                    $tree[$grandparent]->color = DirectoryEntry::COLOR_RED;
                    $node = $grandparent;
                    continue;
                }
                if ($node === $tree[$parentId]->left) {
                    $node = $parentId;
                    $this->rotateRight($node, $root, $tree);
                    $parentId = $tree[$node]->parent;
                    $grandparent = $tree[$parentId]->parent;
                }
                $tree[$parentId]->color = DirectoryEntry::COLOR_BLACK;
                $tree[$grandparent]->color = DirectoryEntry::COLOR_RED;
                $this->rotateLeft($grandparent, $root, $tree);
            }
        }
        $tree[$root]->color = DirectoryEntry::COLOR_BLACK;
    }

    /** @param array<int, WritableTreeNode> $tree */
    private function rotateLeft(int $node, int &$root, array &$tree): void
    {
        $right = $tree[$node]->right;
        $tree[$node]->right = $tree[$right]->left;
        if ($tree[$right]->left !== self::NONE) {
            $tree[$tree[$right]->left]->parent = $node;
        }
        $tree[$right]->parent = $tree[$node]->parent;
        if ($tree[$node]->parent === self::NONE) {
            $root = $right;
        } elseif ($node === $tree[$tree[$node]->parent]->left) {
            $tree[$tree[$node]->parent]->left = $right;
        } else {
            $tree[$tree[$node]->parent]->right = $right;
        }
        $tree[$right]->left = $node;
        $tree[$node]->parent = $right;
    }

    /** @param array<int, WritableTreeNode> $tree */
    private function rotateRight(int $node, int &$root, array &$tree): void
    {
        $left = $tree[$node]->left;
        $tree[$node]->left = $tree[$left]->right;
        if ($tree[$left]->right !== self::NONE) {
            $tree[$tree[$left]->right]->parent = $node;
        }
        $tree[$left]->parent = $tree[$node]->parent;
        if ($tree[$node]->parent === self::NONE) {
            $root = $left;
        } elseif ($node === $tree[$tree[$node]->parent]->right) {
            $tree[$tree[$node]->parent]->right = $left;
        } else {
            $tree[$tree[$node]->parent]->left = $left;
        }
        $tree[$left]->right = $node;
        $tree[$node]->parent = $left;
    }

    /** @return array{int, int} */
    private function allocationSectorCounts(int $baseSectorCount, int $entriesPerFatSector): array
    {
        $fat = 0;
        $difat = 0;
        do {
            $previousFat = $fat;
            $previousDifat = $difat;
            $fat = intdiv($baseSectorCount + $fat + $difat + $entriesPerFatSector - 1, $entriesPerFatSector);
            $difat = $fat <= 109 ? 0 : intdiv($fat - 109 + ($entriesPerFatSector - 2), $entriesPerFatSector - 1);
        } while ($fat !== $previousFat || $difat !== $previousDifat);

        return [$fat, $difat];
    }

    /** @param array<int, int> $table */
    private function markChain(array &$table, int $start, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $table[$start + $index] = $index + 1 < $count ? $start + $index + 1 : self::END;
        }
    }

    /** @param list<int> $fatSectors */
    private function header(
        int $sectorSize,
        int $directorySectorCount,
        array $fatSectors,
        int $directoryStart,
        int $miniFatStart,
        int $miniFatSectorCount,
        int $difatStart,
        int $difatSectorCount,
    ): string {
        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 16);
        $header .= $this->u16(0x003E).$this->u16($this->majorVersion);
        $header .= $this->littleEndian ? "\xFE\xFF" : "\xFF\xFE";
        $header .= $this->u16($sectorSize === 512 ? 9 : 12).$this->u16(6).str_repeat("\0", 6);
        $header .= $this->u32($this->majorVersion === 4 ? $directorySectorCount : 0);
        $header .= $this->u32(count($fatSectors)).$this->u32($directoryStart).$this->u32(0);
        $header .= $this->u32(self::MINI_CUTOFF).$this->u32($miniFatStart).$this->u32($miniFatSectorCount);
        $header .= $this->u32($difatStart).$this->u32($difatSectorCount);
        for ($index = 0; $index < 109; $index++) {
            $header .= $this->u32($fatSectors[$index] ?? self::FREE);
        }

        return $header;
    }

    /**
     * @param array{start: int, size: int} $location
     */
    private function directoryEntry(WritableEntry $entry, WritableTreeNode $tree, array $location): string
    {
        $encodedName = mb_convert_encoding(
            $entry->name,
            $this->littleEndian ? 'UTF-16LE' : 'UTF-16BE',
            'UTF-8',
        )."\0\0";
        if (strlen($encodedName) > 64) {
            throw new CfbfException(sprintf('Directory entry name "%s" exceeds 31 UTF-16 code units.', $entry->name));
        }
        $size = $location['size'];
        $sizeHigh = $this->majorVersion === 4 ? intdiv($size, 4294967296) : 0;
        $sizeLow = $size % 4294967296;

        return str_pad($encodedName, 64, "\0")
            .$this->u16(strlen($encodedName))
            .pack('C2', $entry->type, $tree->color)
            .$this->u32($tree->left).$this->u32($tree->right).$this->u32($tree->child)
            .$this->encodeClassId($entry->classId)
            .$this->u32($entry->stateBits)
            .$this->encodeFileTime($entry->creationTime, $entry->creationFileTimeTicks)
            .$this->encodeFileTime($entry->modifiedTime, $entry->modifiedFileTimeTicks)
            .$this->u32($location['start']).$this->u32($sizeLow).$this->u32($sizeHigh);
    }

    private function encodeClassId(string $classId): string
    {
        if (!preg_match('/^([0-9a-f]{8})-([0-9a-f]{4})-([0-9a-f]{4})-([0-9a-f]{4})-([0-9a-f]{12})$/i', $classId, $parts)) {
            throw new CfbfException(sprintf('Invalid storage CLSID "%s".', $classId));
        }

        return $this->u32((int) hexdec($parts[1]))
            .$this->u16((int) hexdec($parts[2]))
            .$this->u16((int) hexdec($parts[3]))
            .hex2bin($parts[4].$parts[5]);
    }

    private function encodeFileTime(?\DateTimeImmutable $time, ?int $originalTicks): string
    {
        if ($time === null) {
            return str_repeat("\0", 8);
        }
        if ($originalTicks !== null) {
            return $this->u32($originalTicks % 4294967296).$this->u32(intdiv($originalTicks, 4294967296));
        }
        if ($time->getTimestamp() < -11_644_473_600) {
            throw new CfbfException('CFBF timestamps cannot be earlier than 1601-01-01 UTC.');
        }
        $ticks = ($time->getTimestamp() + 11_644_473_600) * 10_000_000 + (int) $time->format('u') * 10;

        return $this->u32($ticks % 4294967296).$this->u32(intdiv($ticks, 4294967296));
    }

    private function compareEntryNames(WritableEntry $left, WritableEntry $right): int
    {
        return $this->compareNames($left->name, $right->name);
    }

    private function compareNames(string $left, string $right): int
    {
        $leftUpper = PathNormalizer::fold($left);
        $rightUpper = PathNormalizer::fold($right);
        $leftUtf16 = mb_convert_encoding($leftUpper, 'UTF-16BE', 'UTF-8');
        $rightUtf16 = mb_convert_encoding($rightUpper, 'UTF-16BE', 'UTF-8');
        $length = strlen($leftUtf16) <=> strlen($rightUtf16);

        return $length !== 0 ? $length : strcmp($leftUtf16, $rightUtf16);
    }

    /** @return list<string> */
    private function pathParts(string $path): array
    {
        $path = str_replace('\\', '/', trim($path, '/\\'));
        if ($path === '') {
            return [];
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \InvalidArgumentException(sprintf('Invalid compound file path "%s".', $path));
            }
            if (!mb_check_encoding($part, 'UTF-8') || str_contains($part, "\0") || strpbrk($part, ':!') !== false) {
                throw new \InvalidArgumentException(sprintf('Directory entry name "%s" contains a reserved character.', $part));
            }
            $encoded = mb_convert_encoding($part, 'UTF-16LE', 'UTF-8');
            if (strlen($encoded) > 62) {
                throw new \InvalidArgumentException(sprintf('Directory entry name "%s" exceeds 31 UTF-16 code units.', $part));
            }
        }

        return $parts;
    }

    /** @return array{string, string, string} */
    private function streamTarget(string $path): array
    {
        $parts = $this->pathParts($path);
        $name = array_pop($parts);
        if ($name === null) {
            throw new \InvalidArgumentException('A stream path cannot be empty.');
        }
        $parent = implode('/', $parts);
        $parentKey = $this->normalizePath($parent);
        if (!isset($this->entries[$parentKey]) || !$this->entries[$parentKey]->isStorage()) {
            throw new CfbfException(sprintf('Parent storage "%s" does not exist.', $parent));
        }
        $normalized = $this->normalizePath($path);
        if (isset($this->entries[$normalized]) && $this->entries[$normalized]->isStorage()) {
            throw new CfbfException(sprintf('Cannot replace storage "%s" with a stream.', $path));
        }

        return [$parent === '' ? $name : $parent.'/'.$name, $name, $normalized];
    }

    private function entry(string $path): WritableEntry
    {
        $key = $this->normalizePath($path);
        if (!isset($this->entries[$key])) {
            throw new CfbfException(sprintf('Entry "%s" does not exist.', $path));
        }

        return $this->entries[$key];
    }

    private function closeSourceForReplacement(string $path): ?CompoundFile
    {
        if ($this->ownedSource === null || $this->sourcePath === null) {
            return null;
        }
        $target = realpath($path) ?: $path;
        $samePath = PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($target, $this->sourcePath) === 0
            : $target === $this->sourcePath;
        if (!$samePath) {
            return null;
        }
        $source = $this->ownedSource;
        $source->close();
        $this->ownedSource = null;

        return $source;
    }

    private function restoreClosedSource(?CompoundFile $closedSource): void
    {
        if ($closedSource === null || $this->sourcePath === null) {
            return;
        }
        $replacement = CompoundFile::open($this->sourcePath);
        foreach ($this->entries as $entry) {
            $entry->rebindSource($closedSource, $replacement);
        }
        $this->ownedSource = $replacement;
    }

    private function replaceEntriesFrom(CompoundFile $file): void
    {
        $this->entries = [];
        foreach ($file->getEntries() as $entry) {
            $this->entries[$this->normalizePath($entry->getPath())] = WritableEntry::imported($file, $entry);
        }
        $this->ownedSource = $file;
    }

    private function parentPath(string $path): string
    {
        $position = strrpos($path, '/');

        return $position === false ? '' : substr($path, 0, $position);
    }

    private function normalizePath(string $path): string
    {
        return PathNormalizer::normalize($path);
    }

    private function u16(int $value): string
    {
        return pack($this->littleEndian ? 'v' : 'n', $value);
    }

    private function u32(int $value): string
    {
        return pack($this->littleEndian ? 'V' : 'N', $value);
    }

    /** @param list<int> $values */
    private function packUInt32Array(array $values): string
    {
        if ($values === []) {
            return '';
        }

        return pack(($this->littleEndian ? 'V' : 'N').'*', ...$values);
    }

    /** @param resource $resource */
    private function writeAll($resource, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($resource, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new CfbfException('Cannot write the compound file.');
            }
            $offset += $written;
        }
    }
}
