<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersTables extends AbstractMigration
{
    public function up(): void
    {
        // The shipping quote lives in the orders row rather than in its own
        // table: an order holds at most one, it is replaced rather than
        // accumulated, and it is never read on its own.
        $this->execute(<<<SQL
            CREATE TABLE orders (
                id CHAR(36) NOT NULL,
                status VARCHAR(16) NOT NULL,
                destination_zip_code CHAR(8) NOT NULL,
                currency CHAR(3) NOT NULL,
                shipping_carrier VARCHAR(120) NULL,
                shipping_service VARCHAR(60) NULL,
                shipping_amount_cents BIGINT UNSIGNED NULL,
                shipping_estimated_days SMALLINT UNSIGNED NULL,
                shipping_quoted_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                confirmed_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_orders_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        // product_id is intentionally NOT a foreign key: the line is a snapshot
        // taken when the order was placed, and it has to survive the product
        // being renamed, repriced or removed from the catalogue.
        //
        // The unique key is the structural half of "one line per product"; the
        // rule that adding the same product again increases that line is the
        // domain's job.
        $this->execute(<<<SQL
            CREATE TABLE order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id CHAR(36) NOT NULL,
                product_id CHAR(36) NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                unit_price_cents BIGINT UNSIGNED NOT NULL,
                weight_grams INT UNSIGNED NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_order_items_order_product (order_id, product_id),
                CONSTRAINT fk_order_items_order
                    FOREIGN KEY (order_id) REFERENCES orders (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE order_items');
        $this->execute('DROP TABLE orders');
    }
}
