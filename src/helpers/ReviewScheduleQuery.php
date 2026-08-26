<?php

namespace typedef\contentreview\helpers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\records\ReviewScheduleRecord;

final class ReviewScheduleQuery
{
    public const TABLE_ALIAS = 's';

    /**
     * Returns a SQL column name with its alias prefix
     *
     * E.g. 'lastReviewedOn' => 's.lastReviewedOn'
     */
    public static function alias(string $columnName): string
    {
        return self::TABLE_ALIAS . '.' . $columnName;
    }

    public static function queryDueAndOverdue(?User $reviewer = null): Query
    {
        return self::buildBaseQuery($reviewer)
            ->andWhere(['<=', self::alias('nextReviewOn'), Calendar::todayYmd()]);
    }

    public static function queryOverdue(?User $reviewer = null): Query
    {
        return self::buildBaseQuery($reviewer)
            ->andWhere(['<', self::alias('nextReviewOn'), Calendar::todayYmd()]);
    }

    public static function queryDueToday(?User $reviewer = null): Query
    {
        return self::buildBaseQuery($reviewer)
            ->andWhere([self::alias('nextReviewOn') => Calendar::todayYmd()]);
    }

    public static function queryDueSoon(?User $reviewer = null): Query
    {
        $today = Calendar::todayYmd();

        $dueSoonDays = ContentReviewPlugin::getInstance()->getSettings()->dueSoonDays;
        $soon = Calendar::addDays($today, $dueSoonDays);

        return self::buildBaseQuery($reviewer)
            ->andWhere(['>', self::alias('nextReviewOn'), $today])
            ->andWhere(['<=', self::alias('nextReviewOn'), $soon]);
    }

    public static function buildBaseQuery(?User $reviewer = null): Query
    {
        $settings = ContentReviewPlugin::getInstance()->getSettings();

        // Filter by enabled site IDs if not all sites are using content review
        /** @var Array<int>|null $siteIds */
        $siteIds = null;
        if (!in_array('all', $settings->enabledSites, true)) {
            $allSites = Craft::$app->getSites()->getAllSites();

            $uids = array_flip($settings->enabledSites);
            $siteIds = [];
            foreach ($allSites as $site) {
                if (isset($uids[$site->uid])) {
                    $siteIds[] = (int)$site->id;
                }
            }
        }

        // Filter by enabled section IDs if not all sections are using content review
        /** @var Array<int>|null $sectionIds */
        $sectionIds = null;
        if (!in_array('all', $settings->enabledSections, true)) {
            $allSections = Craft::$app->getEntries()->getAllSections();

            $uids = array_flip($settings->enabledSections);
            $sectionIds = [];
            foreach ($allSections as $section) {
                if (isset($uids[$section->uid])) {
                    $sectionIds[] = (int)$section->id;
                }
            }
        }

        $query = (new Query())
            ->from([self::TABLE_ALIAS => ReviewScheduleRecord::tableName()])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[s.elementId]]')
            ->innerJoin(
                ['elementSites' => Table::ELEMENTS_SITES],
                '[[elementSites.elementId]] = [[s.elementId]] AND [[elementSites.siteId]] = [[s.siteId]]'
            )
            ->select([
                self::alias('elementId'),
                self::alias('siteId'),
                self::alias('nextReviewOn'),
                self::alias('reviewerUserId'),
            ])
            ->orderBy([
                self::alias('nextReviewOn') => SORT_ASC,
                self::alias('elementId') => SORT_ASC,
                self::alias('siteId') => SORT_ASC,
            ])
            ->where([
                self::alias('elementType') => Entry::class,
                self::alias('enabled') => 1,
                'elements.archived' => false,
                'elements.dateDeleted' => null,
            ])
            ->andWhere(['not', [self::alias('nextReviewOn') => null]]);

        if ($siteIds === []) {
            $query->andWhere('0=1');
        } elseif ($siteIds !== null) {
            $query->andWhere([self::alias('siteId') => $siteIds]);
        }

        if ($sectionIds === []) {
            $query->andWhere('0=1');
        } elseif ($sectionIds !== null) {
            $query
                ->innerJoin('{{%entries}} entries', 'entries.id = ' . self::alias('elementId'))
                ->andWhere(['entries.sectionId' => $sectionIds]);
        }

        if ($reviewer !== null) {
            $query->andWhere([self::alias('reviewerUserId') => $reviewer->id]);
        }

        return $query;
    }

    public static function buildSummary(?User $reviewer = null): array
    {
        $overdueCount = (int)self::queryOverdue($reviewer)->count();
        $dueTodayCount = (int)self::queryDueToday($reviewer)->count();
        $dueSoonCount = (int)self::queryDueSoon($reviewer)->count();
        $dueOrOverdueCount = $overdueCount + $dueTodayCount;
        $totalScheduledCount = (int)self::buildBaseQuery($reviewer)->count();

        $overduePercent = 0;
        $dueTodayPercent = 0;
        $dueSoonPercent = 0;

        if ($totalScheduledCount > 0) {
            $overduePercent = round(($overdueCount / $totalScheduledCount) * 100, 1);
            if ($overduePercent >= 10) {
                $overduePercent = round($overduePercent);
            }
            $dueTodayPercent = round(($dueTodayCount / $totalScheduledCount) * 100, 1);
            if ($dueTodayPercent >= 10) {
                $dueTodayPercent = round($dueTodayPercent);
            }
            $dueSoonPercent = round(($dueSoonCount / $totalScheduledCount) * 100, 1);
            if ($dueSoonPercent >= 10) {
                $dueSoonPercent = round($dueSoonPercent);
            }
        }

        return [
            'overdueCount' => $overdueCount,
            'dueTodayCount' => $dueTodayCount,
            'dueSoonCount' => $dueSoonCount,
            'totalScheduledCount' => $totalScheduledCount,
            'dueOrOverdueCount' => $dueOrOverdueCount,
            'overduePercent' => $overduePercent,
            'dueTodayPercent' => $dueTodayPercent,
            'dueSoonPercent' => $dueSoonPercent,
        ];
    }

    public static function buildEntryRows(Query $query, bool $includeReviewer = false): array
    {
        return self::buildEntryRowsFromEntriesArray($query->all(), $includeReviewer);
    }

    public static function buildEntryRowsFromEntriesArray(array $rows, bool $includeReviewer = false): array
    {
        $elementIds = [];
        $siteIds = [];
        $reviewerUserIds = [];

        foreach ($rows as $row) {
            $elementIds[] = (int)$row['elementId'];
            $siteIds[] = (int)$row['siteId'];

            if ($includeReviewer && $row['reviewerUserId'] !== null) {
                $reviewerUserIds[] = (int)$row['reviewerUserId'];
            }
        }

        $entryMap = [];

        if ($elementIds !== [] && $siteIds !== []) {
            $entries = Entry::find()
                ->id(array_values(array_unique($elementIds)))
                ->siteId(array_values(array_unique($siteIds)))
                ->status(null)
                ->all();

            foreach ($entries as $entry) {
                $entryMap[$entry->id . ':' . $entry->siteId] = $entry;
            }
        }

        $reviewerMap = [];

        if ($includeReviewer && $reviewerUserIds !== []) {
            $reviewers = User::find()
                ->id(array_values(array_unique($reviewerUserIds)))
                ->status(null)
                ->all();

            foreach ($reviewers as $reviewer) {
                $reviewerMap[$reviewer->id] = $reviewer;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $entryKey = (int)$row['elementId'] . ':' . (int)$row['siteId'];
            $entry = $entryMap[$entryKey] ?? null;

            if ($entry === null) {
                continue;
            }

            $item = [
                'entry' => $entry,
                'due' => (string)$row['nextReviewOn'],
            ];

            if ($includeReviewer) {
                $reviewerUserId = $row['reviewerUserId'] !== null ? (int)$row['reviewerUserId'] : null;
                $item['reviewer'] = $reviewerUserId !== null ? ($reviewerMap[$reviewerUserId] ?? null) : null;
            }

            $items[] = $item;
        }

        return $items;
    }
}
