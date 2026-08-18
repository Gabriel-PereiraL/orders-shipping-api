<?php

declare(strict_types=1);

/**
 * The only place that reads the environment. Everything else receives values.
 */
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'orders',
        'user' => getenv('DB_USER') ?: 'orders',
        'password' => getenv('DB_PASSWORD') ?: 'orders',
    ],
    'currency' => getenv('APP_CURRENCY') ?: 'BRL',
    'shipping' => [
        // "fake" runs the local carrier simulator, "http" talks to a real one.
        'provider' => getenv('SHIPPING_PROVIDER') ?: 'fake',
        'quote_ttl_seconds' => (int) (getenv('SHIPPING_QUOTE_TTL_SECONDS') ?: 900),
        'carrier' => getenv('SHIPPING_HTTP_CARRIER') ?: 'carrier',
        'base_url' => getenv('SHIPPING_HTTP_BASE_URL') ?: '',
        'timeout_seconds' => (float) (getenv('SHIPPING_HTTP_TIMEOUT_SECONDS') ?: 2.0),
        'connect_timeout_seconds' => (float) (getenv('SHIPPING_HTTP_CONNECT_TIMEOUT_SECONDS') ?: 1.0),
    ],
];
