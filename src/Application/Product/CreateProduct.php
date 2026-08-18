<?php

declare(strict_types=1);

namespace OrderApi\Application\Product;

use OrderApi\Application\Port\ProductRepository;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use Ramsey\Uuid\Uuid;

/**
 * Identifiers are UUIDv7: unique without a round trip to the database, and
 * time-ordered, so they index well instead of scattering writes like UUIDv4.
 */
final readonly class CreateProduct
{
    public function __construct(
        private ProductRepository $products,
        private string $currency,
    ) {
    }

    public function execute(string $sku, string $name, int $priceInCents, int $weightGrams): Product
    {
        $product = new Product(
            Uuid::uuid7()->toString(),
            $sku,
            $name,
            Money::fromCents($priceInCents, $this->currency),
            $weightGrams,
        );

        $this->products->save($product);

        return $product;
    }
}
