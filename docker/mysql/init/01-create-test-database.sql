-- Integration tests run against their own schema so that a test run can never
-- truncate the database you were using by hand.
CREATE DATABASE IF NOT EXISTS orders_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

GRANT ALL PRIVILEGES ON orders_test.* TO 'orders'@'%';
FLUSH PRIVILEGES;
