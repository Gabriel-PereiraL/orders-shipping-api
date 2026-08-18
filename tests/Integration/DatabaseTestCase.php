<?php

declare(strict_types=1);

namespace OrderApi\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base for the tests that are only worth writing against a real database:
 * mapping, transactions and the constraints the schema enforces.
 *
 * These tests connect for real and fail loudly when they cannot, rather than
 * skipping themselves. A silently skipped integration suite is worse than no
 * integration suite, because the build still goes green.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?PDO $connection = null;

    protected function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('DB_PORT') ?: 3306);
        $database = getenv('DB_TEST_NAME') ?: 'orders_test';

        try {
            self::$connection = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
                getenv('DB_USER') ?: 'orders',
                getenv('DB_PASSWORD') ?: 'orders',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf(
                    'Could not reach the test database at %s:%d. Start the environment with '
                    . '"docker compose up -d", apply the schema with "make migrate-test", and run the '
                    . 'suite inside the container with "make test". (%s)',
                    $host,
                    $port,
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }

        return self::$connection;
    }

    protected function setUp(): void
    {
        $connection = $this->connection();

        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        $connection->exec('TRUNCATE TABLE order_items');
        $connection->exec('TRUNCATE TABLE orders');
        $connection->exec('TRUNCATE TABLE products');
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
