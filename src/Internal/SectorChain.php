<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

use DK\CompoundFile\Exception\CfbfException;

/** @internal Shared CFBF sector markers and chain-unit validation. */
final class SectorChain
{
    public const FREE = 0xFFFFFFFF;
    public const END = 0xFFFFFFFE;
    public const FAT = 0xFFFFFFFD;
    public const DIFAT = 0xFFFFFFFC;

    /**
     * @param array<int, int> $table
     * @param array<int, true> $seen
     */
    public static function validateUnit(int $current, array $table, array $seen): void
    {
        if ($current === self::FREE || $current === self::FAT || $current === self::DIFAT || !isset($table[$current])) {
            throw new CfbfException('Invalid sector chain.');
        }
        if (isset($seen[$current])) {
            throw new CfbfException('Cycle in sector chain.');
        }
    }
}
