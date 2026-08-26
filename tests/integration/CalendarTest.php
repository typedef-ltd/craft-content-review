<?php

namespace typedef\contentreview\tests\integration;

use DateTimeImmutable;
use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\tests\support\IntegrationTestCase;

final class CalendarTest extends IntegrationTestCase
{
    public function testUiDatesAreNormalisedAndBlankValuesAreIgnored(): void
    {
        self::assertNull(Calendar::parseUiDate(null));
        self::assertNull(Calendar::parseUiDate(''));
        self::assertNull(Calendar::parseUiDate(['date' => '']));
        self::assertSame('2026-08-12', Calendar::parseUiDate('12 August 2026'));
        self::assertSame('2026-08-12', Calendar::parseUiDate(new DateTimeImmutable('2026-08-12 18:30:00')));
    }
}
