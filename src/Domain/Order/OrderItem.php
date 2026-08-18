<?php

declare(strict_types=1);

namespace OrderApi\Domain\Order;

use OrderApi\Domain\Exception\InvalidQuantity;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;

/**
 * A product frozen into an order at the moment it was added.
 *
 * Name, unit price and weight are copied instead of referenced on purpose: when
 * the catalogue raises a price tomorrow, orders placed today must keep the price
 * the customer actually agreed to. Reading the price through the product would
 * silently rewrite history, and would also make shipping quotes irreproducible.
 */
final readonly class OrderItem
{
    public function __construct(
        public string $productId,
        public string $productName,
        public Money $unitPrice,
        public int $weightGrams,
        public int $quantity,
    ) {
        if ($quantity < 1) {
            throw InvalidQuantity::notPositive($quantity);
        }
    }

    public static function fromProduct(Product $product, int $quantity): self
    {
        return new self(
            $product->id,
            $product->name,
            $product->price,
            $product->weightGrams,
            $quantity,
        );
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->multipliedBy($this->quantity);
    }

    public function totalWeightGrams(): int
    {
        return $this->weightGrams * $this->quantity;
    }

    public function withExtraQuantity(int $quantity): self
    {
        if ($quantity < 1) {
            throw InvalidQuantity::notPositive($quantity);
        }

        return new self(
            $this->productId,
            $this->productName,
            $this->unitPrice,
            $this->weightGrams,
            $this->quantity + $quantity,
        );
    }
}
