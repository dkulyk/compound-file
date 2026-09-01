<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

use DK\CompoundFile\DirectoryEntry;

/** @internal Directory red-black tree links serialized by the writer. */
final class WritableTreeNode
{
    public function __construct(
        public int $left = 0xFFFFFFFF,
        public int $right = 0xFFFFFFFF,
        public int $child = 0xFFFFFFFF,
        public int $parent = 0xFFFFFFFF,
        public int $color = DirectoryEntry::COLOR_BLACK,
    ) {
    }
}
