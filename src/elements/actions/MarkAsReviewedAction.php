<?php

declare(strict_types=1);

namespace typedef\contentreview\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use typedef\contentreview\ContentReviewPlugin;

class MarkAsReviewedAction extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('content-review', 'Mark as reviewed');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $entries = $query
            ->status(null)
            ->all();

        $markedCount = 0;

        foreach ($entries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            try {
                $marked = ContentReviewPlugin::getInstance()
                    ->getReviewService()
                    ->markEntryAsReviewed($entry);
            } catch (\Throwable $exception) {
                Craft::error(
                    sprintf(
                        'Could not mark entry #%d as reviewed: %s',
                        $entry->id,
                        $exception->getMessage()
                    ),
                    __METHOD__
                );

                continue;
            }

            if ($marked) {
                $markedCount++;
            }
        }

        if ($markedCount === 0) {
            $this->setMessage(Craft::t('content-review', 'No entries were marked as reviewed.'));
            return true;
        }

        $this->setMessage(Craft::t(
            'content-review',
            '{count} entries marked as reviewed.',
            ['count' => $markedCount]
        ));

        return true;
    }
}
