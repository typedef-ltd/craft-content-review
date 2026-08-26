<?php

declare(strict_types=1);

namespace typedef\contentreview\records\db;

use craft\db\ActiveQuery;
use typedef\contentreview\records\ReviewScheduleRecord;

/**
 * @method ReviewScheduleRecord|null one($db = null)
 * @method ReviewScheduleRecord[]    all($db = null)
 */
class ReviewScheduleRecordQuery extends ActiveQuery
{
    /** @return $this */
    public function elementId(int $id): self
    {
        return $this->andWhere(['elementId' => $id]);
    }

    /** @return $this */
    public function siteId(int $id): self
    {
        return $this->andWhere(['siteId' => $id]);
    }
}
