<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

/** @internal Compares names using the ordering required by CFBF directory trees. */
final class CfbfNameComparator
{
    public static function compare(string $left, string $right): int
    {
        $leftUtf16 = mb_convert_encoding(PathNormalizer::fold($left), 'UTF-16BE', 'UTF-8');
        $rightUtf16 = mb_convert_encoding(PathNormalizer::fold($right), 'UTF-16BE', 'UTF-8');
        $length = strlen($leftUtf16) <=> strlen($rightUtf16);

        return $length !== 0 ? $length : strcmp($leftUtf16, $rightUtf16);
    }
}
