<?php

namespace typedef\contentreview\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\services\Elements;
use DateTime;
use DateTimeZone;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\models\ReviewSchedule;
use typedef\contentreview\records\ReviewRecord;
use typedef\contentreview\records\ReviewScheduleRecord;
use typedef\contentreview\tests\support\InMemoryReviewService;
use typedef\contentreview\tests\support\IntegrationTestCase;

final class ReviewServiceTest extends IntegrationTestCase
{
    public function testNewScheduleUsesLastUpdatedDateAndDefaultsReviewerToAuthor(): void
    {
        $this->settings()->defaultIntervalDays = 30;
        $entry = $this->entry('2026-01-10');
        $service = new InMemoryReviewService();

        $schedule = $service->syncReviewScheduleForEntry($entry, dryRun: true);

        self::assertNotNull($schedule);
        self::assertSame($entry->authorId, $schedule->reviewerUserId);
        self::assertSame('2026-02-09', $schedule->nextReviewOn);
        self::assertSame(0, $service->saveCount);

        $entry->draftId = 1;
        self::assertNull($service->syncReviewScheduleForEntry($entry, dryRun: true));
    }

    public function testExistingReviewedScheduleRecalculatesFromLastReviewDate(): void
    {
        $this->settings()->defaultIntervalDays = 30;
        $entry = $this->entry('2026-06-01');
        $service = new InMemoryReviewService();
        $service->schedule = $this->schedule($entry, [
            'lastReviewedOn' => '2026-01-10',
            'nextReviewOn' => '2026-07-09',
            'reviewerUserId' => $entry->authorId,
        ]);

        $schedule = $service->syncReviewScheduleForEntry($entry, dryRun: true);

        self::assertNotNull($schedule);
        self::assertSame('2026-02-09', $schedule->nextReviewOn);
        self::assertSame('2026-01-10', $schedule->lastReviewedOn);
        self::assertSame(0, $service->saveCount);
    }

    public function testExplicitReviewDateOverridesCalculatedDateWhenAllowed(): void
    {
        $this->settings()->allowPerEntryCustomDates = true;
        $entry = $this->entry();
        $service = new InMemoryReviewService();

        $schedule = $service->syncReviewScheduleForEntry(
            $entry,
            reviewerUserId: 456,
            explicitReviewOn: '2026-12-01',
            dryRun: true
        );

        self::assertNotNull($schedule);
        self::assertSame(456, $schedule->reviewerUserId);
        self::assertSame('2026-12-01', $schedule->explicitReviewOn);
        self::assertSame('2026-12-01', $schedule->nextReviewOn);
    }

    public function testDisablingScheduleHonoursDryRun(): void
    {
        $this->settings()->allowPerEntryDisabling = true;
        $entry = $this->entry();
        $service = new InMemoryReviewService();
        $service->schedule = $this->schedule($entry, [
            'nextReviewOn' => Calendar::addDays(Calendar::todayYmd(), 30),
            'reviewerUserId' => $entry->authorId,
            'enabled' => true,
        ]);

        $schedule = $service->syncReviewScheduleForEntry($entry, enabled: false, dryRun: true);

        self::assertNotNull($schedule);
        self::assertFalse($schedule->enabled);
        self::assertSame(0, $service->saveCount);
        self::assertTrue($service->schedule->enabled);
    }

    public function testDisablingPerEntryDisablingReEnablesExistingSchedule(): void
    {
        $this->settings()->allowPerEntryDisabling = false;
        $this->settings()->defaultIntervalDays = 30;

        $entry = $this->entry();
        $service = new InMemoryReviewService();
        $service->schedule = $this->schedule($entry, [
            'lastReviewedOn' => '2026-01-10',
            'nextReviewOn' => '2026-02-09',
            'reviewerUserId' => $entry->authorId,
            'enabled' => false,
        ]);

        $schedule = $service->syncReviewScheduleForEntry($entry);

        self::assertNotNull($schedule);
        self::assertTrue($schedule->enabled);
        self::assertTrue($service->schedule?->enabled);
        self::assertSame(1, $service->saveCount);
    }

    public function testMarkingEntryAsReviewedCreatesHistoryAndAdvancesSchedule(): void
    {
        $this->settings()->defaultIntervalDays = 30;
        $user = $this->testUser();
        $siteId = $this->primarySiteId();
        $today = Calendar::todayYmd();

        ReviewRecord::deleteAll([
            'elementId' => (int)$user->id,
            'siteId' => $siteId,
        ]);

        $entry = $this->entry();
        $entry->id = (int)$user->id;
        $entry->siteId = $siteId;
        $entry->setAuthorId((int)$user->id);

        $service = new InMemoryReviewService();
        $service->schedule = $this->schedule($entry, [
            'explicitReviewOn' => $today,
            'lastReviewedOn' => Calendar::subtractDays($today, 30),
            'nextReviewOn' => $today,
            // Reviewer assignment is organisational; any user who can save the entry may complete the review.
            'reviewerUserId' => 456,
        ]);

        $originalElements = Craft::$app->getElements();
        $elements = $this->createMock(Elements::class);
        $elements->method('canSave')->willReturn(true);
        Craft::$app->set('elements', $elements);

        $craftUser = Craft::$app->getUser();
        $originalIdentity = $craftUser->getIdentity();
        $craftUser->switchIdentity($user);

        try {
            self::assertTrue($service->markEntryAsReviewed($entry));
        } finally {
            $craftUser->switchIdentity($originalIdentity);
            Craft::$app->set('elements', $originalElements);
        }

        self::assertSame($today, $service->schedule?->lastReviewedOn);
        self::assertNull($service->schedule?->explicitReviewOn);
        self::assertSame(Calendar::addDays($today, 30), $service->schedule?->nextReviewOn);

        $review = ReviewRecord::find()
            ->elementId((int)$user->id)
            ->siteId($siteId)
            ->one();

        self::assertNotNull($review);
        self::assertSame($today, $review->dueOn);
        self::assertSame($today, $review->reviewedOn);
        self::assertSame((int)$user->id, (int)$review->reviewedByUserId);
    }

    public function testMarkingEntryAsReviewedRequiresPermissionToSaveEntry(): void
    {
        $user = $this->testUser();
        $siteId = $this->primarySiteId();
        $today = Calendar::todayYmd();

        ReviewRecord::deleteAll([
            'elementId' => (int)$user->id,
            'siteId' => $siteId,
        ]);

        $entry = $this->entry();
        $entry->id = (int)$user->id;
        $entry->siteId = $siteId;
        $entry->setAuthorId((int)$user->id);

        $service = new InMemoryReviewService();
        $service->schedule = $this->schedule($entry, [
            'nextReviewOn' => $today,
            'reviewerUserId' => (int)$user->id,
        ]);

        $originalElements = Craft::$app->getElements();
        $elements = $this->createMock(Elements::class);
        $elements->method('canSave')->willReturn(false);
        Craft::$app->set('elements', $elements);

        try {
            self::assertFalse($service->markEntryAsReviewed($entry));
        } finally {
            Craft::$app->set('elements', $originalElements);
        }

        self::assertNull($service->schedule?->lastReviewedOn);
        self::assertSame(
            0,
            (int)ReviewRecord::find()
                ->elementId((int)$user->id)
                ->siteId($siteId)
                ->count()
        );
    }

    public function testReviewCompletionRollsBackScheduleWhenHistoryCannotBeSaved(): void
    {
        $this->settings()->defaultIntervalDays = 30;

        $user = $this->testUser();
        $siteId = $this->primarySiteId();
        $today = Calendar::todayYmd();
        $previousReviewDate = Calendar::subtractDays($today, 30);

        ReviewRecord::deleteAll([
            'elementId' => (int)$user->id,
            'siteId' => $siteId,
        ]);

        $record = $this->createScheduleRecord([
            'explicitReviewOn' => $today,
            'lastReviewedOn' => $previousReviewDate,
            'nextReviewOn' => $today,
            'reviewerUserId' => (int)$user->id,
        ]);

        $entry = $this->entry();
        $entry->id = (int)$user->id;
        $entry->siteId = $siteId;
        $entry->setAuthorId((int)$user->id);

        $originalElements = Craft::$app->getElements();
        $elements = $this->createMock(Elements::class);
        $elements->method('canSave')->willReturn(true);
        Craft::$app->set('elements', $elements);

        $craftUser = Craft::$app->getUser();
        $originalIdentity = $craftUser->getIdentity();

        $invalidUser = new User();
        $invalidUser->id = 2147483000;
        $craftUser->setIdentity($invalidUser);

        try {
            self::assertFalse(ContentReviewPlugin::getInstance()->getReviewService()->markEntryAsReviewed($entry));
        } finally {
            $craftUser->setIdentity($originalIdentity);
            Craft::$app->set('elements', $originalElements);
        }

        $persistedSchedule = ReviewScheduleRecord::findOne($record->id);

        self::assertNotNull($persistedSchedule);
        self::assertSame($previousReviewDate, $persistedSchedule->lastReviewedOn);
        self::assertSame($today, $persistedSchedule->explicitReviewOn);
        self::assertSame($today, $persistedSchedule->nextReviewOn);
        self::assertSame(
            0,
            (int)ReviewRecord::find()
                ->elementId((int)$user->id)
                ->siteId($siteId)
                ->count()
        );
    }

    public function testEntriesOnDisabledSitesAreNotScheduled(): void
    {
        $this->settings()->enabledSites = ['site-that-does-not-exist'];

        $entry = $this->entry();
        $service = new InMemoryReviewService();

        self::assertNull($service->syncReviewScheduleForEntry($entry));
        self::assertSame(0, $service->saveCount);
    }

    private function entry(string $dateUpdated = '2026-01-01'): Entry
    {
        $entry = new Entry();
        $entry->id = 900001;
        $entry->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $entry->setAuthorId(123);
        $entry->dateUpdated = new DateTime($dateUpdated . ' 12:00:00', new DateTimeZone('UTC'));

        return $entry;
    }

    private function schedule(Entry $entry, array $config = []): ReviewSchedule
    {
        return new ReviewSchedule(array_merge([
            'elementId' => (int)$entry->id,
            'siteId' => (int)$entry->siteId,
        ], $config));
    }
}
