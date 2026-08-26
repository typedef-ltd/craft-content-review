<?php

namespace typedef\contentreview\records;

use Craft;
use craft\db\ActiveRecord;
use craft\helpers\DateTimeHelper;
use craft\records\Element;
use craft\records\Site;
use craft\records\User;
use DateTime;
use typedef\contentreview\models\ReviewSchedule;
use typedef\contentreview\records\db\ReviewScheduleRecordQuery;
use yii\db\ActiveQuery;

/**
 * @property-read ActiveQuery $site
 * @property-read ActiveQuery $reviewer
 * @property-read ActiveQuery $element
 * @property int $id
 * @property int $elementId
 * @property string $elementType
 * @property int $siteId
 * @property int|null $reviewerUserId
 * @property string|null $nextReviewOn 'YYYY-MM-DD'
 * @property string|null $explicitReviewOn 'YYYY-MM-DD'
 * @property string|null $lastReviewedOn 'YYYY-MM-DD'
 * @property boolean $enabled
 * @property DateTime $dateCreated
 * @property DateTime $dateUpdated
 */
class ReviewScheduleRecord extends ActiveRecord
{
    public const TABLE_NAME = 'contentreview_review_schedules';

    public static function tableName(): string
    {
        return '{{%' . static::TABLE_NAME . '}}';
    }

    public static function find(): ReviewScheduleRecordQuery
    {
        return new ReviewScheduleRecordQuery(static::class);
    }

    public function rules(): array
    {
        return [
            [['enabled'], 'boolean'],
            [['elementId', 'siteId'], 'required'],
            [['elementId', 'siteId', 'reviewerUserId'], 'integer'],
            [['explicitReviewOn', 'nextReviewOn', 'lastReviewedOn'], 'safe'],

            // One review schedule per (element, site)
            [
                ['elementId', 'siteId'],
                'unique',
                'targetAttribute' => ['elementId', 'siteId'],
                'message' => Craft::t('content-review', 'A review schedule already exists for this entry and site.'),
            ],

            // FKs
            [
                ['elementId'],
                'exist',
                'targetClass' => Element::class,
                'targetAttribute' => ['elementId' => 'id'],
                'filter' => ['dateDeleted' => null],
            ],
            [
                ['siteId'],
                'exist',
                'targetClass' => Site::class,
                'targetAttribute' => ['siteId' => 'id'],
            ],
            [
                ['reviewerUserId'],
                'exist',
                'skipOnEmpty' => true,
                'targetClass' => User::class,
                'targetAttribute' => ['reviewerUserId' => 'id'],
            ],
        ];
    }

    public function getElement(): ActiveQuery
    {
        return self::hasOne(Element::class, ['id' => 'elementId']);
    }

    public function getSite(): ActiveQuery
    {
        return self::hasOne(Site::class, ['id' => 'siteId']);
    }

    public function getReviewer(): ActiveQuery
    {
        return self::hasOne(User::class, ['id' => 'reviewerUserId']);
    }

    public function toModel(): ReviewSchedule
    {
        return new ReviewSchedule([
            'id' => $this->id,
            'elementId' => $this->elementId,
            'elementType' => $this->elementType,
            'siteId' => $this->siteId,
            'explicitReviewOn' => $this->explicitReviewOn,
            'nextReviewOn' => $this->nextReviewOn,
            'lastReviewedOn' => $this->lastReviewedOn,
            'reviewerUserId' => $this->reviewerUserId ? (int)$this->reviewerUserId : null,
            'enabled' => $this->enabled,
            'dateCreated' => DateTimeHelper::toDateTime($this->dateCreated) ?: null,
            'dateUpdated' => DateTimeHelper::toDateTime($this->dateUpdated) ?: null,
        ]);
    }

    public static function fromModel(ReviewSchedule $model): self
    {
        /** @var self $record */
        $record = $model->id ? static::findOne($model->id) : new self();

        $record->elementId = $model->elementId;
        $record->elementType = $model->elementType;
        $record->siteId = $model->siteId;
        $record->explicitReviewOn = $model->explicitReviewOn;
        $record->nextReviewOn = $model->nextReviewOn;
        $record->lastReviewedOn = $model->lastReviewedOn;
        $record->reviewerUserId = $model->reviewerUserId;
        $record->enabled = $model->enabled;

        return $record;
    }
}
