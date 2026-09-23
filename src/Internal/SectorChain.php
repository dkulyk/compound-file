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
     * Validates the unit a chain walk is about to emit and checks the walk for a cycle.
     *
     * Cycle detection is Floyd's. The walk itself is the tortoise; $hare starts at the
     * chain's first unit and moves two links for every later unit the walk emits, so
     * it costs one integer instead of a set of every unit visited. The two meet no
     * later than the walk's first repeated unit, so a cycle is reported before any
     * caller receives it, and at most one cycle length earlier, so a read that stops
     * just short of the repeat fails as well. Once the hare reaches the end of the
     * chain or a broken link
     * it becomes null: a chain that terminates cannot cycle, and the walk reports the
     * break itself when it gets there.
     *
     * @param array<int, int> $table
     */
    public static function visit(int $unit, ?int &$hare, array $table, bool $first): void
    {
        // Every marker is at or above DIFAT, so one comparison rejects all four.
        if ($unit >= self::DIFAT || !isset($table[$unit])) {
            throw new CfbfException('Invalid sector chain.');
        }
        if ($first || $hare === null) {
            return;
        }
        // Two unrolled links: this runs once per unit of every chain walk.
        $link = $table[$hare];
        $hare = $link < self::DIFAT && isset($table[$link]) ? $table[$link] : null;
        if ($hare !== null && ($hare >= self::DIFAT || !isset($table[$hare]))) {
            $hare = null;
        }
        if ($hare === $unit) {
            throw new CfbfException('Cycle in sector chain.');
        }
    }
}
