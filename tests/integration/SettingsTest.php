<?php

namespace typedef\contentreview\tests\integration;

use typedef\contentreview\models\Settings;
use typedef\contentreview\tests\support\IntegrationTestCase;

final class SettingsTest extends IntegrationTestCase
{
    public function testDueSoonWindowMustBeShorterThanEveryActiveReviewInterval(): void
    {
        $settings = new Settings([
            'defaultIntervalDays' => 30,
            'dueSoonDays' => 7,
        ]);

        self::assertTrue($settings->validate(['dueSoonDays']));

        $settings = new Settings([
            'defaultIntervalDays' => 7,
            'dueSoonDays' => 7,
        ]);

        self::assertFalse($settings->validate(['dueSoonDays']));
        self::assertNotEmpty($settings->getErrors('dueSoonDays'));

        $settings = new Settings([
            'defaultIntervalDays' => 30,
            'dueSoonDays' => 7,
            'usePerSectionIntervals' => true,
            'intervalDaysPerSection' => [
                'example-section' => 5,
            ],
        ]);

        self::assertFalse($settings->validate(['dueSoonDays']));
        self::assertNotEmpty($settings->getErrors('dueSoonDays'));
    }

    public function testGlobalDigestEmailsAreParsedAndValidated(): void
    {
        $settings = new Settings([
            'globalDigestEmails' => 'one@example.com, two@example.com, one@example.com',
        ]);

        self::assertTrue($settings->validate(['globalDigestEmails']));
        self::assertSame(
            ['one@example.com', 'two@example.com'],
            $settings->getGlobalDigestEmails()
        );

        $settings = new Settings([
            'globalDigestEmails' => 'one@example.com, not-an-email',
        ]);

        self::assertFalse($settings->validate(['globalDigestEmails']));
        self::assertNotEmpty($settings->getErrors('globalDigestEmails'));
    }
}
