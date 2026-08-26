<?php

use craft\helpers\App;

$database = App::env('DB_DATABASE');

if (!is_string($database) || $database === '') {
    throw new RuntimeException(
        'DB_DATABASE must be set before running Craft-backed tests. See tests/.env.example.'
    );
}

// craft\test\Craft drops every table in the configured database before a suite.
// Refuse an obviously non-test database as a last line of defence against mistakes.
if (preg_match('/(^|[_-])test($|[_-])/i', $database) !== 1) {
    throw new RuntimeException(
        'Refusing to run destructive Craft tests: DB_DATABASE must contain "test" as a separate word.'
    );
}

return [
    'dsn' => App::env('DB_DSN') ?: null,
    'driver' => App::env('DB_DRIVER'),
    'server' => App::env('DB_SERVER'),
    'port' => App::env('DB_PORT'),
    'database' => $database,
    'user' => App::env('DB_USER'),
    'password' => App::env('DB_PASSWORD'),
    'schema' => App::env('DB_SCHEMA'),
    'tablePrefix' => App::env('DB_TABLE_PREFIX'),
];
