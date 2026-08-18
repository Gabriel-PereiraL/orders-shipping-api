<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Application\Order;

use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Order\CreateOrder;
use OrderApi\Domain\Exception\InvalidDestination;
use OrderApi\Domain\Exception\InvalidQuantity;
use OrderApi\Domain\Order\OrderStatus;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use OrderApi\Tests\Support\FixedClock;
use OrderApi\Tests\Support\InMemoryOrderRepository;
use OrderApi\Tests\Support\InMemoryProductRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateOrderTest extends TestCase
{
    private InMemoryOrderRepository $orders;
    private InMemoryProductRepository $products;
    private FixedClock $clock;
    private CreateOrder $createOrder;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrderRepository();
        $this->products = new InMemoryProductRepository();
        $this->clock = new FixedClock('2026-03-10 10:00:00');
        $this->createOrder = new CreateOrder($this->orders, $this->products, $this->clock, 'BRL');

        $this->products->save(new Product('p-keyboard', 'KB-01', 'Mechanical keyboard', Money::fromCents(24990, 'BRL'), 900));
        $this->products->save(new Product('p-mouse', 'MS-01', 'Wireless mouse', Money::fromCents(10000, 'BRL'), 180));
    }

    public function testItCreatesADraftOrderAndStoresIt(): void
    {
        $order = $this->createOrder->execute('01310-100', [
            ['productId' => 'p-keyboard', 'quantity' => 2],
            ['productId' => 'p-mouse', 'quantity' => 1],
        ]);

        self::assertSame(OrderStatus::Draft, $order->status());
        self::assertSame(59980, $order->subtotal()->cents);
        self::assertSame('01310100', $order->destinationZipCode);
        self::assertEquals($this->clock->now(), $order->createdAt);
        self::assertNotNull($this->orders->findById($order->id));
    }

    public function testItGivesEveryOrderAUniqueIdentifier(): void
    {
        $first = $this->createOrder->execute('01310100', [['productId' => 'p-mouse', 'quantity' => 1]]);
        $second = $this->createOrder->execute('01310100', [['productId' => 'p-mouse', 'quantity' => 1]]);

        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, $this->orders->count());
    }

    public function testItCopiesTheCataloguePriceIntoTheOrder(): void
    {
        $order = $this->createOrder->execute('01310100', [['productId' => 'p-keyboard', 'quantity' => 1]]);

        $this->products->save(new Product('p-keyboard', 'KB-01', 'Mechanical keyboard', Money::fromCents(49990, 'BRL'), 900));

        $stored = $this->orders->findById($order->id);
        self::assertNotNull($stored);
        self::assertSame(24990, $stored->subtotal()->cents);
    }

    public function testItRejectsAnUnknownProduct(): void
    {
        $this->expectException(ResourceNotFound::class);
        $this->expectExceptionMessage('Product p-ghost was not found.');

        $this->createOrder->execute('01310100', [['productId' => 'p-ghost', 'quantity' => 1]]);
    }

    /**
     * A rejected order must leave nothing behind, otherwise a failed request
     * would still show up in the customer's order history.
     */
    public function testItStoresNothingWhenAProductIsUnknown(): void
    {
        try {
            $this->createOrder->execute('01310100', [
                ['productId' => 'p-keyboard', 'quantity' => 1],
                ['productId' => 'p-ghost', 'quantity' => 1],
            ]);
        } catch (ResourceNotFound) {
            // asserted below
        }

        self::assertSame(0, $this->orders->count());
    }

    #[DataProvider('invalidQuantities')]
    public function testItRejectsNonPositiveQuantities(int $quantity): void
    {
        $this->expectException(InvalidQuantity::class);

        $this->createOrder->execute('01310100', [['productId' => 'p-mouse', 'quantity' => $quantity]]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testItRejectsAMalformedDestination(): void
    {
        $this->expectException(InvalidDestination::class);

        $this->createOrder->execute('123', [['productId' => 'p-mouse', 'quantity' => 1]]);
    }
}
