<?php

declare(strict_types=1);

namespace OrderApi\Application\Port;

use OrderApi\Domain\Product\Product;

/**
 * What the application needs from storage, expressed without mentioning storage.
 *
 * The interface exists because there are two real implementations: the SQL one
 * used at runtime and the in-memory one that lets the use case tests assert
 * behaviour without a database.
 */
interface ProductRepository
{
    public function save(Product $product): void;

    public function findById(string $id): ?Product;
}
