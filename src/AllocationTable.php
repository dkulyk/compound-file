<?php

declare(strict_types=1);

namespace DK\CompoundFile;

/** Immutable diagnostic snapshot of CFBF allocation tables. */
final class AllocationTable
{
    /**
     * @param list<int> $difat
     * @param list<int> $fat
     * @param list<int> $miniFat
     */
    public function __construct(
        private array $difat,
        private array $fat,
        private array $miniFat,
    ) {
    }

    /** @return list<int> */
    public function getDifat(): array
    {
        return $this->difat;
    }
    /** @return list<int> */
    public function getFat(): array
    {
        return $this->fat;
    }
    /** @return list<int> */
    public function getMiniFat(): array
    {
        return $this->miniFat;
    }
}
