<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

use DK\CompoundFile\Exception\CfbfException;

/** @internal Resolves and bounds cached CFBF sector chains. */
final class SectorChainCache
{
    /**
     * Sectors are packed as unsigned 32-bit little-endian values: 4 bytes per
     * sector instead of 16 for a PHP list.
     *
     * @var array<int, array{sectors: string, tail: ?int, hare: ?int, complete: bool}>
     */
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
            $this->entries[$start] = ['sectors' => '', 'tail' => null, 'hare' => $start, 'complete' => false];
        }

        $last = $first + $count;
        $entry = &$this->entries[$start];
        $known = strlen($entry['sectors']) >> 2;
        $tail = $entry['tail'];
        $hare = $entry['hare'];
        $walked = [];
        try {
            for ($length = $known; $length < $last && !$entry['complete']; $length++) {
                $current = $tail === null ? $start : $table[$tail] ?? SectorChain::FREE;
                if ($current === SectorChain::END) {
                    $entry['complete'] = true;
                    break;
                }
                SectorChain::visit($current, $hare, $table, $length === 0);
                $walked[] = $current;
                $tail = $current;
            }
        } catch (CfbfException $exception) {
            // Forget the partial walk. Resuming it would leave the cycle detector out
            // of step with the walk and could cache a repeated sector for later reads.
            $this->sectorCount -= $known;
            unset($entry, $this->entries[$start]);

            throw $exception;
        }
        if ($walked !== []) {
            $entry['sectors'] .= pack('V*', ...$walked);
            $this->sectorCount += count($walked);
        }
        $entry['tail'] = $tail;
        $entry['hare'] = $hare;
        unset($entry);
        $this->evict($start);

        // A sequential read asks for exactly the sectors it just walked.
        if ($first === $known) {
            return $walked;
        }
        $sectors = unpack('V*', substr($this->entries[$start]['sectors'], $first << 2, $count << 2));
        if ($sectors === false) {
            throw new CfbfException('Cannot decode a cached sector chain.');
        }

        return array_values($sectors);
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
            // ponytail: the chain being read is never evicted, so one stream can hold
            // 4 bytes per sector (1/128 of the stream with 512-byte sectors) above the bound.
            // Cap it by windowing the walk if that ever matters.
            if ($start === $active) {
                continue;
            }
            $this->sectorCount -= strlen($entry['sectors']) >> 2;
            unset($this->entries[$start]);
        }
    }

}
