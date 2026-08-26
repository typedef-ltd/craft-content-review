# Content Review

Content Review adds an ongoing review workflow for Craft entries. Eligible entries are assigned a reviewer and a next review date, which is either calculated automatically from global or section settings or customised per entry.

Reviewers can see what is overdue, due today, or due soon from dedicated dashboards and optional email digests.

## Requirements

* Craft CMS 5.0 or later
* PHP 8.2 or later

## Installation

Install Content Review from the Craft Plugin Store, or with Composer:

```
composer require typedef/craft-content-review
php craft plugin/install content-review
```

After installation, Content Review automatically queues the creation of initial review schedules for existing eligible entries.

## Configuration

Go to **Content Review → Settings**. You must be an admin and `allowAdminChanges` must be enabled.

Set a default review interval and, optionally:

* use different intervals for individual sections
* limit Content Review to selected sites and sections
* allow per-entry disabling
* allow custom next-review dates
* choose how many days before a review date an entry becomes **due soon**
* configure email digests: whether to send them and how often, whether to use a custom email template, etc
* choose whether the per-entry review pane appears at the top or bottom of the entry sidebar

By default, new review schedules are assigned to the entry’s author, but this can be changed manually on an entry-by-entry basis.

The "due-soon" window also determines when an entry can be marked as reviewed, in order to prevent reviews happening too early.  

### Per-entry settings

Eligible entries have a Content Review panel where editors can see the current schedule and mark the entry as reviewed. They can also assign a different reviewer and, when enabled in the plugin settings:

* set a custom date for the next review
* disable Content Review for the entry

A custom date applies to the **next review only**. After that review is completed, the normal global or section interval resumes unless a new custom date is set.

## Reviewing content

The entry panel shows the next review date, review status, reviewer, interval and previous review date, together with a **Mark as reviewed** action when the entry is reviewable.

Marking an entry as reviewed records the review and schedules the next one automatically.

Content Review applies only to canonical Craft entries. Nested entries (e.g. Matrix blocks), drafts, and revisions are not reviewed independently.

Note that any user who has permission to save an entry can also mark it as reviewed - i.e., the ability to mark as reviewed is not limited only to the assigned reviewer. The reason for this is to keep permissions simple and the system flexible, on the basis that in general the reviewer will be the one marking as reviewed but that there may be cases where delegation is needed without going through the process of reassigning entries. 

### Review dashboards

The Content Review section provides:

* **My reviews** — entries for whom the current user is assigned as reviewer
* **All reviews** — all scheduled entries in the system

Each has an Overview, which gives a clear picture of the state of the Content Review system. Then there are separate list views for "Overdue", "Due today" and "Due soon".

Users need the top-level Content Review plugin permission to access these dashboards. To view **All reviews** requires the **View all reviews** permission also.

A user must also have permission to save an entry before they can mark it as reviewed.

A **Review status** column is available for Craft Entry indexes.

## Email digests

Content Review can send personalised daily or weekly email digests summarising entries that are "overdue", "due today" and "due soon". It is also possible to send a global summary digest of all scheduled entries, mirroring the "All reviews" dashboards.

Digest delivery is triggered by a console command. Run it **once per day** at the time you would like the emails delivered; Content Review determines whether a digest is due that day.

For example, to run the digest command every day at 9am:

```
0 9 * * * php craft content-review/digest/send
```

Note that if this command were run more than once in a day, it may send the same emails again, because the system keeps no record of digests sent. This is in order to keep the system simple (i.e. no extra DB-backed ledger just for rare duplicate runs).

To send a digest manually regardless of the configured schedule, you can run:

```
php craft content-review/digest/send --force=1
```

### Custom digest template

A custom Twig template can be used instead of the built-in digest template by setting **Custom digest template** to a path relative to the project’s `templates/` directory, for example:

```text
emails/content-review
```

The template receives:

* `summary`
* `dueSoonDays`
* `reviewer`
* `reviewsUrl`
* `reviewableContentLabel`
* `previewLimit`
* `itemsOverdue`
* `itemsDueToday`
* `itemsDueSoon`
* `forDate`

Each review item in the `itemsOverdue`, `itemsDueToday`, and `itemsDueSoon` loops contains an `entry` and its `due` date.

`itemsOverdue`, `itemsDueToday`, and `itemsDueSoon` contain at most `previewLimit` entries.

## Developer configuration

Some plugin settings can be overridden in `config/content-review.php`:

```php
<?php

return [
    'sendDigests' => false,
];
```

This can be useful for environment-specific settings such as disabling digest emails outside production.

Settings changed through the Control Panel are stored in Craft project config, and a review-schedule recalculation is queued automatically.

**Important:** after changing a schedule-affecting setting directly in `config/content-review.php`, recalculate schedules with:

```bash
php craft content-review/review-schedules/recalculate
```

In multi-site installations, review schedules are stored independently for each entry/site combination.

## Support

For support, email [joe@typedef.co](mailto:joe@typedef.co).

## License

This plugin requires a commercial license purchasable through the Craft Plugin Store. See [LICENSE.md](LICENSE.md) for details.