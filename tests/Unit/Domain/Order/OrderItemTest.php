<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Domain\Order;

use OrderApi\Domain\Exception\InvalidQuantity;
use OrderApi\Domain\Order\OrderItem;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderItemTest extends TestCase
{
    public function testItMultipliesUnitPriceByQuantity(): void
    {
        $item = OrderItem::fromProduct($this->keyboard(), 3);

        self::assertSame(74970, $item->subtotal()->cents);
        self::assertSame(2700, $item->totalWeightGrams());
    }

    /**
     * The snapshot rule: a line keeps the price and name it was created with,
     * even after the catalogue moves on.
     */
    public function testItKeepsThePriceItWasCreatedWith(): void
    {
        $item = OrderItem::fromProduct($this->keyboard(), 2);

        $repricedCatalogueEntry = new Product(
            'p-keyboard',
            'KB-01',
            'Mechanical keyboard (2026 edition)',
            Money::fromCents(49990, 'BRL'),
            900,
        );

        self::assertNotSame($repricedCatalogueEntry->price->cents, $item->unitPrice->cents);
        self::assertSame(24990, $item->unitPrice->cents);
        self::assertSame('Mechanical keyboard', $item->productName);
    }

    public function testAddingMoreOfTheSameProductIncreasesTheLine(): void
    {
        $item = OrderItem::fromProduct($this->keyboard(), 1)->withExtraQuantity(2);

        self::assertSame(3, $item->quantity);
        self::assertSame(74970, $item->subtotal()->cents);
    }

    #[DataProvider('invalidQuantities')]
    public function testItRejectsNonPositiveQuantities(int $quantity): void
    {
        $this->expectException(InvalidQuantity::class);

        OrderItem::fromProduct($this->keyboard(), $quantity);
    }

    #[DataProvider('invalidQuantities')]
    public function testItRejectsNonPositiveIncrements(int $quantity): void
    {
        $this->expectException(InvalidQuantity::class);

        OrderItem::fromProduct($this->keyboard(), 1)->withExtraQuantity($quantity);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    private function keyboard(): Product
    {
        return new Product('p-keyboard', 'KB-01', 'Mechanical keyboard', Money::fromCents(24990, 'BRL'), 900);
    }
}
