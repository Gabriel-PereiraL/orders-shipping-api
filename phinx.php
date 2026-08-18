<?php

declare(strict_types=1);

/**
 * Migrations read the same environment variables as the application, so there
 * is no second place where the database credentials can drift out of sync.
 *
 * The test environment points at its own schema: running migrations for the
 * integration suite can never touch the database you are using by hand.
 */
$connection = static fn (string $database): array => [
    'adapter' => 'mysql',
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'name' => $database,
    'user' => getenv('DB_USER') ?: 'orders',
    'pass' => getenv('DB_PASSWORD') ?: 'orders',
    'charset' => 'utf8mb4',
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => $connection(getenv('DB_NAME') ?: 'orders'),
        'test' => $connection(getenv('DB_TEST_NAME') ?: 'orders_test'),
    ],
    'version_order' => 'creation',
];
