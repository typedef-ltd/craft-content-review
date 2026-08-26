<?php

namespace typedef\contentreview;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\i18n\Formatter;
use craft\i18n\Locale;
use craft\services\Elements;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use Throwable;
use typedef\contentreview\elements\actions\MarkAsReviewedAction;
use typedef\contentreview\helpers\Calendar;
use typedef\contentreview\helpers\ReviewScheduleQuery;
use typedef\contentreview\models\Settings as ContentReviewSettings;
use typedef\contentreview\queue\RecalculateReviewSchedulesJob;
use typedef\contentreview\services\DigestService;
use typedef\contentreview\services\ReviewService;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\Model as YiiModel;
use yii\base\ModelEvent;
use yii\web\Response;

/**
 * Content Review plugin
 *
 * @method static ContentReviewPlugin getInstance()
 * @method ContentReviewSettings getSettings()
 *
 * @property-read ReviewService $reviewService
 * @property-read DigestService $digestService
 * @property-read Response $settingsResponse
 * @property-read null|array $cpNavItem
 * @property-read ContentReviewSettings $settings
 */
class ContentReviewPlugin extends Plugin
{
    /** @noinspection PropertyInitializationFlawsInspection */
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        // Register services
        $this->setComponents([
            'reviewService' => ReviewService::class,
            'digestService' => DigestService::class,
        ]);

        $this->attachEventHandlers();
    }

    protected function afterInstall(): void
    {
        // Recalculate all review schedules after install
        Craft::$app->getQueue()->push(new RecalculateReviewSchedulesJob());
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['subnav'] = [];

        if (Craft::$app->getUser()->checkPermission('content-review:viewAllReviews')) {
            $item['subnav']['all-reviews'] = [
                'label' => Craft::t('content-review', 'All reviews'),
                'url' => 'content-review/all-reviews/overview',
            ];
        }

        $item['subnav']['my-reviews'] = [
            'label' => Craft::t('content-review', 'My reviews'),
            'url' => 'content-review/my-reviews/overview',
            'badgeCount' => (int)ReviewScheduleQuery::queryDueAndOverdue(Craft::$app->getUser()->getIdentity())->count(),
        ];

        if (Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('content-review', 'Settings'),
                'url' => 'content-review/settings',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new ContentReviewSettings();
    }

    /**
     * Force a custom template for our plugin settings page, so all content review CP pages can be in one place.
     */
    public function getSettingsResponse(): Response
    {
        return Craft::$app->controller->redirect(UrlHelper::cpUrl('content-review/settings'));
    }

    private function attachEventHandlers(): void
    {
        // Register user permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('content-review', 'Content Review'),
                    'permissions' => [
                        'content-review:viewAllReviews' => [
                            'label' => Craft::t('content-review', 'View all reviews'),
                        ],
                    ],
                ];
            }
        );

        // Register CP template routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['content-review'] = ['template' => 'content-review/cp/index'];

                $event->rules['content-review/all-reviews/overview'] = 'content-review/cp/all-reviews-overview-page';
                $event->rules['content-review/all-reviews/overdue'] = 'content-review/cp/all-reviews-overdue-page';
                $event->rules['content-review/all-reviews/due-today'] = 'content-review/cp/all-reviews-due-today-page';
                $event->rules['content-review/all-reviews/due-soon'] = 'content-review/cp/all-reviews-due-soon-page';

                $event->rules['content-review/my-reviews/overview'] = 'content-review/cp/my-reviews-overview-page';
                $event->rules['content-review/my-reviews/overdue'] = 'content-review/cp/my-reviews-overdue-page';
                $event->rules['content-review/my-reviews/due-today'] = 'content-review/cp/my-reviews-due-today-page';
                $event->rules['content-review/my-reviews/due-soon'] = 'content-review/cp/my-reviews-due-soon-page';

                if (Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
                    $event->rules['content-review/settings'] = 'content-review/cp/settings-page';
                }
            }
        );

        // Add a "review status" column to entries index
        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            static function(RegisterElementTableAttributesEvent $event): void {
                $event->tableAttributes['contentReviewStatus'] = [
                    'label' => Craft::t('content-review', 'Review status'),
                ];
            }
        );

        // Generate and render the "review status" column for each entry
        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            static function(DefineAttributeHtmlEvent $event): void {
                if ($event->attribute !== 'contentReviewStatus') {
                    return;
                }

                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }

                // Never show this on Matrix/nested entries
                if ($entry->getOwner()) {
                    $event->html = '—';
                    return;
                }

                $status = ContentReviewPlugin::getInstance()->getReviewService()->getReviewStatusForEntry($entry);

                $indicatorHtml = Cp::statusIndicatorHtml($status['status'], [
                    'label' => $status['label'],
                    'color' => $status['color'],
                ]);

                $labelHtml = Html::tag('span', Html::encode($status['label']));

                $metaHtml = '';
                if ($status['dateLabel'] !== null) {
                    $metaHtml = Html::tag(
                        'span',
                        Html::encode($status['dateLabel']),
                        ['class' => 'light']
                    );
                }

                $event->html = Html::tag(
                    'div',
                    $indicatorHtml . ' ' . $labelHtml . $metaHtml,
                    ['class' => 'contentreview-status-cell']
                );
            }
        );

        // Register 'mark as reviewed' action for entry indexes
        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_ACTIONS,
            static function(RegisterElementActionsEvent $event): void {
                $event->actions[] = MarkAsReviewedAction::class;
            }
        );

        // Add content review details to the sidebar on the edit entry screen
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            static function(DefineHtmlEvent $event): void {
                $entry = $event->sender;

                // Only Entries should have a content review sidebar pane
                if (!$entry instanceof Entry) {
                    return;
                }

                // Craft 5: don't do content review for Matrix Blocks
                if ($entry->getOwner()) {
                    return;
                }

                // Skip for revisions
                if ($entry->getIsRevision()) {
                    return;
                }

                // Skip drafts - except those that are provisional, i.e. part of Craft's normal editing workflow and which want the content review pane available
                if (!$entry->isProvisionalDraft && $entry->getIsDraft()) {
                    return;
                }

                // New/unsaved entries have no ID yet — nothing to show
                if (!$entry->id) {
                    return;
                }

                $elementId = (int)($entry->canonicalId ?? $entry->id);
                $siteId = (int)$entry->siteId;
                $settings = ContentReviewPlugin::getInstance()->getSettings();

                $schedule = ContentReviewPlugin::getInstance()->getReviewService()->getReviewSchedule($elementId, $siteId);

                $site = Craft::$app->getSites()->getSiteById($siteId);
                $section = $entry->getSection();
                $sectionUid = $section->uid ?? null;

                $disabledBySite = $site === null || !$settings->isSiteEnabledByUid($site->uid);
                $disabledBySection = $sectionUid !== null && !$settings->isSectionEnabledByUid($sectionUid);

                // Don't show if disabled by global rules
                if ($disabledBySite || $disabledBySection) {
                    return;
                }

                // Friendly "effective due" datetime (date from schedule from settings)
                $effectiveNextReviewDateFormatted = '—';
                if ($schedule && $schedule->nextReviewOn) {
                    // Build a per-request formatter without mutating the global one
                    /** @var Formatter $formatter */
                    $formatter = clone Craft::$app->getFormatter();

                    $identity = Craft::$app->getUser()->getIdentity();
                    if ($identity && $identity->getPreferredLocale()) {
                        $locale = new Locale($identity->getPreferredLocale());
                    } else {
                        $locale = Craft::$app->getFormattingLocale();
                    }

                    $formatter->locale = $locale->id;
                    $formatter->timeZone = Craft::$app->getTimeZone();

                    $effectiveNextReviewDateFormatted = $formatter->asDate($schedule->nextReviewOn, 'medium');
                }

                // Interval resolution (section → global)
                $sectionInterval = $sectionUid ? $settings->intervalDaysForSectionUid($sectionUid) : null;
                $effectiveIntervalDays = $settings->getResolvedIntervalDaysForSectionUid($sectionUid);
                $intervalSource = $sectionInterval !== null ? 'section' : 'global';

                // Render the pane
                $entryReviewPane = Craft::$app->getView()->renderTemplate('content-review/entry-pane', [
                    'settings' => $settings,
                    'entry' => $entry,
                    'siteId' => $siteId,
                    'schedule' => $schedule,
                    'effectiveNextReviewDateFormatted' => $effectiveNextReviewDateFormatted,
                    'explicitReviewOnValue' => $schedule->explicitReviewOn ?? null,
                    'effectiveIntervalDays' => $effectiveIntervalDays,
                    'intervalSource' => $intervalSource,
                ]);

                // Position at top or bottom of sidebar according to settings
                if ($settings->entryReviewPaneAtTop) {
                    $event->html = $entryReviewPane . $event->html;
                } else {
                    $event->html .= $entryReviewPane;
                }
            },
            null,
            false,
        );

        // Validate content review details before saving entry
        Event::on(
            Entry::class,
            YiiModel::EVENT_BEFORE_VALIDATE,
            static function(ModelEvent $event): void {
                // Only handle POST requests from the control panel
                $request = Craft::$app->getRequest();
                if (!$request->getIsCpRequest() || !$request->getIsPost()) {
                    return;
                }

                /** @var Entry $entry */
                $entry = $event->sender;

                if ($entry->propagating || !ReviewService::elementIsEligibleForContentReview($entry)) {
                    return;
                }

                $postedContentReview = $request->getBodyParam('contentreview');
                if (!is_array($postedContentReview) || count($postedContentReview) === 0) {
                    return;
                }

                // If the entry is being disabled, its reviewer/date settings are irrelevant
                $enabled = !ContentReviewPlugin::getInstance()->getSettings()->allowPerEntryDisabling || ($postedContentReview['enabled'] ?? true);
                if (!$enabled) {
                    return;
                }

                $explicitReviewOnDateObject = $postedContentReview['explicitReviewOn'] ?? null;
                $reviewerUserIdsArray = $postedContentReview['reviewerUserId'] ?? [];

                // Validate explicit date
                if ($explicitReviewOnDateObject) {
                    try {
                        Calendar::parseUiDate($explicitReviewOnDateObject);
                    } catch (Throwable) {
                        $entry->addError('contentReviewExplicitReviewOn', Craft::t('content-review', 'This is not a valid date.'));
                    }
                }

                // Validate reviewer
                if (is_array($reviewerUserIdsArray) && count($reviewerUserIdsArray) !== 0 && $reviewerUserIdsArray[0] !== null) {
                    $resolvedReviewer = Craft::$app->getUsers()->getUserById($reviewerUserIdsArray[0]);
                    if (!$resolvedReviewer) {
                        $entry->addError('contentReviewReviewerUserId', Craft::t('content-review', 'Selected reviewer does not exist.'));
                    } elseif (!$resolvedReviewer->active) {
                        $entry->addError('contentReviewReviewerUserId', Craft::t('content-review', 'Selected reviewer is not active.'));
                    } elseif (!Craft::$app->getElements()->canSave($entry, $resolvedReviewer)) {
                        $entry->addError('contentReviewReviewerUserId', Craft::t('content-review', 'Selected reviewer does not have permission to edit this entry.'));
                    }
                } else {
                    $existingSchedule = ContentReviewPlugin::getInstance()->getReviewService()->getReviewSchedule($entry->canonicalId ?? $entry->id, $entry->siteId);
                    if ($existingSchedule && $existingSchedule->reviewerUserId !== null) {
                        $entry->addError('contentReviewReviewerUserId', Craft::t('content-review', 'You must choose a reviewer'));
                    } else {
                        $author = $entry->getAuthor();

                        if (!$author) {
                            $entry->addError('contentReviewReviewerUserId', Craft::t('content-review', 'Entry must have an author before a reviewer can be set.'));
                        } elseif (!Craft::$app->getElements()->canSave($entry, $author)) {
                            $entry->addError('contentReviewReviewerUserId', Craft::t('content-review', 'The entry author cannot be assigned as reviewer because they do not have permission to edit this entry.'));
                        }
                    }
                }

                if ($entry->hasErrors()) {
                    Craft::$app->getSession()->setError(Craft::t('content-review', 'Please correct the Content Review settings.'));
                    $event->isValid = false; // cancel save
                }
            }
        );

        // Keep review schedules in sync whenever eligible entries are saved
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            static function(ElementEvent $event): void {
                /** @var Entry $entry */
                $entry = $event->element;

                if (!ReviewService::elementIsEligibleForContentReview($entry)) {
                    return;
                }

                $settings = ContentReviewPlugin::getInstance()->getSettings();
                $reviewService = ContentReviewPlugin::getInstance()->getReviewService();
                $request = Craft::$app->getRequest();

                $postedContentReview = null;

                // Get posted content review entry settings, where applicable
                if (!$entry->propagating && $request->getIsCpRequest() && $request->getIsPost()) {
                    $posted = $request->getBodyParam('contentreview');

                    if (is_array($posted) && count($posted) !== 0) {
                        $postedContentReview = $posted;
                    }
                }

                // If no posted entry settings, just ensure a schedule
                if ($postedContentReview === null) {
                    $reviewService->syncReviewScheduleForEntry($entry);
                    return;
                }

                // If per-entry disabling is unavailable, this entry is de-facto enabled
                $enabled = !$settings->allowPerEntryDisabling || ($postedContentReview['enabled'] ?? true);

                // If entry is disabled, just sync that fact to the schedule
                if (!$enabled) {
                    $reviewService->syncReviewScheduleForEntry($entry, false);
                    return;
                }

                // Update schedule with posted settings
                $reviewerUserId = $postedContentReview['reviewerUserId'][0] ?? null;
                $explicitReviewOn = isset($postedContentReview['explicitReviewOn']) ? Calendar::parseUiDate($postedContentReview['explicitReviewOn']) : null;
                $reviewService->syncReviewScheduleForEntry($entry, true, $reviewerUserId, $explicitReviewOn, true);
            }
        );

        // Recalculate schedules whenever the plugin settings change
        $settingsConfigPath = ProjectConfig::PATH_PLUGINS . ".{$this->handle}.settings";
        Craft::$app->getProjectConfig()
            ->onAdd($settingsConfigPath, static function(): void {
                Craft::$app->getQueue()->push(new RecalculateReviewSchedulesJob());
            })
            ->onUpdate($settingsConfigPath, static function(): void {
                Craft::$app->getQueue()->push(new RecalculateReviewSchedulesJob());
            });
    }

    /**
     * @return ReviewService
     * @throws InvalidConfigException
     */
    public function getReviewService(): ReviewService
    {
        return $this->get('reviewService');
    }

    /**
     * @return DigestService
     * @throws InvalidConfigException
     */
    public function getDigestService(): DigestService
    {
        return $this->get('digestService');
    }
}
