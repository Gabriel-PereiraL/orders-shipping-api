<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Written as plain SQL rather than through the schema builder: this project
 * targets MySQL only, and the exact types matter here (money as an integer,
 * identifiers as CHAR(36)), so hiding them behind a portable abstraction would
 * cost clarity and buy portability nobody asked for.
 */
final class CreateProductsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<SQL
            CREATE TABLE products (
                id CHAR(36) NOT NULL,
                sku VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                price_cents BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL,
                weight_grams INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_products_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE products');
    }
}
