<?php

declare(strict_types=1);

namespace DK\CompoundFile;

/** Immutable representation of the parsed CFBF header. */
final class Header
{
    public const LITTLE_ENDIAN = 'little';
    public const BIG_ENDIAN = 'big';

    public function __construct(
        private int $minorVersion,
        private int $majorVersion,
        private string $byteOrder,
        private int $sectorShift,
        private int $miniSectorShift,
        private int $fatSectorCount,
        private int $directoryStartSector,
        private int $transactionSignature,
        private int $miniStreamCutoff,
        private int $miniFatStartSector,
        private int $miniFatSectorCount,
        private int $difatStartSector,
        private int $difatSectorCount,
        private int $directorySectorCount = 0,
    ) {
    }

    public function getMinorVersion(): int
    {
        return $this->minorVersion;
    }
    public function getMajorVersion(): int
    {
        return $this->majorVersion;
    }
    public function getByteOrder(): string
    {
        return $this->byteOrder;
    }
    public function isLittleEndian(): bool
    {
        return $this->byteOrder === self::LITTLE_ENDIAN;
    }
    public function isBigEndian(): bool
    {
        return $this->byteOrder === self::BIG_ENDIAN;
    }
    public function getSectorShift(): int
    {
        return $this->sectorShift;
    }
    public function getSectorSize(): int
    {
        return 1 << $this->sectorShift;
    }
    public function getMiniSectorShift(): int
    {
        return $this->miniSectorShift;
    }
    public function getMiniSectorSize(): int
    {
        return 1 << $this->miniSectorShift;
    }
    public function getFatSectorCount(): int
    {
        return $this->fatSectorCount;
    }
    public function getDirectoryStartSector(): int
    {
        return $this->directoryStartSector;
    }
    /** Returns the declared directory sector count (version 4; zero in version 3). */
    public function getDirectorySectorCount(): int
    {
        return $this->directorySectorCount;
    }
    public function getTransactionSignature(): int
    {
        return $this->transactionSignature;
    }
    public function getMiniStreamCutoff(): int
    {
        return $this->miniStreamCutoff;
    }
    public function getMiniFatStartSector(): int
    {
        return $this->miniFatStartSector;
    }
    public function getMiniFatSectorCount(): int
    {
        return $this->miniFatSectorCount;
    }
    public function getDifatStartSector(): int
    {
        return $this->difatStartSector;
    }
    public function getDifatSectorCount(): int
    {
        return $this->difatSectorCount;
    }
    public function hasMiniFat(): bool
    {
        return $this->miniFatSectorCount > 0;
    }
    public function hasDifatSectors(): bool
    {
        return $this->difatSectorCount > 0;
    }
}
