<?php

namespace typedef\contentreview\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\web\View;
use typedef\contentreview\helpers\Calendar;
use yii\validators\EmailValidator;
use yii\validators\InlineValidator;

/**
 * Content Review settings
 */
class Settings extends Model
{
    /**
     * The default number of days between content reviews.
     * Can be overridden on a per-section basis.
     */
    public int $defaultIntervalDays = 180;

    /**
     * The number of days that counts as "due soon"
     */
    public int $dueSoonDays = 7;

    /**
     * Toggle: enable/disable per-section overrides of the default interval.
     */
    public bool $usePerSectionIntervals = false;

    /**
     * Per-section overrides of the default interval between content reviews.
     *
     * @var array<string,int>|array<int,array{sectionUid:string,days:int|string}>
     *      Either a map [sectionUid => days] or editableTable rows [{sectionUid, days}, ...]
     */
    public array $intervalDaysPerSection = [];

    /** @var string[] UIDs or ['all'] */
    public array $enabledSites = ['all'];

    /** @var string[] UIDs or ['all'] */
    public array $enabledSections = ['all'];

    /**
     * Whether content review can be disabled on a per-entry basis
     */
    public bool $allowPerEntryDisabling = true;

    /**
     * Whether entries can have custom dates on a per-entry basis
     */
    public bool $allowPerEntryCustomDates = false;

    /**
     * Whether to show the review pane at the top of the sidebar
     */
    public bool $entryReviewPaneAtTop = true;

    /**
     * Whether to send email digests
     */
    public bool $sendDigests = true;

    /**
     * Whether to send digests when the only content is "due soon"
     */
    public bool $sendDigestsIfOnlyDueSoon = true;

    /**
     * How often digest emails should be sent.
     * Supported values: daily, weekly
     */
    public string $digestFrequency = 'daily';

    /**
     * Which day weekly digests should be sent on.
     * 1 = Monday, 7 = Sunday
     */
    public int $digestWeekday = 1;

    /**
     * Comma-separated raw email list or env var reference
     */
    public ?string $globalDigestEmails = '';

    /**
     * Custom template to use for digest emails
     */
    public ?string $customDigestTemplate = null;

    public function getGlobalDigestEmails(): array
    {
        $raw = App::parseEnv($this->globalDigestEmails);

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $emailAddresses = array_map('trim', explode(',', $raw));
        $emailAddresses = array_filter(
            $emailAddresses,
            static fn(string $email_address): bool => $email_address !== ''
        );

        return array_values(array_unique($emailAddresses));
    }

    public function getShouldSendDigestToday(): bool
    {
        if ($this->digestFrequency === 'daily') {
            return true;
        }

        if ($this->digestFrequency === 'weekly') {
            return Calendar::dayOfWeek(Calendar::todayYmd()) === $this->digestWeekday;
        }

        return false;
    }

    public function rules(): array
    {
        return [
            [['defaultIntervalDays'], 'integer', 'min' => 1],
            [[
                'usePerSectionIntervals',
                'allowPerEntryDisabling',
                'allowPerEntryCustomDates',
                'entryReviewPaneAtTop',
                'sendDigests',
                'sendDigestsIfOnlyDueSoon',
            ], 'boolean'],

            [['enabledSites', 'enabledSections'], 'default', 'value' => ['all']],
            [['enabledSites', 'enabledSections'], 'each', 'rule' => ['string']],

            // Accept map or fixed-table rows; returns canonical map [uid => int]
            [['intervalDaysPerSection'], 'filter', 'filter' => [$this, 'normalizeIntervalDaysPerSection']],

            [['dueSoonDays'], 'integer', 'min' => 2],
            [['dueSoonDays'], 'validateDueSoonWindow'],

            [['digestFrequency'], 'default', 'value' => 'daily'],
            [['digestFrequency'], 'in', 'range' => ['daily', 'weekly']],

            [['digestWeekday'], 'default', 'value' => 1],
            [['digestWeekday'], 'integer', 'min' => 1, 'max' => 7],

            [['globalDigestEmails'], 'trim'],
            [['globalDigestEmails'], 'string'],
            [['globalDigestEmails'], 'validateGlobalDigestEmails'],

            [['customDigestTemplate'], 'trim'],
            [['customDigestTemplate'], 'string'],
            [['customDigestTemplate'], 'validateCustomDigestTemplate'],

            // Ensure mass assignment is allowed
            [[
                'defaultIntervalDays',
                'usePerSectionIntervals',
                'intervalDaysPerSection',
            ], 'safe'],
        ];
    }

    public function validateDueSoonWindow(string $attribute, mixed $params, InlineValidator $validator): void
    {
        if (
            $this->hasErrors('dueSoonDays')
            || $this->hasErrors('defaultIntervalDays')
        ) {
            return;
        }

        $reviewIntervals = [
            $this->defaultIntervalDays,
        ];

        if ($this->usePerSectionIntervals) {
            foreach ($this->intervalDaysPerSection as $days) {
                $reviewIntervals[] = (int)$days;
            }
        }

        $minimumReviewInterval = min($reviewIntervals);

        if ($this->dueSoonDays >= $minimumReviewInterval) {
            $this->addError(
                $attribute,
                Craft::t(
                    'content-review',
                    'The "Due soon" window must be shorter than every review interval. The shortest configured interval is {days} days.',
                    ['days' => $minimumReviewInterval]
                )
            );
        }
    }

    public function validateGlobalDigestEmails(string $attribute, mixed $params, InlineValidator $validator): void
    {
        $emailValidator = new EmailValidator();

        foreach ($this->getGlobalDigestEmails() as $emailAddress) {
            if (!$emailValidator->validate($emailAddress)) {
                $this->addError(
                    $attribute,
                    Craft::t('content-review', '"{email}" is not a valid email address.', ['email' => $emailAddress])
                );
            }
        }
    }

    public function validateCustomDigestTemplate(string $attribute, mixed $params, InlineValidator $validator): void
    {
        $template = $this->$attribute;

        if ($template === null || $template === '') {
            return;
        }

        $view = Craft::$app->getView();
        $oldTemplateMode = $view->getTemplateMode();

        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            if (!$view->doesTemplateExist($template)) {
                $this->addError($attribute, Craft::t('content-review', 'Template does not exist.'));
            }
        } finally {
            $view->setTemplateMode($oldTemplateMode);
        }
    }

    public function isSiteEnabledByUid(string $siteUid): bool
    {
        return in_array('all', $this->enabledSites, true) || in_array($siteUid, $this->enabledSites, true);
    }

    public function isSectionEnabledByUid(string $sectionUid): bool
    {
        return in_array('all', $this->enabledSections, true) || in_array($sectionUid, $this->enabledSections, true);
    }

    /**
     * Returns the explicit per-section interval, or null if not set/invalid.
     */
    public function intervalDaysForSectionUid(?string $sectionUid): ?int
    {
        if ($sectionUid && isset($this->intervalDaysPerSection[$sectionUid])) {
            $v = (int)$this->intervalDaysPerSection[$sectionUid];
            return $v > 0 ? $v : null;
        }
        return null;
    }

    /**
     * Resolve the effective interval days for a section:
     * - Start from the global default (>= 1).
     * - If per-section intervals are enabled and a valid override exists for this section, use that.
     */
    public function getResolvedIntervalDaysForSectionUid(?string $sectionUid): int
    {
        $base = max(1, $this->defaultIntervalDays);

        if ($this->usePerSectionIntervals && $sectionUid) {
            $sectionInterval = $this->intervalDaysForSectionUid($sectionUid);
            if ($sectionInterval !== null && $sectionInterval >= 1) {
                return $sectionInterval;
            }
        }

        return $base;
    }

    /**
     * Normalises the interval days per section based on the provided value and configuration settings.
     *
     * @param mixed $value The input value to be normalised. This might represent interval days for specific sections
     *                     or a general configuration value.
     * @return array An array of normalised interval days for each section, keyed by UID.
     */
    public function normalizeIntervalDaysPerSection(mixed $value): array
    {
        $sourceArray = Craft::$app->getEntries()->getAllSections();

        if (!is_array($value)) {
            return [];
        }

        // Build a whitelist of known UIDs from the source array (ignore anything else).
        $knownUids = [];
        foreach ($sourceArray as $sourceItem) {
            $knownUids[(string)$sourceItem->uid] = true;
        }

        $out = [];

        // Helper: coerce numeric strings; reject blanks/“—”/non-numeric/zero/negatives.
        $coerceDays = static function($raw): ?int {
            if (is_int($raw)) {
                return $raw >= 1 ? $raw : null;
            }
            $s = trim((string)$raw);
            if ($s === '' || $s === '—') {
                return null;
            }
            if (!preg_match('/^\d+$/', $s)) {
                return null;
            }
            $i = (int)$s;
            return $i >= 1 ? $i : null;
        };

        if (!array_is_list($value)) {
            // Could be (A) map OR (B) rows keyed by UID.
            foreach ($value as $key => $rowOrScalar) {
                $uid = (string)$key;

                // If it’s the rows shape, pick the 'days' cell; else treat as scalar days.
                $days = is_array($rowOrScalar) && array_key_exists('days', $rowOrScalar)
                    ? $rowOrScalar['days']
                    : $rowOrScalar;

                $daysInt = $coerceDays($days);
                if ($daysInt === null) {
                    continue;
                }

                if (isset($knownUids[$uid])) {
                    $out[$uid] = $daysInt;
                }
            }

            return $out;
        }

        // Numeric-indexed rows (fallback): expect each row to carry a 'sectionUid' + 'days'.
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = isset($row['sectionUid']) ? (string)$row['sectionUid'] : '';
            if ($uid === '') {
                continue;
            }
            if ($knownUids !== null && !isset($knownUids[$uid])) {
                continue;
            }
            $daysInt = $coerceDays($row['days'] ?? null);
            if ($daysInt !== null) {
                $out[$uid] = $daysInt;
            }
        }

        return $out;
    }
}
