<?php

namespace typedef\contentreview\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use craft\records\Site;
use craft\records\User;
use DateTime;
use typedef\contentreview\records\db\ReviewRecordQuery;
use yii\db\ActiveQuery;

/**
 * @property-read ActiveQuery $site
 * @property-read ActiveQuery $reviewer
 * @property-read ActiveQuery $element
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property string $dueOn 'YYYY-MM-DD'
 * @property string $reviewedOn 'YYYY-MM-DD'
 * @property int|null $reviewedByUserId
 * @property DateTime $dateCreated
 */
class ReviewRecord extends ActiveRecord
{
    public const TABLE_NAME = 'contentreview_reviews';

    public static function tableName(): string
    {
        return '{{%' . static::TABLE_NAME . '}}';
    }

    public static function find(): ReviewRecordQuery
    {
        return new ReviewRecordQuery(static::class);
    }

    public function rules(): array
    {
        return [
            [['elementId', 'siteId', 'dueOn', 'reviewedOn'], 'required'],
            [['elementId', 'siteId', 'reviewedByUserId'], 'integer'],

            [['dueOn', 'reviewedOn'], 'safe'],

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
                ['reviewedByUserId'],
                'exist',
                'skipOnEmpty' => true,
                'targetClass' => User::class,
                'targetAttribute' => ['reviewedByUserId' => 'id'],
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
        return self::hasOne(User::class, ['id' => 'reviewedByUserId']);
    }
}
