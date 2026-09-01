<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

final class FixtureBuilder
{
    public static function regular(string $name = 'Data', bool $little = true): string
    {
        $payload = str_repeat('OLE2', 1024); // 4096 bytes: stored in the regular FAT.
        return self::regularWithPayload($payload, $name, $little);
    }

    /** @param list<int>|null $sectorOrder */
    public static function regularWithPayload(
        string $payload,
        string $name = 'Data',
        bool $little = true,
        ?array $sectorOrder = null,
    ): string {
        if (strlen($payload) < 4096 || strlen($payload) % 512 !== 0 || strlen($payload) > 64_512) {
            throw new \InvalidArgumentException('Regular fixture payload must contain 8 to 126 complete sectors.');
        }

        $dataSectorCount = intdiv(strlen($payload), 512);
        $sectorOrder ??= range(2, 1 + $dataSectorCount);
        $expectedSectors = range(2, 1 + $dataSectorCount);
        if (
            count($sectorOrder) !== $dataSectorCount
            || array_diff($sectorOrder, $expectedSectors) !== []
            || array_diff($expectedSectors, $sectorOrder) !== []
        ) {
            throw new \InvalidArgumentException('Sector order must be a permutation of the payload sector IDs.');
        }

        $fat = array_fill(0, 2 + $dataSectorCount, 0xFFFFFFFF);
        $fat[0] = 0xFFFFFFFE;
        $fat[1] = 0xFFFFFFFD;
        foreach ($sectorOrder as $index => $sector) {
            $fat[$sector] = $sectorOrder[$index + 1] ?? 0xFFFFFFFE;
        }
        $directory = self::entry('Root Entry', 5, 0xFFFFFFFF, 0xFFFFFFFF, 1, 0xFFFFFFFE, 0, $little)
            . self::entry($name, 2, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF, $sectorOrder[0], strlen($payload), $little)
            . str_repeat("\0", 256);
        $sectors = [0 => $directory, 1 => self::fat($fat, $little)];
        foreach (str_split($payload, 512) as $index => $sector) {
            $sectors[$sectorOrder[$index]] = $sector;
        }
        ksort($sectors);

        return self::header(1, 0, 0xFFFFFFFE, 1, $little).implode('', $sectors);
    }

    public static function mini(string $name = 'Small'): string
    {
        $payload = str_repeat('mini-', 20);
        $directory = self::entry('Root Entry', 5, 0xFFFFFFFF, 0xFFFFFFFF, 1, 2, 512, true)
            . self::entry($name, 2, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0, strlen($payload), true)
            . str_repeat("\0", 256);
        $miniFat = pack('V*', 1, 0xFFFFFFFE).str_repeat("\xFF", 504);
        $miniStream = str_pad(substr($payload, 0, 64), 64, "\0").str_pad(substr($payload, 64), 64, "\0").str_repeat("\0", 384);
        return self::header(3, 1, 1, 1, true).$directory.$miniFat.$miniStream.self::fat([0xFFFFFFFE,0xFFFFFFFE,0xFFFFFFFE,0xFFFFFFFD], true);
    }

    public static function fragmentedMini(string $name = 'Small'): string
    {
        $payload = str_repeat('fragmented-', 10);
        $directory = self::entry('Root Entry', 5, 0xFFFFFFFF, 0xFFFFFFFF, 1, 2, 512, true)
            .self::entry($name, 2, 0xFFFFFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 1, strlen($payload), true)
            .str_repeat("\0", 256);
        $miniFat = pack('V*', 0xFFFFFFFE, 3, 0xFFFFFFFF, 0xFFFFFFFE).str_repeat("\xFF", 496);
        $miniStream = str_repeat('unused-', 9).'x'
            .substr($payload, 0, 64)
            .str_repeat("\0", 64)
            .str_pad(substr($payload, 64), 64, "\0")
            .str_repeat("\0", 256);

        return self::header(3, 1, 1, 1, true).$directory.$miniFat.$miniStream.self::fat([0xFFFFFFFE, 0xFFFFFFFE, 0xFFFFFFFE, 0xFFFFFFFD], true);
    }

    private static function header(int $fatSector, int $miniCount, int $miniStart, int $fatCount, bool $little): string
    {
        $u16 = function ($v) use ($little) {
            return pack($little ? 'v' : 'n', $v);
        };
        $u32 = function ($v) use ($little) {
            return pack($little ? 'V' : 'N', $v);
        };
        $h = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 16).$u16(0x003E).$u16(3).($little ? "\xFE\xFF" : "\xFF\xFE").$u16(9).$u16(6).str_repeat("\0", 6);
        $h .= $u32(0).$u32(1).$u32(0).$u32(0).$u32(0x1000).$u32($miniStart).$u32($miniCount).$u32(0xFFFFFFFE).$u32(0);
        $h .= $u32($fatSector);
        for ($i = 1;$i < 109;$i++) {
            $h .= $u32(0xFFFFFFFF);
        }
        return $h;
    }
    private static function entry(string $name, int $type, int $left, int $right, int $child, int $start, int $size, bool $little): string
    {
        $u16 = function ($v) use ($little) {
            return pack($little ? 'v' : 'n', $v);
        };
        $u32 = function ($v) use ($little) {
            return pack($little ? 'V' : 'N', $v);
        };
        $encoded = mb_convert_encoding($name, $little ? 'UTF-16LE' : 'UTF-16BE', 'UTF-8')."\0\0";
        return str_pad($encoded, 64, "\0").$u16(strlen($encoded)).chr($type).chr(1).$u32($left).$u32($right).$u32($child).str_repeat("\0", 16).$u32(0).str_repeat("\0", 16).$u32($start).$u32($size).$u32(0);
    }
    private static function fat(array $values, bool $little): string
    {
        $out = '';
        foreach ($values as $v) {
            $out .= pack($little ? 'V' : 'N', $v);
        } return str_pad($out, 512, "\xFF");
    }
}
