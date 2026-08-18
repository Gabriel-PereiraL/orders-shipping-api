<?php

declare(strict_types=1);

namespace OrderApi\Tests\Support;

use OrderApi\Application\Port\ProductRepository;
use OrderApi\Domain\Product\Product;

final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<string, Product> */
    private array $products = [];

    public function save(Product $product): void
    {
        $this->products[$product->id] = $product;
    }

    public function findById(string $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function count(): int
    {
        return count($this->products);
    }
}
