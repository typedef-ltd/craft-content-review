<?php

declare(strict_types=1);

namespace typedef\contentreview\models;

use craft\base\Model;
use craft\elements\Entry;
use DateTimeInterface;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\helpers\Calendar;

/**
 * @property-read null|int $daysUntilDue
 * @property-read bool $isOverdue
 * @property-read bool $isDueSoon
 * @property-read bool $isWithinReviewWindow
 * @property-read bool $isDueToday
 */
class ReviewSchedule extends Model
{
    public ?int $id = null;
    public int $elementId;
    /**
     * Future-proofing (for now all schedules are for entries)
     */
    public string $elementType = Entry::class;
    public int $siteId;
    /**
     * @var string|null Date formatted as 'YYYY-MM-DD'
     */
    public ?string $explicitReviewOn = null;
    /**
     * @var string|null Date formatted as 'YYYY-MM-DD'
     */
    public ?string $nextReviewOn = null;
    /**
     * @var string|null Date formatted as 'YYYY-MM-DD'
     */
    public ?string $lastReviewedOn = null;
    public ?int $reviewerUserId = null;
    public bool $enabled = true;
    public ?DateTimeInterface $dateCreated = null;
    public ?DateTimeInterface $dateUpdated = null;

    public function rules(): array
    {
        return [
            [['enabled'], 'boolean'],
            [['elementId', 'siteId'], 'required'],
            [['elementId', 'siteId', 'reviewerUserId'], 'integer', 'min' => 1],
            [['explicitReviewOn', 'nextReviewOn', 'lastReviewedOn'], 'safe'],
        ];
    }

    public function getIsOverdue(): bool
    {
        if ($this->nextReviewOn === null) {
            return false;
        }

        return Calendar::isPast($this->nextReviewOn);
    }

    public function getIsDueToday(): bool
    {
        if ($this->nextReviewOn === null) {
            return false;
        }

        return Calendar::isToday($this->nextReviewOn);
    }

    public function getIsDueSoon(): bool
    {
        if ($this->nextReviewOn === null) {
            return false;
        }

        $today = Calendar::todayYmd();

        $dueSoonDays = ContentReviewPlugin::getInstance()->getSettings()->dueSoonDays;
        $soon = Calendar::addDays($today, $dueSoonDays);

        return $this->nextReviewOn > $today && $this->nextReviewOn <= $soon;
    }

    public function getIsWithinReviewWindow(): bool
    {
        if ($this->nextReviewOn === null) {
            return false;
        }

        if ($this->getIsOverdue() || $this->getIsDueToday() || $this->getIsDueSoon()) {
            return true;
        }

        return false;
    }

    /**
     * Number of days until due
     *
     * Will be negative if overdue, or null if there's no scheduled review record
     */
    public function getDaysUntilDue(): ?int
    {
        if ($this->nextReviewOn === null) {
            return null;
        }

        return Calendar::daysUntil($this->nextReviewOn);
    }
}
