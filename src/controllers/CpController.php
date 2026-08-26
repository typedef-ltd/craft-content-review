<?php

namespace typedef\contentreview\controllers;

use Craft;
use craft\db\Paginator;
use craft\db\Query;
use craft\elements\User;
use craft\web\Controller;
use craft\web\twig\variables\Paginate;
use typedef\contentreview\ContentReviewPlugin;
use typedef\contentreview\helpers\ReviewScheduleQuery;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class CpController extends Controller
{
    private const OVERVIEW_PREVIEW_LIMIT = 5;
    private const LIST_PAGE_SIZE = 100;

    protected int|bool|array $allowAnonymous = false;

    public function actionSettingsPage(): Response
    {
        $this->requireCpRequest();
        $this->requireAdmin();

        $settings = ContentReviewPlugin::getInstance()->getSettings();
        $allSites = Craft::$app->getSites()->getAllSites();
        $allSections = Craft::$app->getEntries()->getAllSections();

        // Per-section interval rows (keyed by section UID)
        $perSectionRows = [];
        foreach ($allSections as $section) {
            $sectionUid = (string)$section->uid;
            $perSectionRows[$sectionUid] = [
                'section' => sprintf('%s (%s)', $section->name, $section->handle),
                'days' => isset($settings->intervalDaysPerSection[$sectionUid]) ? (string)(int)$settings->intervalDaysPerSection[$sectionUid] : '',
            ];
        }

        // Render
        return $this->renderTemplate('content-review/cp/settings', [
            'plugin' => $this,
            'settings' => $settings,
            // for template convenience
            'allSites' => $allSites,
            'allSections' => $allSections,
            // editable table sources
            'perSectionRows' => $perSectionRows,
        ]);
    }

    /**
     * @throws ForbiddenHttpException
     * @throws BadRequestHttpException
     */
    public function actionAllReviewsOverviewPage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-content-review');
        $this->requirePermission('content-review:viewAllReviews');

        return $this->renderReviewsOverviewPage('content-review/cp/all-reviews/overview', null, true);
    }

    public function actionAllReviewsOverduePage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-content-review');
        $this->requirePermission('content-review:viewAllReviews');

        return $this->renderReviewsListPage('content-review/cp/all-reviews/overdue', ReviewScheduleQuery::queryOverdue(), true);
    }

    public function actionAllReviewsDueTodayPage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-content-review');
        $this->requirePermission('content-review:viewAllReviews');

        return $this->renderReviewsListPage('content-review/cp/all-reviews/due-today', ReviewScheduleQuery::queryDueToday(), true);
    }

    public function actionAllReviewsDueSoonPage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-content-review');
        $this->requirePermission('content-review:viewAllReviews');

        return $this->renderReviewsListPage('content-review/cp/all-reviews/due-soon', ReviewScheduleQuery::queryDueSoon(), true,
            [
                'dueSoonDays' => ContentReviewPlugin::getInstance()->getSettings()->dueSoonDays,
            ]
        );
    }

    public function actionMyReviewsOverviewPage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-content-review');
        $reviewer = Craft::$app->getUser()->getIdentity();

        return $this->renderReviewsOverviewPage('content-review/cp/my-reviews/overview', $reviewer, false);
    }

    public function actionMyReviewsOverduePage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-content-review');
        $reviewer = Craft::$app->getUser()->getIdentity();

        return $this->renderReviewsListPage('content-review/cp/my-reviews/overdue', ReviewScheduleQuery::queryOverdue($reviewer));
    }

    public function actionMyReviewsDueTodayPage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-content-review');
        $reviewer = Craft::$app->getUser()->getIdentity();

        return $this->renderReviewsListPage('content-review/cp/my-reviews/due-today', ReviewScheduleQuery::queryDueToday($reviewer));
    }

    public function actionMyReviewsDueSoonPage(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-content-review');
        $reviewer = Craft::$app->getUser()->getIdentity();

        return $this->renderReviewsListPage('content-review/cp/my-reviews/due-soon', ReviewScheduleQuery::queryDueSoon($reviewer), false,
            [
                'dueSoonDays' => ContentReviewPlugin::getInstance()->getSettings()->dueSoonDays,
            ]
        );
    }

    private function renderReviewsOverviewPage(string $template, ?User $reviewer, bool $includeReviewer): Response
    {
        $summary = ReviewScheduleQuery::buildSummary($reviewer);

        $itemsOverdue = ReviewScheduleQuery::buildEntryRows(ReviewScheduleQuery::queryOverdue($reviewer)->limit(self::OVERVIEW_PREVIEW_LIMIT), $includeReviewer);
        $itemsDueToday = ReviewScheduleQuery::buildEntryRows(ReviewScheduleQuery::queryDueToday($reviewer)->limit(self::OVERVIEW_PREVIEW_LIMIT), $includeReviewer);
        $itemsDueSoon = ReviewScheduleQuery::buildEntryRows(ReviewScheduleQuery::queryDueSoon($reviewer)->limit(self::OVERVIEW_PREVIEW_LIMIT), $includeReviewer);

        return $this->renderTemplate($template, [
            'summary' => $summary,
            'dueSoonDays' => ContentReviewPlugin::getInstance()->getSettings()->dueSoonDays,
            'previewLimit' => self::OVERVIEW_PREVIEW_LIMIT,
            'itemsOverdue' => $itemsOverdue,
            'itemsDueToday' => $itemsDueToday,
            'itemsDueSoon' => $itemsDueSoon,
            'showReviewer' => $includeReviewer,
        ]);
    }

    private function renderReviewsListPage(string $template, Query $query, bool $includeReviewer = false, array $variables = []): Response
    {
        $paginator = new Paginator($query, [
            'pageSize' => self::LIST_PAGE_SIZE,
            'currentPage' => Craft::$app->getRequest()->getPageNum(),
        ]);

        return $this->renderTemplate($template, array_merge($variables, [
            'items' => ReviewScheduleQuery::buildEntryRowsFromEntriesArray($paginator->getPageResults(), $includeReviewer),
            'pageInfo' => Paginate::create($paginator),
            'showReviewer' => $includeReviewer,
        ]));
    }
}
