<?php

namespace typedef\contentreview\tests\unit;

use Codeception\Test\Unit;
use typedef\contentreview\models\Settings;

final class SettingsTest extends Unit
{
    public function testReviewIntervalsResolveFromGlobalAndPerSectionSettings(): void
    {
        $settings = new Settings([
            'defaultIntervalDays' => 180,
            'intervalDaysPerSection' => [
                'news' => 30,
            ],
        ]);

        self::assertSame(180, $settings->getResolvedIntervalDaysForSectionUid('news'));

        $settings->usePerSectionIntervals = true;

        self::assertSame(30, $settings->getResolvedIntervalDaysForSectionUid('news'));
        self::assertSame(180, $settings->getResolvedIntervalDaysForSectionUid('pages'));
    }
}
