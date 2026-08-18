<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Application\Order;

use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Order\ConfirmOrder;
use OrderApi\Application\Order\CreateOrder;
use OrderApi\Application\Order\GetOrder;
use OrderApi\Application\Order\QuoteOrderShipping;
use OrderApi\Domain\Exception\OrderStateConflict;
use OrderApi\Domain\Order\Order;
use OrderApi\Domain\Order\OrderStatus;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use OrderApi\Tests\Support\FixedClock;
use OrderApi\Tests\Support\InMemoryOrderRepository;
use OrderApi\Tests\Support\InMemoryProductRepository;
use OrderApi\Tests\Support\StubShippingQuoteProvider;
use PHPUnit\Framework\TestCase;

final class ConfirmOrderTest extends TestCase
{
    private const QUOTE_TTL_SECONDS = 900;

    private InMemoryOrderRepository $orders;
    private InMemoryProductRepository $products;
    private FixedClock $clock;
    private ConfirmOrder $confirmOrder;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrderRepository();
        $this->products = new InMemoryProductRepository();
        $this->clock = new FixedClock('2026-03-10 10:00:00');
        $this->confirmOrder = new ConfirmOrder($this->orders, $this->clock, self::QUOTE_TTL_SECONDS);

        $this->products->save(new Product('p-keyboard', 'KB-01', 'Mechanical keyboard', Money::fromCents(24990, 'BRL'), 900));
    }

    /**
     * The whole flow, end to end through the use cases: create, quote, confirm,
     * read back.
     */
    public function testAQuotedOrderIsConfirmedAndStaysConfirmed(): void
    {
        $order = $this->quotedOrder(2500);

        $confirmed = $this->confirmOrder->execute($order->id);

        self::assertSame(OrderStatus::Confirmed, $confirmed->status());
        self::assertSame(27490, $confirmed->total()->cents);
        self::assertEquals($this->clock->now(), $confirmed->confirmedAt());

        $reloaded = (new GetOrder($this->orders))->execute($order->id);
        self::assertSame(OrderStatus::Confirmed, $reloaded->status());
        self::assertSame(27490, $reloaded->total()->cents);
    }

    public function testItReportsAnUnknownOrder(): void
    {
        $this->expectException(ResourceNotFound::class);
        $this->expectExceptionMessage('Order o-ghost was not found.');

        $this->confirmOrder->execute('o-ghost');
    }

    public function testAnOrderWithoutAQuoteCannotBeConfirmed(): void
    {
        $order = $this->draftOrder();

        $this->expectException(OrderStateConflict::class);
        $this->expectExceptionMessage('no shipping quote');

        $this->confirmOrder->execute($order->id);
    }

    public function testConfirmingTwiceIsRejected(): void
    {
        $order = $this->quotedOrder(2500);
        $this->confirmOrder->execute($order->id);

        $this->expectException(OrderStateConflict::class);
        $this->expectExceptionMessage('already confirmed');

        $this->confirmOrder->execute($order->id);
    }

    /**
     * A price we were quoted a quarter of an hour ago is not a price we may
     * still charge; the customer has to see the current freight before paying.
     */
    public function testAQuoteThatWentStaleCannotBeConfirmed(): void
    {
        $order = $this->quotedOrder(2500);
        $this->clock->advanceSeconds(self::QUOTE_TTL_SECONDS + 1);

        $this->expectException(OrderStateConflict::class);
        $this->expectExceptionMessage('expired');

        $this->confirmOrder->execute($order->id);
    }

    public function testAStaleOrderCanBeConfirmedAfterBeingQuotedAgain(): void
    {
        $order = $this->quotedOrder(2500);
        $this->clock->advanceSeconds(self::QUOTE_TTL_SECONDS + 1);

        (new QuoteOrderShipping($this->orders, StubShippingQuoteProvider::quoting(2700, $this->clock->now()->format('Y-m-d H:i:s'))))
            ->execute($order->id);
        $confirmed = $this->confirmOrder->execute($order->id);

        self::assertSame(OrderStatus::Confirmed, $confirmed->status());
        self::assertSame(27690, $confirmed->total()->cents);
    }

    public function testARejectedConfirmationLeavesTheOrderAsADraft(): void
    {
        $order = $this->draftOrder();

        try {
            $this->confirmOrder->execute($order->id);
            self::fail('Expected the confirmation to be rejected.');
        } catch (OrderStateConflict) {
            $reloaded = (new GetOrder($this->orders))->execute($order->id);
            self::assertSame(OrderStatus::Draft, $reloaded->status());
            self::assertNull($reloaded->confirmedAt());
        }
    }

    private function draftOrder(): Order
    {
        return (new CreateOrder($this->orders, $this->products, $this->clock, 'BRL'))
            ->execute('01310100', [['productId' => 'p-keyboard', 'quantity' => 1]]);
    }

    private function quotedOrder(int $shippingCents): Order
    {
        $order = $this->draftOrder();

        return (new QuoteOrderShipping(
            $this->orders,
            StubShippingQuoteProvider::quoting($shippingCents, $this->clock->now()->format('Y-m-d H:i:s')),
        ))->execute($order->id);
    }
}
