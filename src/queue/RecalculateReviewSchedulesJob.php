<?php

declare(strict_types=1);

namespace typedef\contentreview\queue;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use typedef\contentreview\ContentReviewPlugin;
use yii\base\InvalidConfigException;

class RecalculateReviewSchedulesJob extends BaseJob
{
    private const BATCH_SIZE = 100;

    /**
     * @throws InvalidConfigException
     */
    public function execute($queue): void
    {
        $reviewService = ContentReviewPlugin::getInstance()->getReviewService();

        $baseQuery = Entry::find()
            ->status(null)
            ->siteId('*');

        $done = 0;
        $total = (int)(clone $baseQuery)->count();
        $changed = 0;

        /** @var Entry[] $entries */
        foreach ($baseQuery->batch(self::BATCH_SIZE) as $entries) {
            foreach ($entries as $entry) {
                $done++;

                if ($reviewService->syncReviewScheduleForEntry($entry) !== null) {
                    $changed++;
                }

                $this->setProgress($queue, $total > 0 ? $done / $total : 1.0);
            }
        }

        Craft::info(sprintf(
            '[content-review] Recalculate review schedules job complete. Total=%d, Changed=%d',
            $total,
            $changed,
        ), __METHOD__);
    }

    protected function defaultDescription(): string
    {
        return Craft::t('content-review', 'Recalculate Content Review schedules');
    }
}
