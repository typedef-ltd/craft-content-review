<?php

namespace typedef\contentreview\tests\support;

use typedef\contentreview\models\ReviewSchedule;
use typedef\contentreview\services\ReviewService;

final class InMemoryReviewService extends ReviewService
{
    public int $saveCount = 0;
    public ?ReviewSchedule $schedule = null;

    public function getReviewSchedule(int $elementId, int $siteId): ?ReviewSchedule
    {
        if (
            $this->schedule === null
            || $this->schedule->elementId !== $elementId
            || $this->schedule->siteId !== $siteId
        ) {
            return null;
        }

        return clone $this->schedule;
    }

    public function saveReviewSchedule(ReviewSchedule $schedule): void
    {
        $this->saveCount++;
        $this->schedule = clone $schedule;
    }
}
