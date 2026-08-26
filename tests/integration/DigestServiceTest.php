<?php

namespace typedef\contentreview\tests\integration;

use Craft;
use craft\mail\Mailer;
use craft\mail\Message;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\records\ReviewScheduleRecord;
use typedef\contentreview\tests\support\IntegrationTestCase;

final class DigestServiceTest extends IntegrationTestCase
{
    public function testDigestIsOnlySentWhenContentIsDue(): void
    {
        ReviewScheduleRecord::deleteAll();
        $this->settings()->globalDigestEmails = 'global@example.com';
        $service = ContentReviewPlugin::getInstance()->getDigestService();

        self::assertSame(0, $service->sendDigests(true));

        $user = $this->testUser();
        $this->createScheduleRecord([
            'nextReviewOn' => Calendar::todayYmd(),
            'reviewerUserId' => (int)$user->id,
        ]);

        $originalMailer = $this->installSuccessfulMailer();

        try {
            self::assertSame(2, $service->sendDigests(true));
        } finally {
            Craft::$app->set('mailer', $originalMailer);
        }
    }

    public function testDueSoonOnlyDigestRespectsSetting(): void
    {
        ReviewScheduleRecord::deleteAll();
        $this->settings()->dueSoonDays = 7;
        $this->settings()->globalDigestEmails = '';
        $this->settings()->sendDigestsIfOnlyDueSoon = false;

        $user = $this->testUser();
        $this->createScheduleRecord([
            'nextReviewOn' => Calendar::addDays(Calendar::todayYmd(), 1),
            'reviewerUserId' => (int)$user->id,
        ]);

        $service = ContentReviewPlugin::getInstance()->getDigestService();
        $originalMailer = $this->installSuccessfulMailer();

        try {
            self::assertSame(0, $service->sendDigests(true));

            $this->settings()->sendDigestsIfOnlyDueSoon = true;
            self::assertSame(1, $service->sendDigests(true));
        } finally {
            Craft::$app->set('mailer', $originalMailer);
        }
    }

    public function testDigestContinuesWhenOneRecipientThrows(): void
    {
        ReviewScheduleRecord::deleteAll();
        $this->settings()->globalDigestEmails = 'global@example.com';

        $user = $this->testUser();
        $this->createScheduleRecord([
            'nextReviewOn' => Calendar::todayYmd(),
            'reviewerUserId' => (int)$user->id,
        ]);

        $sendCount = 0;

        $message = $this->createMock(Message::class);
        $message->method('setTo')->willReturnSelf();
        $message->method('setSubject')->willReturnSelf();
        $message->method('setHtmlBody')->willReturnSelf();
        $message->method('send')->willReturnCallback(function () use (&$sendCount): bool {
            $sendCount++;

            if ($sendCount === 1) {
                throw new \RuntimeException('Test mail failure');
            }

            return true;
        });

        $mailer = $this->createMock(Mailer::class);
        $mailer->method('compose')->willReturn($message);

        $originalMailer = Craft::$app->getMailer();
        Craft::$app->set('mailer', $mailer);

        try {
            self::assertSame(1, ContentReviewPlugin::getInstance()->getDigestService()->sendDigests(true));
            self::assertSame(2, $sendCount);
        } finally {
            Craft::$app->set('mailer', $originalMailer);
        }
    }

    private function installSuccessfulMailer(): Mailer
    {
        $message = $this->createMock(Message::class);
        $message->method('setTo')->willReturnSelf();
        $message->method('setSubject')->willReturnSelf();
        $message->method('setHtmlBody')->willReturnSelf();
        $message->method('send')->willReturn(true);

        $mailer = $this->createMock(Mailer::class);
        $mailer->method('compose')->willReturn($message);

        $originalMailer = Craft::$app->getMailer();
        Craft::$app->set('mailer', $mailer);

        return $originalMailer;
    }
}
