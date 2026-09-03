<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

/** @internal Applies the same Unicode case folding to all CFBF path indexes. */
final class PathNormalizer
{
    public static function normalize(string $path): string
    {
        return mb_strtolower(str_replace('\\', '/', trim($path, '/\\')), 'UTF-8');
    }

    public static function fold(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }
}
