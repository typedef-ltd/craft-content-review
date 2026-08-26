<?php

namespace typedef\contentreview\console\controllers;

use craft\console\Controller;
use typedef\contentreview\ContentReviewPlugin;
use yii\console\ExitCode;

class DigestController extends Controller
{
    /** @var bool|null Force digests to send regardless of settings */
    public ?bool $force = false;

    public function options($actionID): array
    {
        $opts = parent::options($actionID);
        if ($actionID === 'send') {
            $opts[] = 'force';
        }
        return $opts;
    }

    /**
     * Sends scheduled digest emails
     */
    public function actionSend(): int
    {
        $settings = ContentReviewPlugin::getInstance()->getSettings();

        if (!$this->force) {
            // Fail silently if digest emails are disabled
            if (!$settings->sendDigests) {
                $this->stdout("Digest emails are currently disabled\n");
                return ExitCode::OK;
            }

            // Fail silently if today is not the day to send digest emails
            if (!$settings->getShouldSendDigestToday()) {
                $this->stdout("Skipping digests - digest emails are not scheduled to send today\n");
                return ExitCode::OK;
            }
        }

        $successfulRecipientCount = ContentReviewPlugin::getInstance()
            ->getDigestService()
            ->sendDigests((bool)$this->force);

        $this->stdout("Content Review sent {$successfulRecipientCount} digest email(s)\n");
        return ExitCode::OK;
    }
}
