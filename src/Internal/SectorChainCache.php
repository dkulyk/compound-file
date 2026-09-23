<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

use DK\CompoundFile\Exception\CfbfException;

/** @internal Resolves and bounds cached CFBF sector chains. */
final class SectorChainCache
{
    /** @var array<int, array{sectors: list<int>, hare: ?int, complete: bool}> */
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
            $this->entries[$start] = ['sectors' => [], 'hare' => $start, 'complete' => false];
        }

        $last = $first + $count;
        $entry = &$this->entries[$start];
        $sectors = &$entry['sectors'];
        $tail = $sectors === [] ? null : $sectors[array_key_last($sectors)];
        $hare = $entry['hare'];
        try {
            while (count($sectors) < $last && !$entry['complete']) {
                $current = $tail === null ? $start : $table[$tail] ?? SectorChain::FREE;
                if ($current === SectorChain::END) {
                    $entry['complete'] = true;
                    break;
                }
                SectorChain::visit($current, $hare, $table, $sectors === []);
                $sectors[] = $current;
                $this->sectorCount++;
                $tail = $current;
            }
        } catch (CfbfException $exception) {
            // Forget the partial walk. Resuming it would leave the cycle detector out
            // of step with the walk and could cache a repeated sector for later reads.
            $this->sectorCount -= count($sectors);
            unset($entry, $sectors, $this->entries[$start]);

            throw $exception;
        }
        $entry['hare'] = $hare;
        unset($entry, $sectors);
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
