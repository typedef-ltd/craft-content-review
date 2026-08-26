<?php

declare(strict_types=1);

namespace typedef\contentreview\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\enums\Color;
use RuntimeException;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\models\ReviewSchedule;
use typedef\contentreview\records\ReviewRecord;
use typedef\contentreview\records\ReviewScheduleRecord;
use yii\base\InvalidConfigException;
use yii\db\Exception;
use yii\db\Transaction;

class ReviewService extends Component
{
    /**
     * Read-only fetch by (elementId, siteId).
     */
    public function getReviewSchedule(int $elementId, int $siteId): ?ReviewSchedule
    {
        $rec = ReviewScheduleRecord::find()
            ->elementId($elementId)
            ->siteId($siteId)
            ->one();

        return $rec?->toModel();
    }

    /**
     * @throws RuntimeException|Exception
     */
    public function saveReviewSchedule(ReviewSchedule $schedule): void
    {
        $record = ReviewScheduleRecord::fromModel($schedule);

        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'Could not save review schedule for element %d, site %d: %s',
                $schedule->elementId,
                $schedule->siteId,
                implode('; ', $record->getErrorSummary(true)),
            ));
        }
    }

    /**
     * Returns a lightweight review-status payload for an entry index cell.
     *
     * @return array{
     *     status: string,
     *     label: string,
     *     color: Color,
     *     dateLabel: string|null,
     * }
     */
    public function getReviewStatusForEntry(Entry $entry): array
    {
        $settings = ContentReviewPlugin::getInstance()->getSettings();
        $site = Craft::$app->getSites()->getSiteById($entry->siteId);
        $sectionUid = $entry->getSection()?->uid ?? null;

        $disabledBySite = $site === null || !$settings->isSiteEnabledByUid($site->uid);
        $disabledBySection = $sectionUid !== null && !$settings->isSectionEnabledByUid($sectionUid);

        if ($disabledBySite || $disabledBySection) {
            return [
                'status' => 'disabled',
                'label' => Craft::t('content-review', 'Disabled'),
                'color' => Color::Gray,
                'dateLabel' => null,
            ];
        }

        $elementId = (int)($entry->canonicalId ?? $entry->id);
        $siteId = (int)$entry->siteId;

        $schedule = $this->getReviewSchedule($elementId, $siteId);

        if ($schedule === null) {
            return [
                'status' => 'unscheduled',
                'label' => Craft::t('content-review', 'Unscheduled'),
                'color' => Color::Gray,
                'dateLabel' => null,
            ];
        }

        if (!$schedule->enabled) {
            return [
                'status' => 'disabled',
                'label' => Craft::t('content-review', 'Disabled'),
                'color' => Color::Gray,
                'dateLabel' => null,
            ];
        }

        if ($schedule->nextReviewOn === null) {
            return [
                'status' => 'unscheduled',
                'label' => Craft::t('content-review', 'Unscheduled'),
                'color' => Color::Gray,
                'dateLabel' => null,
            ];
        }

        $dateLabel = Craft::$app->getFormatter()->asDate($schedule->nextReviewOn, 'short');

        if ($schedule->getIsOverdue()) {
            return [
                'status' => 'overdue',
                'label' => Craft::t('content-review', 'Overdue'),
                'color' => Color::Red,
                'dateLabel' => $dateLabel,
            ];
        }

        if ($schedule->getIsDueToday()) {
            return [
                'status' => 'due',
                'label' => Craft::t('content-review', 'Due today'),
                'color' => Color::Amber,
                'dateLabel' => null,
            ];
        }

        if ($schedule->getIsDueSoon()) {
            return [
                'status' => 'due-soon',
                'label' => Craft::t('content-review', 'Due soon'),
                'color' => Color::Yellow,
                'dateLabel' => $dateLabel,
            ];
        }

        return [
            'status' => 'scheduled',
            'label' => Craft::t('content-review', 'Next review due'),
            'color' => Color::White,
            'dateLabel' => $dateLabel,
        ];
    }

    /**
     * Creates or updates the review schedule for an entry and recalculates its next review date
     *
     * - Creates a new schedule if one does not exist
     * - Updates reviewer and/or explicit review date if provided
     * - Recalculates nextReviewOn based on explicit date, lastReviewedOn, or last meaningful content update
     *
     * @param Entry $entry The eligible canonical entry to process
     * @param int|null $reviewerUserId Optional reviewer user ID to assign; if null, defaults may apply
     * @param string|null $explicitReviewOn Optional explicit next review date (Y-m-d); overrides calculated date
     * @param bool $clearExplicitDateIfNull Whether to clear any existing explicit review date when null is passed
     * @param bool $dryRun If true, do not persist changes; only compute and return the result
     *
     * @return ReviewSchedule|null The resulting schedule, or null if no changes were made or the entry is not eligible
     *
     * @throws InvalidConfigException
     */
    public function syncReviewScheduleForEntry(Entry $entry, ?bool $enabled = null, ?int $reviewerUserId = null, ?string $explicitReviewOn = null, bool $clearExplicitDateIfNull = false, bool $dryRun = false): ?ReviewSchedule
    {
        if (!self::elementIsEligibleForContentReview($entry)) {
            return null;
        }

        $settings = ContentReviewPlugin::getInstance()->getSettings();
        $elementId = (int)($entry->canonicalId ?? $entry->id);
        $siteId = (int)$entry->siteId;

        $isNew = true;
        $schedule = $this->getReviewSchedule($elementId, $siteId);
        if ($schedule) {
            $isNew = false;
        } else {
            $schedule = new ReviewSchedule();
            $schedule->elementId = $elementId;
            $schedule->siteId = $siteId;
        }

        // Temporarily remember existing settings in order to work out whether we need to save the record
        $previousEnabled = $schedule->enabled;
        $previousNextReviewOn = $schedule->nextReviewOn;
        $previousExplicitReviewOn = $schedule->explicitReviewOn;
        $previousReviewerUserId = $schedule->reviewerUserId;

        // Update the enabled toggle on the review schedule, if set
        if ($enabled !== null) {
            $schedule->enabled = $enabled;
        }

        // If the schedule is disabled, decide what to do...
        if (!$schedule->enabled) {
            if (!$settings->allowPerEntryDisabling) {
                // If per-entry disabling is not allowed, force the entry to be enabled
                $schedule->enabled = true;
            } elseif ($previousEnabled !== $schedule->enabled) {
                // If we're disabling the entry, then just save the disabled setting here and return early
                if (!$dryRun) {
                    $this->saveReviewSchedule($schedule);
                }

                return $schedule;
            } else {
                // Entry is already disabled, so just return without doing anything
                return null;
            }
        }

        // Set reviewer
        // If no reviewer set manually, and this is a new entry and/or there is no saved reviewer, default to entry author as reviewer
        if ($reviewerUserId !== null) {
            $schedule->reviewerUserId = $reviewerUserId;
        } elseif ($isNew || $schedule->reviewerUserId === null) {
            $author = $entry->getAuthor();

            $schedule->reviewerUserId = $author && Craft::$app->getElements()->canSave($entry, $author) ? (int)$author->id : null;
        }

        $section = $entry->getSection();
        $sectionUid = $section->uid ?? null;

        // Decide whether to set the explicit date or not
        if (!$settings->allowPerEntryCustomDates || ($clearExplicitDateIfNull && $explicitReviewOn === null)) {
            $schedule->explicitReviewOn = null;
        } elseif ($explicitReviewOn !== null) {
            $schedule->explicitReviewOn = $explicitReviewOn;
        }

        // Set the next review date
        if ($schedule->explicitReviewOn) {
            // We're using an explicit date
            $schedule->nextReviewOn = $schedule->explicitReviewOn;
        } else {
            // Work out the interval between reviews for this entry
            $intervalDays = $settings->getResolvedIntervalDaysForSectionUid($sectionUid);

            // Calculate the next review date based on the interval and whether it has already been reviewed
            if (!$isNew && $schedule->lastReviewedOn) {
                // Already reviewed, so set the next review date based on an offset from that
                $schedule->nextReviewOn = Calendar::addDays($schedule->lastReviewedOn, $intervalDays);
            } else {
                // No existing review so we need to work out what date to use
                // First, try to use the last human revision of the content as a best-guess approximation of what might count as a "review"
                // Otherwise fall back to the last updated date, then created date, then today
                $latestRevisionDate = (new Query())
                    ->select(['elements.dateCreated'])
                    ->from(['revisions' => Table::REVISIONS])
                    ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.revisionId]] = [[revisions.id]]')
                    ->where(['revisions.canonicalId' => $elementId])
                    ->andWhere(['not', ['revisions.creatorId' => null]])
                    ->orderBy(['revisions.num' => SORT_DESC])
                    ->scalar();

                if (is_string($latestRevisionDate) && $latestRevisionDate !== '') {
                    $lastMeaningfulContentDate = Calendar::ymdFromDateTimeString($latestRevisionDate);
                } elseif ($entry->dateUpdated) {
                    $lastMeaningfulContentDate = Calendar::ymdFromDateTime($entry->dateUpdated);
                } elseif ($entry->postDate) {
                    $lastMeaningfulContentDate = Calendar::ymdFromDateTime($entry->postDate);
                } else {
                    $lastMeaningfulContentDate = Calendar::todayYmd();
                }

                $schedule->nextReviewOn = Calendar::addDays($lastMeaningfulContentDate, $intervalDays);
            }
        }

        // If the record is unchanged, then don't go any further
        if (
            !$isNew
            && $schedule->enabled === $previousEnabled
            && $schedule->reviewerUserId === $previousReviewerUserId
            && $schedule->nextReviewOn === $previousNextReviewOn
            && $schedule->explicitReviewOn === $previousExplicitReviewOn
        ) {
            return null;
        }

        if ($dryRun) {
            return $schedule;
        }

        $this->saveReviewSchedule($schedule);

        return $schedule;
    }

    /**
     * Marks an entry as reviewed and calculates its next review date
     *
     * @throws Exception
     * @throws InvalidConfigException
     * @throws \Throwable
     */
    public function markEntryAsReviewed(Entry $entry): bool
    {
        if (!self::elementIsEligibleForContentReview($entry)) {
            return false;
        }

        // Don't allow anyone to review who can't also save the entry
        if (!Craft::$app->getElements()->canSave($entry)) {
            return false;
        }

        $elementId = (int)($entry->canonicalId ?? $entry->id);
        $siteId = (int)$entry->siteId;
        $mutex = Craft::$app->getMutex();
        $mutexKey = "contentreview:review:{$elementId}:{$siteId}";

        if (!$mutex->acquire($mutexKey, 3)) {
            return false;
        }

        try {
            $settings = ContentReviewPlugin::getInstance()->getSettings();
            $sectionUid = $entry->getSection()->uid ?? null;

            // Fetch the schedule only after acquiring the lock, so concurrent requests
            // always re-check the latest persisted review state.
            $schedule = $this->getReviewSchedule($elementId, $siteId);
            if (!$schedule) {
                throw new InvalidConfigException("No schedule found for entry {$elementId}");
            }

            if (!$schedule->enabled || !$schedule->getIsWithinReviewWindow()) {
                return false;
            }

            // Create a new review history record
            $review = new ReviewRecord();
            $review->siteId = $siteId;
            $review->elementId = $elementId;
            $review->reviewedOn = Calendar::todayYmd();
            $review->reviewedByUserId = Craft::$app->getUser()->getIdentity()->id;
            $review->dueOn = $schedule->nextReviewOn;

            // Update schedule record
            $schedule->lastReviewedOn = $review->reviewedOn;
            $schedule->explicitReviewOn = null;

            $intervalDays = $settings->getResolvedIntervalDaysForSectionUid($sectionUid);
            $schedule->nextReviewOn = Calendar::addDays($schedule->lastReviewedOn, $intervalDays);

            // Save both records atomically
            /** @var Transaction $transaction */
            $transaction = Craft::$app->getDb()->beginTransaction();

            try {
                $this->saveReviewSchedule($schedule);

                if (!$review->save()) {
                    $transaction->rollBack();

                    return false;
                }

                $transaction->commit();

                return true;
            } catch (\Throwable $exception) {
                if ($transaction->getIsActive()) {
                    $transaction->rollBack();
                }

                throw $exception;
            }
        } finally {
            $mutex->release($mutexKey);
        }
    }

    public static function elementIsEligibleForContentReview(ElementInterface $element): bool
    {
        if (!$element instanceof Entry) {
            return false;
        }

        $entry = $element;

        if (
            $entry->getIsDraft()
            || $entry->getIsRevision()
            || !$entry->getIsCanonical()
            || $entry->getOwner()
        ) {
            return false;
        }

        $settings = ContentReviewPlugin::getInstance()->getSettings();

        // Skip if disabled by site
        $site = Craft::$app->getSites()->getSiteById($entry->siteId);
        if ($site === null || !$settings->isSiteEnabledByUid($site->uid)) {
            return false;
        }

        // Skip if disabled by section
        $sectionUid = $entry->getSection()?->uid;
        if ($sectionUid !== null && !$settings->isSectionEnabledByUid($sectionUid)) {
            return false;
        }

        return true;
    }
}
