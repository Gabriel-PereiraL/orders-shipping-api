<?php

declare(strict_types=1);

namespace OrderApi\Tests\Integration\Persistence;

use OrderApi\Application\Exception\DuplicateSku;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use OrderApi\Infrastructure\Persistence\Pdo\PdoProductRepository;
use OrderApi\Tests\Integration\DatabaseTestCase;

final class PdoProductRepositoryTest extends DatabaseTestCase
{
    private PdoProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = new PdoProductRepository($this->connection());
    }

    public function testAProductSurvivesARoundTrip(): void
    {
        $product = new Product(
            '018f2c8e-0000-7000-8000-000000000001',
            'KB-01',
            'Mechanical keyboard',
            Money::fromCents(24990, 'BRL'),
            900,
        );

        $this->products->save($product);

        self::assertEquals($product, $this->products->findById($product->id));
    }

    /**
     * Money is stored as an integer for exactly this reason: what goes in comes
     * back out, with no rounding introduced by the trip through the database.
     */
    public function testThePriceComesBackToTheCent(): void
    {
        $this->products->save(new Product(
            '018f2c8e-0000-7000-8000-000000000002',
            'ODD-01',
            'Awkwardly priced item',
            Money::fromCents(1999999, 'BRL'),
            10,
        ));

        $loaded = $this->products->findById('018f2c8e-0000-7000-8000-000000000002');

        self::assertNotNull($loaded);
        self::assertSame(1999999, $loaded->price->cents);
        self::assertSame('BRL', $loaded->price->currency);
    }

    public function testAnUnknownProductIsSimplyAbsent(): void
    {
        self::assertNull($this->products->findById('018f2c8e-0000-7000-8000-00000000ffff'));
    }

    /**
     * The unique key is what actually prevents this, and the repository is what
     * turns a driver error into something the application can answer with.
     */
    public function testTwoProductsCannotShareASku(): void
    {
        $this->products->save(new Product(
            '018f2c8e-0000-7000-8000-000000000003',
            'SAME-SKU',
            'First',
            Money::fromCents(1000, 'BRL'),
            100,
        ));

        $this->expectException(DuplicateSku::class);
        $this->expectExceptionMessage('SAME-SKU');

        $this->products->save(new Product(
            '018f2c8e-0000-7000-8000-000000000004',
            'SAME-SKU',
            'Second',
            Money::fromCents(2000, 'BRL'),
            200,
        ));
    }
}
