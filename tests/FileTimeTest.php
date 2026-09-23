<?php

declare(strict_types=1);

namespace DK\CompoundFile\Tests;

use DK\CompoundFile\Exception\CfbfException;
use DK\CompoundFile\FileTime;
use PHPUnit\Framework\TestCase;

final class FileTimeTest extends TestCase
{
    public function testRoundTripsSubSecondPrecision(): void
    {
        $time = new \DateTimeImmutable('2025-09-23 00:00:00.123456', new \DateTimeZone('UTC'));
        $decoded = FileTime::decode(FileTime::encode($time));
        self::assertNotNull($decoded);
        self::assertSame('2025-09-23 00:00:00.123456', $decoded->format('Y-m-d H:i:s.u'));
    }

    public function testEncodesKnownFileTimeValue(): void
    {
        // 2009-02-13 23:31:30 UTC, the classic 1234567890 Unix timestamp.
        $time = new \DateTimeImmutable('@1234567890');
        self::assertSame('00f59632338ec901', bin2hex(FileTime::encode($time)));
        self::assertSame(128790414900000000, FileTime::toTicks($time));
    }

    public function testAcceptsMutableDateTime(): void
    {
        $mutable = new \DateTime('@1234567890');
        self::assertSame(FileTime::encode(new \DateTimeImmutable('@1234567890')), FileTime::encode($mutable));
    }

    public function testTreatsEpochAsRealDateNotUnset(): void
    {
        $encoded = FileTime::encode(new \DateTimeImmutable('@0'));
        self::assertNotSame(FileTime::UNSET, $encoded);
        $decoded = FileTime::decode($encoded);
        self::assertNotNull($decoded);
        self::assertSame(0, $decoded->getTimestamp());
    }

    public function testNullAndUnsetValuesRoundTrip(): void
    {
        self::assertSame(FileTime::UNSET, FileTime::encode(null));
        self::assertNull(FileTime::decode(FileTime::UNSET));
        self::assertNull(FileTime::ticks(0, 0));
        self::assertNull(FileTime::fromTicks(null));
    }

    public function testDecodesRawBytesWithoutRoundTrip(): void
    {
        $decoded = FileTime::decode(hex2bin('00f59632338ec901'));
        self::assertNotNull($decoded);
        self::assertSame(1234567890, $decoded->getTimestamp());
    }

    public function testHandlesDatesBetweenTheFileTimeAndUnixEpochs(): void
    {
        $time = new \DateTimeImmutable('1969-12-31 23:59:55.123456', new \DateTimeZone('UTC'));
        self::assertSame(116444735951234560, FileTime::toTicks($time));
        $decoded = FileTime::decode(FileTime::encode($time));
        self::assertNotNull($decoded);
        self::assertSame('1969-12-31 23:59:55.123456', $decoded->format('Y-m-d H:i:s.u'));
    }

    public function testDateObjectsTruncateTicksToMicroseconds(): void
    {
        $ticks = (1758585600 + FileTime::EPOCH_OFFSET) * FileTime::TICKS_PER_SECOND + 1234567;
        $bytes = pack('V2', $ticks % 4294967296, intdiv($ticks, 4294967296));

        $rewritten = FileTime::encode(FileTime::decode($bytes));
        self::assertNotSame($bytes, $rewritten);
        self::assertSame($ticks - 7, FileTime::ticks(...array_values((array) unpack('V2', $rewritten))));
    }

    public function testConvertsTheLargestRepresentableTickCount(): void
    {
        $decoded = FileTime::fromTicks(PHP_INT_MAX);
        self::assertNotNull($decoded);
        self::assertSame('30828-09-14 02:48:05.477580', $decoded->format('Y-m-d H:i:s.u'));
    }

    public function testRejectsDatesBeyondTheRepresentableRange(): void
    {
        $this->expectException(CfbfException::class);
        FileTime::encode((new \DateTimeImmutable('2000-01-01', new \DateTimeZone('UTC')))->setDate(30829, 1, 1));
    }

    public function testRejectsNegativeTicks(): void
    {
        self::assertNull(FileTime::fromTicks(-1));
        self::assertNull(FileTime::fromTicks(PHP_INT_MIN));
    }

    public function testRejectsTicksAboveTheSignedRange(): void
    {
        self::assertNull(FileTime::ticks(0, 0x80000000));
    }

    public function testRejectsWrongLengthInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FileTime::decode("\0\0\0\0");
    }

    public function testRejectsDatesBeforeTheFileTimeEpoch(): void
    {
        $this->expectException(CfbfException::class);
        FileTime::encode(new \DateTimeImmutable('1600-01-01', new \DateTimeZone('UTC')));
    }
}
