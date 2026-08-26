<?php

namespace typedef\contentreview\tests\integration;

use craft\elements\User;
use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\helpers\ReviewScheduleQuery;
use typedef\contentreview\tests\support\IntegrationTestCase;

final class ReviewScheduleQueryTest extends IntegrationTestCase
{
    public function testScheduleQueriesUseTheCorrectDateBuckets(): void
    {
        $this->settings()->dueSoonDays = 7;
        $today = Calendar::todayYmd();
        $record = $this->createScheduleRecord();

        $record->updateAttributes(['nextReviewOn' => Calendar::subtractDays($today, 1)]);
        self::assertSame(1, (int)ReviewScheduleQuery::queryOverdue()->count());
        self::assertSame(0, (int)ReviewScheduleQuery::queryDueToday()->count());

        $record->updateAttributes(['nextReviewOn' => $today]);
        self::assertSame(1, (int)ReviewScheduleQuery::queryDueToday()->count());
        self::assertSame(0, (int)ReviewScheduleQuery::queryOverdue()->count());

        $record->updateAttributes(['nextReviewOn' => Calendar::addDays($today, 7)]);
        self::assertSame(1, (int)ReviewScheduleQuery::queryDueSoon()->count());

        $record->updateAttributes(['nextReviewOn' => Calendar::addDays($today, 8)]);
        self::assertSame(0, (int)ReviewScheduleQuery::queryDueSoon()->count());
    }

    public function testBaseQueryRequiresEnabledScheduledContentAndHonoursReviewerScope(): void
    {
        $user = $this->testUser();
        $record = $this->createScheduleRecord([
            'reviewerUserId' => (int)$user->id,
        ]);

        self::assertSame(1, (int)ReviewScheduleQuery::buildBaseQuery()->count());
        self::assertSame(1, (int)ReviewScheduleQuery::buildBaseQuery($user)->count());

        $otherReviewer = new User();
        $otherReviewer->id = 2147483000;
        self::assertSame(0, (int)ReviewScheduleQuery::buildBaseQuery($otherReviewer)->count());

        $record->updateAttributes(['enabled' => false]);
        self::assertSame(0, (int)ReviewScheduleQuery::buildBaseQuery()->count());

        $record->updateAttributes([
            'enabled' => true,
            'nextReviewOn' => null,
        ]);
        self::assertSame(0, (int)ReviewScheduleQuery::buildBaseQuery()->count());

        $record->updateAttributes([
            'elementType' => User::class,
            'nextReviewOn' => Calendar::todayYmd(),
        ]);
        self::assertSame(0, (int)ReviewScheduleQuery::buildBaseQuery()->count());
    }

    public function testUnknownConfiguredSitesAndSectionsFailClosed(): void
    {
        $this->createScheduleRecord();

        $this->settings()->enabledSites = ['site-that-does-not-exist'];
        self::assertSame(0, (int)ReviewScheduleQuery::buildBaseQuery()->count());

        $this->settings()->enabledSites = ['all'];
        $this->settings()->enabledSections = ['section-that-does-not-exist'];
        self::assertSame(0, (int)ReviewScheduleQuery::buildBaseQuery()->count());
    }
}
