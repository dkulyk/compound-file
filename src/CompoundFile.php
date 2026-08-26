<?php

declare(strict_types=1);

namespace DK\CompoundFile;

use DK\CompoundFile\Exception\CfbfException;
use DK\CompoundFile\Internal\RandomAccessReader;

/**
 * Read-only parser for an OLE2 Compound File Binary Format (CFBF) container.
 *
 * Use {@see open()} for a file path or {@see fromResource()} for an existing
 * seekable PHP stream. The object owns resources it opens, but never closes a
 * resource supplied by the caller.
 */
final class CompoundFile
{
    private const FREE = 0xFFFFFFFF;
    private const END = 0xFFFFFFFE;
    private const FAT = 0xFFFFFFFD;
    private const DIFAT = 0xFFFFFFFC;
    private const NONE = 0xFFFFFFFF;

    private RandomAccessReader $reader;
    private Header $header;
    private bool $littleEndian;
    private int $majorVersion;
    private int $sectorSize;
    private int $miniSectorSize;
    private int $miniCutoff;
    /** @var list<int> */
    private array $fat = [];
    /** @var list<int> */
    private array $difat = [];
    /** @var list<int> */
    private array $miniFat = [];
    /** @var array<int, DirectoryEntry> */
    private array $entries = [];
    /** @var array<string, DirectoryEntry> */
    private array $entriesByPath = [];
    private ?DirectoryEntry $root = null;
    private string $miniStream = '';

    private function __construct(RandomAccessReader $reader)
    {
        $this->reader = $reader;
        $this->parse();
    }

    /** Opens and parses a compound file from a filesystem path. */
    public static function open(string $path): self
    {
        return new self(RandomAccessReader::open($path));
    }

    /**
     * Parses a compound file from a seekable PHP stream.
     *
     * @param resource $resource Open seekable stream; ownership stays with the caller.
     */
    public static function fromResource($resource): self
    {
        return new self(RandomAccessReader::wrap($resource));
    }

    /** Returns the CFB major version (3 or 4). */
    public function getMajorVersion(): int
    {
        return $this->majorVersion;
    }

    /** Returns immutable metadata parsed from the CFBF header. */
    public function getHeader(): Header
    {
        return $this->header;
    }

    /** Returns a diagnostic snapshot of the DIFAT, FAT, and mini-FAT tables. */
    public function getAllocationTable(): AllocationTable
    {
        return new AllocationTable($this->difat, $this->fat, $this->miniFat);
    }

    /** Finds a directory entry by its raw numeric identifier. */
    public function getEntryById(int $id): ?DirectoryEntry
    {
        return $this->entries[$id] ?? null;
    }

    /** Returns all non-empty directory entries, including the root entry.
     * @return list<DirectoryEntry>
     */
    public function getEntries(): array
    {
        return array_values($this->entries);
    }

    /** Finds an entry by its case-insensitive slash-separated path, or returns null. */
    public function findEntry(string $path): ?DirectoryEntry
    {
        $key = $this->normalizePath($path);
        return $this->entriesByPath[$key] ?? null;
    }

    /** Returns true when a stream exists at the supplied path. */
    public function hasStream(string $path): bool
    {
        $entry = $this->findEntry($path);
        return $entry !== null && $entry->isStream();
    }

    /**
     * Returns the direct children of a storage.
     *
     * Pass an empty path for the root storage.
     *
     * @return list<DirectoryEntry>
     */
    public function getChildren(string $storagePath = ''): array
    {
        $storage = $this->findEntry($storagePath);
        if ($storage === null || !$storage->isStorage()) {
            throw new CfbfException(sprintf('Storage "%s" does not exist.', $storagePath));
        }

        $normalizedParent = $this->normalizePath($storagePath);
        $children = [];
        foreach ($this->entries as $entry) {
            if ($entry === $this->root) {
                continue;
            }
            $entryPath = $this->normalizePath($entry->getPath());
            $separator = strrpos($entryPath, '/');
            $parent = $separator === false ? '' : substr($entryPath, 0, $separator);
            if ($parent === $normalizedParent) {
                $children[] = $entry;
            }
        }

        return $children;
    }

    /** Opens a named stream for incremental, seekable reading. */
    public function openStream(string|DirectoryEntry $path): Stream
    {
        $entry = $path instanceof DirectoryEntry ? $path : $this->findEntry($path);
        if ($entry === null || !$entry->isStream()) {
            $description = $path instanceof DirectoryEntry ? $path->getPath() : $path;
            throw new CfbfException(sprintf('Stream "%s" does not exist.', $description));
        }
        return new Stream($this, $entry);
    }

    /** Returns the complete contents of a named stream. */
    public function getStreamContents(string|DirectoryEntry $path): string
    {
        return $this->openStream($path)->getContents();
    }

    /** @internal Reads a byte range from a directory stream. */
    public function readEntry(DirectoryEntry $entry, int $offset, int $length): string
    {
        $size = $entry->getSize();
        if ($offset < 0 || $offset > $size) {
            throw new \InvalidArgumentException('Stream offset is outside the stream.');
        }
        $length = min($length, $size - $offset);
        if ($length <= 0) {
            return '';
        }
        if ($size < $this->miniCutoff) {
            return $this->readChainRange($this->miniStream, $this->miniFat, $this->miniSectorSize, $entry->getStartSector(), $offset, $length);
        }
        return $this->readRegularChainRange($entry->getStartSector(), $offset, $length);
    }

    private function parse(): void
    {
        $header = $this->reader->read(0, 512);
        if (substr($header, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new CfbfException('Invalid CFBF signature.');
        }
        $byteOrder = substr($header, 28, 2);
        if ($byteOrder === "\xFE\xFF") {
            $this->littleEndian = true;
        } elseif ($byteOrder === "\xFF\xFE") {
            $this->littleEndian = false;
        } else {
            throw new CfbfException('Invalid CFBF byte-order marker.');
        }

        $this->majorVersion = $this->u16($header, 26);
        $minorVersion = $this->u16($header, 24);
        $sectorShift = $this->u16($header, 30);
        if (($this->majorVersion !== 3 && $this->majorVersion !== 4) || ($sectorShift !== 9 && $sectorShift !== 12)) {
            throw new CfbfException('Unsupported CFBF version or sector size.');
        }
        $this->sectorSize = 1 << $sectorShift;
        $miniSectorShift = $this->u16($header, 32);
        $this->miniSectorSize = 1 << $miniSectorShift;
        $fatCount = $this->u32($header, 44);
        $directoryStart = $this->u32($header, 48);
        $transactionSignature = $this->u32($header, 52);
        $this->miniCutoff = $this->u32($header, 56);
        $miniFatStart = $this->u32($header, 60);
        $miniFatCount = $this->u32($header, 64);
        $difatStart = $this->u32($header, 68);
        $difatCount = $this->u32($header, 72);

        $this->header = new Header(
            $minorVersion,
            $this->majorVersion,
            $this->littleEndian ? Header::LITTLE_ENDIAN : Header::BIG_ENDIAN,
            $sectorShift,
            $miniSectorShift,
            $fatCount,
            $directoryStart,
            $transactionSignature,
            $this->miniCutoff,
            $miniFatStart,
            $miniFatCount,
            $difatStart,
            $difatCount,
        );

        $fatSectors = [];
        for ($i = 0; $i < 109; $i++) {
            $id = $this->u32($header, 76 + $i * 4);
            if ($id !== self::FREE) {
                $fatSectors[] = $id;
            }
        }
        $visited = [];
        for ($n = 0; $n < $difatCount && $difatStart !== self::END; $n++) {
            if (isset($visited[$difatStart])) {
                throw new CfbfException('Cycle in DIFAT chain.');
            }
            $visited[$difatStart] = true;
            $sector = $this->sector($difatStart);
            for ($i = 0; $i < $this->sectorSize / 4 - 1; $i++) {
                $id = $this->u32($sector, $i * 4);
                if ($id !== self::FREE) {
                    $fatSectors[] = $id;
                }
            }
            $difatStart = $this->u32($sector, $this->sectorSize - 4);
        }
        if (count($fatSectors) < $fatCount) {
            throw new CfbfException('DIFAT contains fewer FAT sectors than declared.');
        }
        $this->difat = array_slice($fatSectors, 0, $fatCount);
        foreach (array_slice($fatSectors, 0, $fatCount) as $fatSector) {
            foreach ($this->uint32Array($this->sector($fatSector)) as $value) {
                $this->fat[] = $value;
            }
        }

        if ($miniFatCount > 0) {
            $bytes = $this->readRegularChain($miniFatStart, $miniFatCount * $this->sectorSize);
            $this->miniFat = $this->uint32Array($bytes);
        }
        $this->parseDirectory($this->readRegularChain($directoryStart));
        if (!$this->root instanceof DirectoryEntry) {
            throw new CfbfException('Root directory entry is missing.');
        }
        $root = $this->root;
        if ($root->getSize() > 0) {
            $this->miniStream = $this->readRegularChain($root->getStartSector(), $root->getSize());
        }
        $this->indexDirectoryTree($root->childId, '', []);
        $root->setPath('');
        $this->entriesByPath[''] = $root;
    }

    private function parseDirectory(string $bytes): void
    {
        for ($offset = 0, $id = 0; $offset + 128 <= strlen($bytes); $offset += 128, $id++) {
            $type = ord($bytes[$offset + 66]);
            if ($type === 0) {
                continue;
            }
            $nameLength = $this->u16($bytes, $offset + 64);
            if ($nameLength < 2 || $nameLength > 64 || $nameLength % 2 !== 0) {
                throw new CfbfException('Invalid directory entry name length.');
            }
            $encoded = substr($bytes, $offset, $nameLength - 2);
            $name = mb_convert_encoding(
                $encoded,
                'UTF-8',
                $this->littleEndian ? 'UTF-16LE' : 'UTF-16BE'
            );
            $low = $this->u32($bytes, $offset + 120);
            $high = $this->u32($bytes, $offset + 124);
            $size = $this->majorVersion === 3 ? $low : $this->combine64($low, $high);
            $entry = new DirectoryEntry(
                $this,
                $id,
                $name,
                $nameLength,
                $type,
                ord($bytes[$offset + 67]),
                $this->u32($bytes, $offset + 68),
                $this->u32($bytes, $offset + 72),
                $this->u32($bytes, $offset + 76),
                $this->decodeClassId(substr($bytes, $offset + 80, 16)),
                $this->u32($bytes, $offset + 96),
                $this->decodeFileTime($bytes, $offset + 100),
                $this->decodeFileTime($bytes, $offset + 108),
                $this->u32($bytes, $offset + 116),
                $size,
            );
            $this->entries[$id] = $entry;
            if ($type === DirectoryEntry::TYPE_ROOT) {
                $this->root = $entry;
            }
        }
    }

    /** @param array<int, true> $ancestors */
    private function indexDirectoryTree(int $id, string $parent, array $ancestors): void
    {
        if ($id === self::NONE) {
            return;
        }
        if (isset($ancestors[$id])) {
            throw new CfbfException('Cycle in directory tree.');
        }
        if (!isset($this->entries[$id])) {
            throw new CfbfException('Directory tree references a missing entry.');
        }
        $ancestors[$id] = true;
        $entry = $this->entries[$id];
        $this->indexDirectoryTree($entry->leftId, $parent, $ancestors);
        $path = $parent === '' ? $entry->getName() : $parent . '/' . $entry->getName();
        $entry->setPath($path);
        $this->entriesByPath[$this->normalizePath($path)] = $entry;
        if ($entry->isStorage()) {
            $this->indexDirectoryTree($entry->childId, $path, $ancestors);
        }
        $this->indexDirectoryTree($entry->rightId, $parent, $ancestors);
    }

    private function readRegularChain(int $start, ?int $limit = null): string
    {
        $result = '';
        foreach ($this->chain($start, $this->fat) as $sector) {
            $result .= $this->sector($sector);
            if ($limit !== null && strlen($result) >= $limit) {
                return substr($result, 0, $limit);
            }
        }
        return $limit === null ? $result : substr($result, 0, $limit);
    }

    private function readRegularChainRange(int $start, int $offset, int $length): string
    {
        $result = '';
        $first = intdiv($offset, $this->sectorSize);
        $inside = $offset % $this->sectorSize;
        $index = 0;
        foreach ($this->chain($start, $this->fat) as $sector) {
            if ($index++ < $first) {
                continue;
            } $chunk = substr($this->sector($sector), $inside, $length - strlen($result));
            $result .= $chunk;
            $inside = 0;
            if (strlen($result) >= $length) {
                break;
            }
        }
        return $result;
    }

    /** @param array<int, int> $table */
    private function readChainRange(string $source, array $table, int $unitSize, int $start, int $offset, int $length): string
    {
        $result = '';
        $first = intdiv($offset, $unitSize);
        $inside = $offset % $unitSize;
        $index = 0;
        foreach ($this->chain($start, $table) as $unit) {
            if ($index++ < $first) {
                continue;
            } $result .= substr($source, $unit * $unitSize + $inside, $length - strlen($result));
            $inside = 0;
            if (strlen($result) >= $length) {
                break;
            }
        }
        return $result;
    }

    /** @param array<int, int> $table @return \Generator<int, int> */
    private function chain(int $start, array $table): \Generator
    {
        $seen = [];
        $current = $start;
        while ($current !== self::END) {
            if ($current === self::FREE || $current === self::FAT || $current === self::DIFAT || !isset($table[$current])) {
                throw new CfbfException('Invalid sector chain.');
            }
            if (isset($seen[$current])) {
                throw new CfbfException('Cycle in sector chain.');
            }
            $seen[$current] = true;
            yield $current;
            $current = $table[$current];
        }
    }

    private function sector(int $id): string
    {
        // In version 4 the 512-byte header is padded to one 4096-byte sector.
        $offset = $this->sectorSize + $id * $this->sectorSize;
        return $this->reader->read($offset, $this->sectorSize);
    }
    /** @return list<int> */
    private function uint32Array(string $bytes): array
    {
        $result = [];
        for ($i = 0;$i + 4 <= strlen($bytes);$i += 4) {
            $result[] = $this->u32($bytes, $i);
        } return $result;
    }
    private function u16(string $bytes, int $offset): int
    {
        $value = unpack($this->littleEndian ? 'v' : 'n', substr($bytes, $offset, 2));
        if ($value === false) {
            throw new CfbfException('Cannot decode a 16-bit integer.');
        }
        return $value[1];
    }
    private function u32(string $bytes, int $offset): int
    {
        $value = unpack($this->littleEndian ? 'V' : 'N', substr($bytes, $offset, 4));
        if ($value === false) {
            throw new CfbfException('Cannot decode a 32-bit integer.');
        }
        return $value[1];
    }
    private function combine64(int $low, int $high): int
    {
        if (PHP_INT_SIZE < 8 && $high !== 0) {
            throw new CfbfException('This stream size requires a 64-bit PHP build.');
        }
        if ($high > 0x7FFFFFFF) {
            throw new CfbfException('Stream size exceeds PHP integer range.');
        }
        return $high * 4294967296 + $low;
    }

    private function decodeClassId(string $bytes): string
    {
        if ($bytes === str_repeat("\0", 16)) {
            return '00000000-0000-0000-0000-000000000000';
        }
        $first = $this->u32($bytes, 0);
        $second = $this->u16($bytes, 4);
        $third = $this->u16($bytes, 6);
        return sprintf(
            '%08x-%04x-%04x-%s-%s',
            $first,
            $second,
            $third,
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }

    private function decodeFileTime(string $bytes, int $offset): ?\DateTimeImmutable
    {
        $low = $this->u32($bytes, $offset);
        $high = $this->u32($bytes, $offset + 4);
        if ($low === 0 && $high === 0) {
            return null;
        }
        $ticks = $this->combine64($low, $high);
        $unixSeconds = intdiv($ticks, 10_000_000) - 11_644_473_600;
        return new \DateTimeImmutable('@'.$unixSeconds);
    }
    private function normalizePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', trim($path, '/\\')));
    }
}
