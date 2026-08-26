<?php

declare(strict_types=1);

namespace typedef\contentreview\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use typedef\contentreview\ContentReviewPlugin;
use yii\console\ExitCode;

class ReviewSchedulesController extends Controller
{
    private const BATCH_SIZE = 100;

    public function actionRecalculate(): int
    {
        $reviewService = ContentReviewPlugin::getInstance()->getReviewService();

        $query = Entry::find()
            ->status(null)
            ->siteId('*');

        $total = 0;
        $changed = 0;

        /** @var Entry[] $entries */
        foreach ($query->batch(self::BATCH_SIZE) as $entries) {
            foreach ($entries as $entry) {
                $total++;

                if ($reviewService->syncReviewScheduleForEntry($entry) !== null) {
                    $changed++;
                }
            }
        }

        $this->stdout("Content Review schedules recalculated. Total: {$total}, Changed: {$changed}\n");

        return ExitCode::OK;
    }
}
