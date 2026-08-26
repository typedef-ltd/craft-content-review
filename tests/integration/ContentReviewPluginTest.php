<?php

namespace typedef\contentreview\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\services\Elements;
use DateTime;
use DateTimeZone;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\records\ReviewRecord;
use typedef\contentreview\records\ReviewScheduleRecord;
use typedef\contentreview\tests\support\IntegrationTestCase;
use yii\base\Event;

final class ContentReviewPluginTest extends IntegrationTestCase
{
    public function testPluginInstallsAndCreatesItsSchema(): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('content-review');

        self::assertTrue(Craft::$app->getPlugins()->isPluginInstalled('content-review'));
        self::assertInstanceOf(ContentReviewPlugin::class, $plugin);

        $schema = Craft::$app->getDb()->getSchema();
        self::assertNotNull($schema->getTableSchema(ReviewScheduleRecord::tableName(), true));
        self::assertNotNull($schema->getTableSchema(ReviewRecord::tableName(), true));
    }

    public function testAfterSaveLifecycleEnsuresSchedulesWithoutPostedSettingsAndDuringPropagation(): void
    {
        $this->settings()->defaultIntervalDays = 30;

        $user = $this->testUser();
        $siteId = $this->primarySiteId();

        ReviewScheduleRecord::deleteAll([
            'elementId' => (int)$user->id,
            'siteId' => $siteId,
        ]);

        $entry = new Entry();
        $entry->id = (int)$user->id;
        $entry->siteId = $siteId;
        $entry->setAuthor($user);
        $entry->dateUpdated = new DateTime('2026-01-10 12:00:00', new DateTimeZone('UTC'));

        $originalElements = Craft::$app->getElements();
        $elements = $this->createMock(Elements::class);
        $elements->method('canSave')->willReturn(true);
        Craft::$app->set('elements', $elements);

        try {
            Event::trigger(
                Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                new ElementEvent([
                    'element' => $entry,
                    'isNew' => true,
                ])
            );

            $schedule = ReviewScheduleRecord::find()
                ->elementId((int)$user->id)
                ->siteId($siteId)
                ->one();

            self::assertNotNull($schedule);
            self::assertSame((int)$user->id, (int)$schedule->reviewerUserId);
            self::assertSame('2026-02-09', $schedule->nextReviewOn);

            $schedule->delete();
            $entry->propagating = true;

            Event::trigger(
                Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                new ElementEvent([
                    'element' => $entry,
                    'isNew' => true,
                ])
            );

            $propagatedSchedule = ReviewScheduleRecord::find()
                ->elementId((int)$user->id)
                ->siteId($siteId)
                ->one();

            self::assertNotNull($propagatedSchedule);
            self::assertSame((int)$user->id, (int)$propagatedSchedule->reviewerUserId);
            self::assertSame('2026-02-09', $propagatedSchedule->nextReviewOn);
        } finally {
            Craft::$app->set('elements', $originalElements);
        }
    }
}
