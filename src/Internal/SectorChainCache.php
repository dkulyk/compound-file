<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

/** @internal Resolves and bounds cached CFBF sector chains. */
final class SectorChainCache
{
    /** @var array<int, array{sectors: list<int>, seen: array<int, true>, complete: bool}> */
    private array $entries = [];
    private int $sectorCount = 0;

    public function __construct(
        private int $maximumEntries,
        private int $maximumSectors,
    ) {
    }

    /**
     * @param array<int, int> $table
     * @return list<int>
     */
    public function range(int $start, array $table, int $first, int $count): array
    {
        if (!isset($this->entries[$start])) {
            $this->entries[$start] = ['sectors' => [], 'seen' => [], 'complete' => false];
        }

        $last = $first + $count;
        $entry = &$this->entries[$start];
        $sectors = &$entry['sectors'];
        $seen = &$entry['seen'];
        $tail = $sectors === [] ? null : $sectors[array_key_last($sectors)];
        while (count($sectors) < $last && !$entry['complete']) {
            $current = $tail === null ? $start : $table[$tail] ?? SectorChain::FREE;
            if ($current === SectorChain::END) {
                $entry['complete'] = true;
                break;
            }
            SectorChain::validateUnit($current, $table, $seen);
            $seen[$current] = true;
            $sectors[] = $current;
            $this->sectorCount++;
            $tail = $current;
        }
        unset($entry, $sectors, $seen);
        $this->evict($start);

        return array_slice($this->entries[$start]['sectors'], $first, $count);
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->sectorCount = 0;
    }

    private function evict(int $active): void
    {
        foreach ($this->entries as $start => $entry) {
            if (count($this->entries) <= $this->maximumEntries && $this->sectorCount <= $this->maximumSectors) {
                return;
            }
            if ($start === $active) {
                continue;
            }
            $this->sectorCount -= count($entry['sectors']);
            unset($this->entries[$start]);
        }
    }

}
