<?php

namespace typedef\contentreview\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use InvalidArgumentException;
use typedef\contentreview\helpers\Calendar;

final class CalendarTest extends Unit
{
    public function testDateArithmeticAcrossCalendarBoundaries(): void
    {
        self::assertSame('2024-02-29', Calendar::addDays('2024-02-28', 1));
        self::assertSame('2024-03-01', Calendar::addDays('2024-02-28', 2));
        self::assertSame('2025-01-01', Calendar::addDays('2024-12-31', 1));
        self::assertSame('2024-12-31', Calendar::subtractDays('2025-01-01', 1));
        self::assertSame('2026-08-05', Calendar::addDays('2026-08-12', -7));
    }

    public function testDaysBetweenIsSigned(): void
    {
        self::assertSame(7, Calendar::daysBetween('2026-08-12', '2026-08-19'));
        self::assertSame(-7, Calendar::daysBetween('2026-08-19', '2026-08-12'));
        self::assertSame(0, Calendar::daysBetween('2026-08-12', '2026-08-12'));
    }

    public function testDayOfWeekUsesIsoWeekdayNumbers(): void
    {
        self::assertSame(1, Calendar::dayOfWeek('2026-08-10'));
        self::assertSame(7, Calendar::dayOfWeek('2026-08-16'));
    }

    public function testYmdValidationIsStrict(): void
    {
        self::assertTrue(Calendar::isValidYmd('2024-02-29'));
        self::assertFalse(Calendar::isValidYmd('2026-02-29'));
        self::assertFalse(Calendar::isValidYmd('2026-8-12'));
        self::assertFalse(Calendar::isValidYmd('12-08-2026'));
        self::assertFalse(Calendar::isValidYmd(''));
    }

    public function testInvalidDateThrowsWhenUsedForArithmetic(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Calendar::addDays('2026-02-29', 1);
    }

    public function testDateTimeConversionsUseCanonicalYmdFormat(): void
    {
        self::assertSame(
            '2026-08-12',
            Calendar::ymdFromDateTime(new DateTimeImmutable('2026-08-12 23:59:59'))
        );
        self::assertSame('2026-08-12', Calendar::ymdFromDateTimeString('12 August 2026 10:30:00'));
    }
}
