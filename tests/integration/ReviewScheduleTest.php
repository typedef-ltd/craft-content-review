<?php

namespace typedef\contentreview\tests\integration;

use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\models\ReviewSchedule;
use typedef\contentreview\tests\support\IntegrationTestCase;

final class ReviewScheduleTest extends IntegrationTestCase
{
    public function testDueStatesHaveTheCorrectBoundariesAndHandleUnscheduledEntries(): void
    {
        $this->settings()->dueSoonDays = 7;
        $today = Calendar::todayYmd();
        $schedule = $this->schedule();

        self::assertFalse($schedule->getIsOverdue());
        self::assertFalse($schedule->getIsDueToday());
        self::assertFalse($schedule->getIsDueSoon());

        $schedule->nextReviewOn = Calendar::subtractDays($today, 1);
        self::assertTrue($schedule->getIsOverdue());

        $schedule->nextReviewOn = $today;
        self::assertTrue($schedule->getIsDueToday());

        $schedule->nextReviewOn = Calendar::addDays($today, 7);
        self::assertTrue($schedule->getIsDueSoon());

        $schedule->nextReviewOn = Calendar::addDays($today, 8);
        self::assertFalse($schedule->getIsDueSoon());
    }

    public function testReviewWindowOnlyOpensWhenReviewIsDueSoonOrDue(): void
    {
        $this->settings()->dueSoonDays = 7;
        $today = Calendar::todayYmd();
        $schedule = $this->schedule();

        self::assertFalse($schedule->getIsWithinReviewWindow());

        $schedule->nextReviewOn = Calendar::addDays($today, 8);
        self::assertFalse($schedule->getIsWithinReviewWindow());

        $schedule->nextReviewOn = Calendar::addDays($today, 7);
        self::assertTrue($schedule->getIsWithinReviewWindow());

        $schedule->nextReviewOn = $today;
        self::assertTrue($schedule->getIsWithinReviewWindow());

        $schedule->nextReviewOn = Calendar::subtractDays($today, 1);
        self::assertTrue($schedule->getIsWithinReviewWindow());
    }

    private function schedule(): ReviewSchedule
    {
        return new ReviewSchedule([
            'elementId' => 100,
            'siteId' => 1,
        ]);
    }
}
