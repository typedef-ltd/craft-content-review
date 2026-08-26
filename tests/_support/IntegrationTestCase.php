<?php

namespace typedef\contentreview\tests\support;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\models\Settings;
use typedef\contentreview\records\ReviewScheduleRecord;

abstract class IntegrationTestCase extends Unit
{
    private array $settingsSnapshot = [];

    protected function _before(): void
    {
        $this->settings()->clearErrors();
        $this->settingsSnapshot = $this->settings()->getAttributes();
    }

    protected function _after(): void
    {
        $this->settings()->setAttributes($this->settingsSnapshot, false);
        $this->settings()->clearErrors();
    }

    protected function settings(): Settings
    {
        return ContentReviewPlugin::getInstance()->getSettings();
    }

    protected function testUser(): User
    {
        $user = User::find()
            ->status(null)
            ->one();

        self::assertInstanceOf(User::class, $user, 'Craft test installation should contain its install user.');

        return $user;
    }

    protected function primarySiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    protected function createScheduleRecord(array $config = []): ReviewScheduleRecord
    {
        $user = $this->testUser();
        $siteId = $this->primarySiteId();

        ReviewScheduleRecord::deleteAll([
            'elementId' => (int)$user->id,
            'siteId' => $siteId,
        ]);

        $record = new ReviewScheduleRecord(array_merge([
            'elementId' => (int)$user->id,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'nextReviewOn' => Calendar::todayYmd(),
            'reviewerUserId' => null,
            'enabled' => true,
        ], $config));

        if (!$record->save()) {
            self::fail('Could not create test review schedule: ' . implode('; ', $record->getErrorSummary(true)));
        }

        return $record;
    }
}
