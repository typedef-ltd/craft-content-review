# Testing

Content Review uses Codeception 5 with two deliberately separate suites:

- `unit`: fast tests for deterministic logic. These load Craft/Yii classes, but do not create a Craft application or use a database.
- `integration`: boots a disposable Craft application, installs Content Review, and uses a dedicated test database.

The suite is deliberately small. It protects the plugin's core invariants: date and interval calculation, due/review-window boundaries, automatic schedule creation and reconciliation, per-entry overrides, review authorisation and atomic completion, review-query filtering, settings invariants, plugin installation/schema, and digest sending.

## DDEV setup

DDEV is the recommended PHP/Composer/database environment for this repository. The test Craft application itself is created in-process by `craft\test\Craft`; no separate Craft website or working DDEV URL is required.

Create the dedicated disposable database once:

```bash
ddev mysql -e 'CREATE DATABASE IF NOT EXISTS content_review_test'
```

Create the local test environment file:

```bash
cp tests/.env.example tests/.env
```

The example file is already configured for the normal DDEV database connection:

```dotenv
DB_DRIVER=mysql
DB_SERVER=db
DB_PORT=3306
DB_DATABASE=content_review_test
DB_USER=db
DB_PASSWORD=db
```

The integration suite uses Craft's `craft\test\Craft` module with `clean: true`, which drops all tables in that database before rebuilding Craft. As an additional safeguard, the test configuration refuses to run unless the database name contains `test` as a separate word, such as `content_review_test` or `test_content_review`.

`DEFAULT_SITE_URL=http://test.craftcms.test/index.php` is deliberately synthetic. Codeception uses it to construct Craft's in-process request environment; it does not need to resolve through DDEV.

## Composer setup

After adding or changing test dependencies, resolve them from inside DDEV:

```bash
ddev composer update codeception/codeception codeception/module-asserts codeception/module-yii2 vlucas/phpdotenv --with-dependencies
```

Commit the resulting `composer.lock`. Normal checkouts then only need:

```bash
ddev composer install
```

If test support classes have just been added or renamed, refresh Composer's development autoloader:

```bash
ddev composer dump-autoload
```

Codeception's generated actor action files live under `tests/_support/_generated/` and are deliberately ignored. They are disposable; Codeception rebuilds them from the suite configuration.

## Running tests

Run only the fast unit suite:

```bash
ddev composer test-unit
```

Run the Craft/database integration suite:

```bash
ddev composer test-integration
```

Run everything:

```bash
ddev composer test
```

The integration database is disposable. Never point `tests/.env` at a development, staging, or production database.
