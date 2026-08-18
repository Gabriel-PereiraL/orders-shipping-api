<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Domain\Product;

use OrderApi\Domain\Exception\InvalidProduct;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testItHoldsCatalogueData(): void
    {
        $product = new Product('p-1', 'MOUSE-01', 'Wireless mouse', Money::fromCents(9990, 'BRL'), 180);

        self::assertSame('MOUSE-01', $product->sku);
        self::assertSame(9990, $product->price->cents);
        self::assertSame(180, $product->weightGrams);
    }

    public function testAFreeProductIsAllowed(): void
    {
        $product = new Product('p-1', 'GIFT-01', 'Promotional sticker', Money::zero('BRL'), 5);

        self::assertSame(0, $product->price->cents);
    }

    #[DataProvider('blankFields')]
    public function testItRejectsBlankIdentityFields(string $id, string $sku, string $name): void
    {
        $this->expectException(InvalidProduct::class);

        new Product($id, $sku, $name, Money::fromCents(100, 'BRL'), 100);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function blankFields(): iterable
    {
        yield 'empty id' => ['', 'SKU-1', 'Name'];
        yield 'empty sku' => ['p-1', '', 'Name'];
        yield 'empty name' => ['p-1', 'SKU-1', ''];
        yield 'whitespace only name' => ['p-1', 'SKU-1', '   '];
    }

    /**
     * Shipping is quoted by weight, so a weightless product cannot be sold here.
     */
    #[DataProvider('invalidWeights')]
    public function testItRejectsProductsThatCannotBeShipped(int $weightGrams): void
    {
        $this->expectException(InvalidProduct::class);

        new Product('p-1', 'SKU-1', 'Name', Money::fromCents(100, 'BRL'), $weightGrams);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidWeights(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-50];
    }
}
