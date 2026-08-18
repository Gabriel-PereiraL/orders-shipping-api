<?php

declare(strict_types=1);

namespace OrderApi\Tests\Integration\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use OrderApi\Domain\Order\Order;
use OrderApi\Domain\Order\OrderStatus;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;
use OrderApi\Infrastructure\Persistence\Pdo\PdoOrderRepository;
use OrderApi\Infrastructure\Persistence\PersistenceFailure;
use OrderApi\Tests\Integration\DatabaseTestCase;

final class PdoOrderRepositoryTest extends DatabaseTestCase
{
    private PdoOrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new PdoOrderRepository($this->connection());
    }

    public function testADraftOrderComesBackWithItsLines(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 2);
        $order->addItem($this->mouse(), 1);

        $this->orders->save($order);
        $loaded = $this->orders->findById($order->id);

        self::assertNotNull($loaded);
        self::assertSame(OrderStatus::Draft, $loaded->status());
        self::assertCount(2, $loaded->items());
        self::assertSame(59980, $loaded->subtotal()->cents);
        self::assertSame(1980, $loaded->totalWeightGrams());
        self::assertSame('01310100', $loaded->destinationZipCode);
        self::assertNull($loaded->shippingQuote());
    }

    public function testTheShippingQuoteComesBackIntact(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);
        $order->applyShippingQuote(new ShippingQuote(
            'ACME Express',
            'standard',
            Money::fromCents(2500, 'BRL'),
            7,
            $this->at('10:00:00'),
        ));

        $this->orders->save($order);
        $loaded = $this->orders->findById($order->id);

        self::assertNotNull($loaded);
        $quote = $loaded->shippingQuote();
        self::assertNotNull($quote);
        self::assertSame('ACME Express', $quote->carrier);
        self::assertSame('standard', $quote->service);
        self::assertSame(2500, $quote->amount->cents);
        self::assertSame(7, $quote->estimatedDays);
        self::assertEquals($this->at('10:00:00'), $quote->quotedAt);
        self::assertSame(27490, $loaded->total()->cents);
    }

    public function testAConfirmedOrderStaysConfirmed(): void
    {
        $order = $this->quotedOrder();
        $order->confirm($this->at('10:05:00'), 900);

        $this->orders->save($order);
        $loaded = $this->orders->findById($order->id);

        self::assertNotNull($loaded);
        self::assertSame(OrderStatus::Confirmed, $loaded->status());
        self::assertEquals($this->at('10:05:00'), $loaded->confirmedAt());
    }

    /**
     * A reloaded order is a real order, not a data bag: the rules still apply.
     */
    public function testAReloadedOrderStillRefusesToBeConfirmedTwice(): void
    {
        $order = $this->quotedOrder();
        $order->confirm($this->at('10:05:00'), 900);
        $this->orders->save($order);

        $loaded = $this->orders->findById($order->id);
        self::assertNotNull($loaded);

        $this->expectExceptionMessage('already confirmed');
        $loaded->confirm($this->at('10:06:00'), 900);
    }

    public function testSavingAgainReplacesTheLinesInsteadOfAddingToThem(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);
        $this->orders->save($order);

        $order->addItem($this->keyboard(), 2);
        $order->addItem($this->mouse(), 1);
        $this->orders->save($order);

        $loaded = $this->orders->findById($order->id);
        self::assertNotNull($loaded);
        self::assertCount(2, $loaded->items());
        self::assertSame(84970, $loaded->subtotal()->cents);
    }

    public function testAnUnknownOrderIsSimplyAbsent(): void
    {
        self::assertNull($this->orders->findById('018f2c8e-0000-7000-8000-00000000ffff'));
    }

    /**
     * If writing the lines fails, the order row must not survive on its own:
     * an order with no items would be a total nobody can explain.
     *
     * The failure is provoked with a product name longer than the column, which
     * is the closest thing to a realistic mid-write error we can trigger on
     * demand.
     */
    public function testAFailedWriteLeavesNoHalfSavedOrder(): void
    {
        $order = $this->draftOrder();
        $order->addItem(
            new Product(
                '018f2c8e-0000-7000-8000-000000000009',
                'LONG-01',
                str_repeat('a', 300),
                Money::fromCents(1000, 'BRL'),
                100,
            ),
            1,
        );

        try {
            $this->orders->save($order);
            self::fail('Expected the oversized line to be rejected by the database.');
        } catch (PersistenceFailure $exception) {
            self::assertStringContainsString('saving an order', $exception->getMessage());
        }

        self::assertNull($this->orders->findById($order->id));
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('order_items'));
    }

    private function countRows(string $table): int
    {
        $statement = $this->connection()->query(sprintf('SELECT COUNT(*) FROM %s', $table));
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function draftOrder(): Order
    {
        return new Order(
            '018f2c8e-0000-7000-8000-0000000000a1',
            '01310100',
            'BRL',
            $this->at('09:00:00'),
        );
    }

    private function quotedOrder(): Order
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);
        $order->applyShippingQuote(new ShippingQuote(
            'ACME Express',
            'standard',
            Money::fromCents(2500, 'BRL'),
            7,
            $this->at('10:00:00'),
        ));

        return $order;
    }

    private function keyboard(): Product
    {
        return new Product(
            '018f2c8e-0000-7000-8000-000000000001',
            'KB-01',
            'Mechanical keyboard',
            Money::fromCents(24990, 'BRL'),
            900,
        );
    }

    private function mouse(): Product
    {
        return new Product(
            '018f2c8e-0000-7000-8000-000000000002',
            'MS-01',
            'Wireless mouse',
            Money::fromCents(10000, 'BRL'),
            180,
        );
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-10 ' . $time, new DateTimeZone('UTC'));
    }
}
