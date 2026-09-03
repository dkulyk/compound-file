<?php

declare(strict_types=1);

namespace DK\CompoundFile\Internal;

/** @internal Applies the same Unicode case folding to all CFBF path indexes. */
final class PathNormalizer
{
    public static function normalize(string $path): string
    {
        return self::fold(str_replace('\\', '/', trim($path, '/\\')));
    }

    public static function fold(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }
}
