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
    /** @var array<int, array{sectors: list<int>, seen: array<int, true>, complete: bool}> */
    private array $regularChainCache = [];
    /** @var array<int, array{sectors: list<int>, seen: array<int, true>, complete: bool}> */
    private array $miniChainCache = [];

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

    /** Releases the parser's filesystem handle; caller-owned resources stay open. */
    public function close(): void
    {
        $this->reader->close();
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

        $integer16 = $this->littleEndian ? 'v' : 'n';
        $versionFields = unpack(
            $integer16.'minorVersion/'.$integer16.'majorVersion/x2/'
            .$integer16.'sectorShift/'.$integer16.'miniSectorShift',
            $header,
            24,
        );
        if ($versionFields === false) {
            throw new CfbfException('Cannot decode the CFBF version fields.');
        }
        $this->majorVersion = $versionFields['majorVersion'];
        $minorVersion = $versionFields['minorVersion'];
        $sectorShift = $versionFields['sectorShift'];
        if (
            ($this->majorVersion !== 3 && $this->majorVersion !== 4)
            || ($this->majorVersion === 3 && $sectorShift !== 9)
            || ($this->majorVersion === 4 && $sectorShift !== 12)
        ) {
            throw new CfbfException('Unsupported CFBF version or sector size.');
        }
        $this->sectorSize = 1 << $sectorShift;
        $miniSectorShift = $versionFields['miniSectorShift'];
        if ($miniSectorShift !== 6) {
            throw new CfbfException('Unsupported CFBF mini-sector size.');
        }
        $this->miniSectorSize = 1 << $miniSectorShift;
        $headerValues = $this->uint32Array(substr($header, 44, 32));
        if (count($headerValues) !== 8) {
            throw new CfbfException('Cannot decode the CFBF header.');
        }
        [
            $fatCount,
            $directoryStart,
            $transactionSignature,
            $this->miniCutoff,
            $miniFatStart,
            $miniFatCount,
            $difatStart,
            $difatCount,
        ] = $headerValues;
        if ($this->miniCutoff !== 4096) {
            throw new CfbfException('Unsupported CFBF mini-stream cutoff.');
        }

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
        foreach ($this->uint32Array(substr($header, 76, 109 * 4)) as $id) {
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
            $difatEntries = $this->uint32Array($sector);
            $nextDifat = array_pop($difatEntries);
            foreach ($difatEntries as $id) {
                if ($id !== self::FREE) {
                    $fatSectors[] = $id;
                }
            }
            $difatStart = $nextDifat ?? self::END;
        }
        if (count($fatSectors) < $fatCount) {
            throw new CfbfException('DIFAT contains fewer FAT sectors than declared.');
        }
        $this->difat = array_slice($fatSectors, 0, $fatCount);
        $fatBytes = $this->readSectorRuns($this->difat, 0, $fatCount * $this->sectorSize);
        $this->fat = $this->uint32Array($fatBytes);

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
        $ancestors = [];
        $this->indexDirectoryTree($root->childId, '', $ancestors);
        $root->setPath('');
        $this->entriesByPath[''] = $root;
    }

    private function parseDirectory(string $bytes): void
    {
        $integer16 = $this->littleEndian ? 'v' : 'n';
        $integer32 = $this->littleEndian ? 'V' : 'N';
        $format = 'x64/'
            .$integer16.'nameLength/Ctype/Ccolor/'
            .$integer32.'leftId/'.$integer32.'rightId/'.$integer32.'childId/'
            .'a16classId/'
            .$integer32.'stateBits/'
            .$integer32.'creationLow/'.$integer32.'creationHigh/'
            .$integer32.'modifiedLow/'.$integer32.'modifiedHigh/'
            .$integer32.'startSector/'.$integer32.'sizeLow/'.$integer32.'sizeHigh';
        $length = strlen($bytes);

        for ($offset = 0, $id = 0; $offset + 128 <= $length; $offset += 128, $id++) {
            $fields = unpack($format, $bytes, $offset);
            if ($fields === false) {
                throw new CfbfException('Cannot decode a directory entry.');
            }
            $type = $fields['type'];
            if ($type === 0) {
                continue;
            }
            if (!in_array($type, [DirectoryEntry::TYPE_STORAGE, DirectoryEntry::TYPE_STREAM, DirectoryEntry::TYPE_ROOT], true)) {
                throw new CfbfException('Invalid directory entry type.');
            }
            if ($fields['color'] !== 0 && $fields['color'] !== 1) {
                throw new CfbfException('Invalid directory entry color.');
            }
            $nameLength = $fields['nameLength'];
            if ($nameLength < 2 || $nameLength > 64 || $nameLength % 2 !== 0) {
                throw new CfbfException('Invalid directory entry name length.');
            }
            $encoded = substr($bytes, $offset, $nameLength - 2);
            $name = mb_convert_encoding(
                $encoded,
                'UTF-8',
                $this->littleEndian ? 'UTF-16LE' : 'UTF-16BE'
            );
            $low = $fields['sizeLow'];
            $high = $fields['sizeHigh'];
            $size = $this->majorVersion === 3 ? $low : $this->combine64($low, $high);
            $entry = new DirectoryEntry(
                $this,
                $id,
                $name,
                $nameLength,
                $type,
                $fields['color'],
                $fields['leftId'],
                $fields['rightId'],
                $fields['childId'],
                $this->decodeClassId($fields['classId']),
                $fields['stateBits'],
                $this->decodeFileTime($fields['creationLow'], $fields['creationHigh']),
                $this->decodeFileTime($fields['modifiedLow'], $fields['modifiedHigh']),
                $fields['startSector'],
                $size,
            );
            $this->entries[$id] = $entry;
            if ($type === DirectoryEntry::TYPE_ROOT) {
                if ($this->root !== null) {
                    throw new CfbfException('Multiple root directory entries.');
                }
                $this->root = $entry;
            }
        }
    }

    /** @param array<int, true> $ancestors */
    private function indexDirectoryTree(int $id, string $parent, array &$ancestors): void
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
        $normalizedPath = $this->normalizePath($path);
        if (isset($this->entriesByPath[$normalizedPath])) {
            throw new CfbfException('Directory contains duplicate entry paths.');
        }
        $this->entriesByPath[$normalizedPath] = $entry;
        if ($entry->isStorage()) {
            $this->indexDirectoryTree($entry->childId, $path, $ancestors);
        }
        $this->indexDirectoryTree($entry->rightId, $parent, $ancestors);
        unset($ancestors[$id]);
    }

    private function readRegularChain(int $start, ?int $limit = null): string
    {
        $sectors = $this->chain($start, $this->fat);
        $length = $limit ?? count($sectors) * $this->sectorSize;

        return $this->readSectorRuns($sectors, 0, $length);
    }

    private function readRegularChainRange(int $start, int $offset, int $length): string
    {
        $first = intdiv($offset, $this->sectorSize);
        $inside = $offset % $this->sectorSize;
        $count = intdiv($inside + $length + $this->sectorSize - 1, $this->sectorSize);
        $sectors = $this->chainRange($start, $this->fat, $this->regularChainCache, $first, $count);

        return $this->readSectorRuns($sectors, $inside, $length);
    }

    /**
     * Reads adjacent physical sectors in a single I/O operation.
     *
     * @param list<int> $sectors
     */
    private function readSectorRuns(array $sectors, int $inside, int $length): string
    {
        $result = '';
        $remaining = $length;
        $count = count($sectors);
        for ($index = 0; $index < $count && $remaining > 0;) {
            $runStart = $sectors[$index];
            $runLength = 1;
            while (
                $index + $runLength < $count
                && $sectors[$index + $runLength] === $runStart + $runLength
            ) {
                $runLength++;
            }

            $available = $runLength * $this->sectorSize - $inside;
            $readLength = min($available, $remaining);
            $result .= $this->reader->read($this->sectorOffset($runStart) + $inside, $readLength);
            $remaining -= $readLength;
            $inside = 0;
            $index += $runLength;
        }

        return $result;
    }

    /** @param array<int, int> $table */
    private function readChainRange(string $source, array $table, int $unitSize, int $start, int $offset, int $length): string
    {
        $first = intdiv($offset, $unitSize);
        $inside = $offset % $unitSize;
        $count = intdiv($inside + $length + $unitSize - 1, $unitSize);
        $units = $this->chainRange($start, $table, $this->miniChainCache, $first, $count);

        $result = '';
        foreach ($units as $unit) {
            if ($unit < 0 || $unit > intdiv(strlen($source), $unitSize) - 1) {
                throw new CfbfException('Mini-sector chain references data outside the mini-stream.');
            }
            $available = $unitSize - $inside;
            $result .= substr(
                $source,
                $unit * $unitSize + $inside,
                min($available, $length - strlen($result)),
            );
            $inside = 0;
            if (strlen($result) >= $length) {
                break;
            }
        }
        if (strlen($result) !== $length) {
            throw new CfbfException('Sector chain is shorter than the declared stream size.');
        }
        return $result;
    }

    /**
     * Resolves only the requested part of a chain and retains the index for
     * subsequent sequential or random reads.
     *
     * @param array<int, int> $table
     * @param array<int, array{sectors: list<int>, seen: array<int, true>, complete: bool}> $cache
     * @return list<int>
     */
    private function chainRange(int $start, array $table, array &$cache, int $first, int $count): array
    {
        if (!isset($cache[$start])) {
            $cache[$start] = ['sectors' => [], 'seen' => [], 'complete' => false];
        }

        $last = $first + $count;
        $entry = &$cache[$start];
        $sectors = &$entry['sectors'];
        $seen = &$entry['seen'];
        $tail = $sectors === [] ? null : $sectors[array_key_last($sectors)];
        while (count($sectors) < $last && !$entry['complete']) {
            $current = $tail === null ? $start : $table[$tail] ?? self::FREE;
            if ($current === self::END) {
                $entry['complete'] = true;
                break;
            }
            $this->validateChainUnit($current, $table, $seen);
            $seen[$current] = true;
            $sectors[] = $current;
            $tail = $current;
        }
        unset($entry, $sectors, $seen);

        return array_slice($cache[$start]['sectors'], $first, $count);
    }

    /**
     * @param array<int, int> $table
     * @return list<int>
     */
    private function chain(int $start, array $table): array
    {
        $seen = [];
        $sectors = [];
        $current = $start;
        while ($current !== self::END) {
            $this->validateChainUnit($current, $table, $seen);
            $seen[$current] = true;
            $sectors[] = $current;
            $current = $table[$current];
        }

        return $sectors;
    }

    /**
     * @param array<int, int> $table
     * @param array<int, true> $seen
     */
    private function validateChainUnit(int $current, array $table, array $seen): void
    {
        if ($current === self::FREE || $current === self::FAT || $current === self::DIFAT || !isset($table[$current])) {
            throw new CfbfException('Invalid sector chain.');
        }
        if (isset($seen[$current])) {
            throw new CfbfException('Cycle in sector chain.');
        }
    }

    private function sector(int $id): string
    {
        return $this->reader->read($this->sectorOffset($id), $this->sectorSize);
    }

    private function sectorOffset(int $id): int
    {
        // In version 4 the 512-byte header is padded to one 4096-byte sector.
        return $this->sectorSize + $id * $this->sectorSize;
    }
    /** @return list<int> */
    private function uint32Array(string $bytes): array
    {
        $usable = strlen($bytes) & ~3;
        if ($usable === 0) {
            return [];
        }

        $values = unpack(
            $this->littleEndian ? 'V*' : 'N*',
            substr($bytes, 0, $usable),
        );
        if ($values === false) {
            throw new CfbfException('Cannot decode a 32-bit integer array.');
        }

        return array_values($values);
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

    private function decodeFileTime(int $low, int $high): ?\DateTimeImmutable
    {
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
