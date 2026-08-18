<?php

declare(strict_types=1);

namespace OrderApi\Tests\Support;

use OrderApi\Application\Exception\DuplicateSku;
use OrderApi\Application\Port\ProductRepository;
use OrderApi\Domain\Product\Product;

/**
 * Enforces the sku uniqueness the real schema enforces with a unique key. A
 * double that accepts what the database would reject turns green tests into
 * false confidence.
 */
final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<string, Product> */
    private array $products = [];

    public function save(Product $product): void
    {
        foreach ($this->products as $stored) {
            if ($stored->sku === $product->sku && $stored->id !== $product->id) {
                throw DuplicateSku::forSku($product->sku);
            }
        }

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
