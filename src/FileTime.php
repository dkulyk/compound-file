<?php

declare(strict_types=1);

namespace DK\CompoundFile;

use DK\CompoundFile\Exception\CfbfException;

/**
 * Conversion between Windows FILETIME and PHP date objects.
 *
 * FILETIME counts 100-nanosecond ticks since 1601-01-01 UTC. Compound files use it
 * for directory entry timestamps, and property set streams such as
 * "\x05SummaryInformation" use the same encoding for VT_FILETIME values.
 */
final class FileTime
{
    /** Ticks per second; a FILETIME tick is 100 nanoseconds. */
    public const TICKS_PER_SECOND = 10_000_000;

    /** Seconds between the FILETIME epoch (1601-01-01) and the Unix epoch. */
    public const EPOCH_OFFSET = 11_644_473_600;

    /** The all-zero FILETIME, meaning "not set". */
    public const UNSET = "\0\0\0\0\0\0\0\0";

    /**
     * Decodes eight little-endian FILETIME bytes, as stored in directory entries
     * and VT_FILETIME property values.
     *
     * Returns null when the value is unset or outside the representable range.
     *
     * A date object resolves to microseconds, so the last of the eight decimal
     * digits of a tick count is lost and encode(decode($bytes)) does not always
     * return $bytes. Use ticks() and fromTicks() to keep the exact count when
     * rewriting a value that was read rather than computed.
     */
    public static function decode(string $bytes): ?\DateTimeImmutable
    {
        if (\strlen($bytes) !== 8) {
            throw new \InvalidArgumentException('A FILETIME value must be exactly 8 bytes.');
        }
        /** @var array{1: int, 2: int} $parts */
        $parts = unpack('V2', $bytes);

        return self::fromTicks(self::ticks($parts[1], $parts[2]));
    }

    /** Encodes a date as eight little-endian FILETIME bytes; null yields the unset value. */
    public static function encode(?\DateTimeInterface $time): string
    {
        if ($time === null) {
            return self::UNSET;
        }
        $ticks = self::toTicks($time);

        return pack('V2', $ticks % 4294967296, intdiv($ticks, 4294967296));
    }

    /**
     * Combines the low and high halves of a FILETIME into a tick count.
     *
     * Returns null when the value is unset or not representable as a PHP integer.
     */
    public static function ticks(int $low, int $high): ?int
    {
        if (($low === 0 && $high === 0) || $high > 0x7FFFFFFF || (PHP_INT_SIZE < 8 && $high !== 0)) {
            return null;
        }

        return $high * 4294967296 + $low;
    }

    /** Converts a FILETIME tick count to UTC, or null when unset or not representable. */
    public static function fromTicks(?int $ticks): ?\DateTimeImmutable
    {
        if ($ticks === null || $ticks < 0) {
            return null;
        }
        $wholeSeconds = intdiv($ticks, self::TICKS_PER_SECOND);
        $microseconds = intdiv($ticks % self::TICKS_PER_SECOND, 10);
        $time = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $wholeSeconds - self::EPOCH_OFFSET, $microseconds),
        );

        return $time === false ? null : $time;
    }

    /**
     * Converts a date to a FILETIME tick count.
     *
     * @throws CfbfException when the date falls outside the representable range,
     *                       which is 1601-01-01 UTC to 30828-09-14 02:48:05 UTC
     */
    public static function toTicks(\DateTimeInterface $time): int
    {
        $seconds = $time->getTimestamp();
        $maximum = intdiv(PHP_INT_MAX, self::TICKS_PER_SECOND) - self::EPOCH_OFFSET;
        if ($seconds < -self::EPOCH_OFFSET || $seconds > $maximum) {
            throw new CfbfException(
                sprintf('FILETIME cannot represent "%s"; the range is 1601-01-01 to 30828-09-14 UTC.', $time->format('c'))
            );
        }

        return ($seconds + self::EPOCH_OFFSET) * self::TICKS_PER_SECOND + (int) $time->format('u') * 10;
    }
}
