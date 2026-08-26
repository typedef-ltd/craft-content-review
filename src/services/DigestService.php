<?php

namespace typedef\contentreview\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\mutex\Mutex;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\helpers\ReviewScheduleQuery;
use typedef\contentreview\records\ReviewScheduleRecord;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Builds and sends digest emails.
 */
class DigestService extends Component
{
    /**
     * Send all digests
     *
     * @return int Number of recipients successfully emailed
     *
     * @throws Exception
     * @throws InvalidConfigException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function sendDigests(bool $force = false): int
    {
        /** @var ?Mutex $mutex */
        $mutex = null;
        $mutexKey = "contentreview:digest:day:" . Calendar::todayYmd();
        if (!$force) {
            $mutex = Craft::$app->getMutex();
            if (!$mutex->acquire($mutexKey)) {
                // Another process is already doing today's digest
                return 0;
            }
        }

        try {
            // Get user IDs
            $reviewerUserIds = (new Query())
                ->select(['reviewerUserId'])
                ->from(ReviewScheduleRecord::tableName())
                ->where([
                    'and',
                    ['not', ['reviewerUserId' => null]],
                    ['enabled' => 1],
                ])
                ->distinct()
                ->column();

            // Get reviewer users
            /** @var User[] $reviewers */
            $reviewers = User::find()
                ->id($reviewerUserIds)
                ->status('active')
                ->all();

            // Loop users and send their personalised digest emails where applicable
            $successfulRecipientCount = 0;
            foreach ($reviewers as $reviewer) {
                // Skip a reviewer if he happens not to have an email address
                if (!$reviewer->email) {
                    continue;
                }

                $emailBody = $this->buildDigestEmail($reviewer);
                if (!$emailBody) {
                    continue;
                }

                $sentOk = $this->sendDigestEmail($reviewer->email, Craft::t('content-review', 'Your Content Review Digest'), $emailBody);
                if ($sentOk) {
                    $successfulRecipientCount++;
                }
            }

            // Global digest recipients
            $globalDigestEmailBody = $this->buildDigestEmail();
            if ($globalDigestEmailBody) {
                foreach (ContentReviewPlugin::getInstance()->getSettings()->getGlobalDigestEmails() as $emailAddress) {
                    $sentOk = $this->sendDigestEmail($emailAddress, Craft::t('content-review', 'Content Review Digest'), $globalDigestEmailBody);
                    if ($sentOk) {
                        $successfulRecipientCount++;
                    }
                }
            }
        } finally {
            if ($mutex !== null) {
                $mutex->release($mutexKey);
            }
        }

        return $successfulRecipientCount;
    }

    /**
     * @param User|null $reviewer if there's no reviewer, then a global digest will be sent with all content
     * @return string|false
     *
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    private function buildDigestEmail(?User $reviewer = null): string|false
    {
        $settings = ContentReviewPlugin::getInstance()->getSettings();

        $summary = ReviewScheduleQuery::buildSummary($reviewer);

        if ($settings->sendDigestsIfOnlyDueSoon) {
            $anythingToReview = ((int)$summary['dueOrOverdueCount'] + (int)$summary['dueSoonCount']) > 0;
        } else {
            $anythingToReview = $summary['dueOrOverdueCount'] > 0;
        }

        // If there is nothing for the user to review, no need to send an email
        if (!$anythingToReview) {
            return false;
        }

        $previewLimit = 5;

        $itemsOverdue = ReviewScheduleQuery::buildEntryRows(ReviewScheduleQuery::queryOverdue($reviewer)->limit($previewLimit));
        $itemsDueToday = ReviewScheduleQuery::buildEntryRows(ReviewScheduleQuery::queryDueToday($reviewer)->limit($previewLimit));
        $itemsDueSoon = ReviewScheduleQuery::buildEntryRows(ReviewScheduleQuery::queryDueSoon($reviewer)->limit($previewLimit));

        // Prepare data for template
        $context = [
            'summary' => $summary,
            'dueSoonDays' => ContentReviewPlugin::getInstance()->getSettings()->dueSoonDays,
            'reviewer' => $reviewer,
            'reviewsUrl' => $reviewer ? UrlHelper::cpUrl('content-review/my-reviews') : UrlHelper::cpUrl('content-review/all-reviews'),
            'reviewableContentLabel' => Craft::t(
                'content-review',
                $reviewer ? 'of your assigned entries' : 'of scheduled entries'
            ),
            'previewLimit' => $previewLimit,
            'itemsOverdue' => $itemsOverdue,
            'itemsDueToday' => $itemsDueToday,
            'itemsDueSoon' => $itemsDueSoon,
            'forDate' => Calendar::todayYmd(),
        ];

        // Render template - custom or plugin-defined
        $view = Craft::$app->getView();
        if ($settings->customDigestTemplate) {
            $oldMode = $view->getTemplateMode();
            $view->setTemplateMode($view::TEMPLATE_MODE_SITE);

            try {
                $htmlBody = $view->renderTemplate($settings->customDigestTemplate, $context);
            } finally {
                $view->setTemplateMode($oldMode);
            }
        } else {
            $htmlBody = $view->renderTemplate('content-review/emails/digest', $context);
        }

        return $htmlBody;
    }

    private function sendDigestEmail(string $emailAddress, string $subject, string $emailBody): bool
    {
        try {
            return Craft::$app->getMailer()
                ->compose()
                ->setTo($emailAddress)
                ->setSubject($subject)
                ->setHtmlBody($emailBody)
                ->send();
        } catch (\Throwable $exception) {
            Craft::error(
                sprintf(
                    'Could not send Content Review digest to %s: %s',
                    $emailAddress,
                    $exception->getMessage()
                ),
                __METHOD__
            );

            return false;
        }
    }
}
