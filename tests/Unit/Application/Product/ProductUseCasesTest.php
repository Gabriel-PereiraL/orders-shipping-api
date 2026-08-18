<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Application\Product;

use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Product\CreateProduct;
use OrderApi\Application\Product\GetProduct;
use OrderApi\Domain\Exception\InvalidProduct;
use OrderApi\Tests\Support\InMemoryProductRepository;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ProductUseCasesTest extends TestCase
{
    private InMemoryProductRepository $products;

    protected function setUp(): void
    {
        $this->products = new InMemoryProductRepository();
    }

    public function testItStoresANewProductUnderAGeneratedIdentifier(): void
    {
        $product = (new CreateProduct($this->products, 'BRL'))
            ->execute('KB-01', 'Mechanical keyboard', 24990, 900);

        self::assertTrue(Uuid::isValid($product->id));
        self::assertSame(24990, $product->price->cents);
        self::assertSame('BRL', $product->price->currency);
        self::assertEquals($product, $this->products->findById($product->id));
    }

    public function testItRejectsAnInvalidProductWithoutStoringIt(): void
    {
        try {
            (new CreateProduct($this->products, 'BRL'))->execute('KB-01', '', 24990, 900);
            self::fail('Expected the product to be rejected.');
        } catch (InvalidProduct) {
            self::assertSame(0, $this->products->count());
        }
    }

    public function testItReadsAStoredProductBack(): void
    {
        $created = (new CreateProduct($this->products, 'BRL'))
            ->execute('MS-01', 'Wireless mouse', 10000, 180);

        self::assertEquals($created, (new GetProduct($this->products))->execute($created->id));
    }

    public function testItReportsAnUnknownProduct(): void
    {
        $this->expectException(ResourceNotFound::class);

        (new GetProduct($this->products))->execute('p-ghost');
    }
}
