<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Application\Order;

use DateTimeImmutable;
use DateTimeZone;
use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Exception\ShippingProviderInvalidResponse;
use OrderApi\Application\Exception\ShippingProviderUnavailable;
use OrderApi\Application\Order\CreateOrder;
use OrderApi\Application\Order\QuoteOrderShipping;
use OrderApi\Domain\Exception\OrderStateConflict;
use OrderApi\Domain\Order\Order;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use OrderApi\Tests\Support\FixedClock;
use OrderApi\Tests\Support\InMemoryOrderRepository;
use OrderApi\Tests\Support\InMemoryProductRepository;
use OrderApi\Tests\Support\StubShippingQuoteProvider;
use PHPUnit\Framework\TestCase;

final class QuoteOrderShippingTest extends TestCase
{
    private InMemoryOrderRepository $orders;
    private InMemoryProductRepository $products;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrderRepository();
        $this->products = new InMemoryProductRepository();
        $this->clock = new FixedClock('2026-03-10 10:00:00');

        $this->products->save(new Product('p-keyboard', 'KB-01', 'Mechanical keyboard', Money::fromCents(24990, 'BRL'), 900));
    }

    public function testItAttachesTheCarrierPriceToTheOrder(): void
    {
        $order = $this->orderWithOneKeyboard();
        $carrier = StubShippingQuoteProvider::quoting(2500);

        $quoted = (new QuoteOrderShipping($this->orders, $carrier))->execute($order->id);

        self::assertSame(1, $carrier->calls);
        self::assertNotNull($quoted->shippingQuote());
        self::assertSame(2500, $quoted->shippingQuote()->amount->cents);
        self::assertSame(27490, $quoted->total()->cents);
    }

    public function testThePriceSurvivesBeingStored(): void
    {
        $order = $this->orderWithOneKeyboard();

        (new QuoteOrderShipping($this->orders, StubShippingQuoteProvider::quoting(2500)))->execute($order->id);

        $reloaded = $this->orders->findById($order->id);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->shippingQuote());
        self::assertSame(2500, $reloaded->shippingQuote()->amount->cents);
    }

    public function testQuotingAgainReplacesThePreviousPrice(): void
    {
        $order = $this->orderWithOneKeyboard();

        (new QuoteOrderShipping($this->orders, StubShippingQuoteProvider::quoting(2500)))->execute($order->id);
        $requoted = (new QuoteOrderShipping($this->orders, StubShippingQuoteProvider::quoting(3100)))->execute($order->id);

        self::assertNotNull($requoted->shippingQuote());
        self::assertSame(3100, $requoted->shippingQuote()->amount->cents);
    }

    public function testItReportsAnUnknownOrder(): void
    {
        $carrier = StubShippingQuoteProvider::quoting(2500);

        try {
            (new QuoteOrderShipping($this->orders, $carrier))->execute('o-ghost');
            self::fail('Expected the order to be reported as missing.');
        } catch (ResourceNotFound) {
            self::assertSame(0, $carrier->calls, 'The carrier must not be called for an order that does not exist.');
        }
    }

    /**
     * An outage must not leave the order in a half-quoted state: nothing was
     * priced, so nothing is stored, and the client can simply try again.
     */
    public function testAnOutageLeavesTheOrderUnchanged(): void
    {
        $order = $this->orderWithOneKeyboard();
        $carrier = StubShippingQuoteProvider::failingWith(
            ShippingProviderUnavailable::timedOut('STUB', 2.0),
        );

        try {
            (new QuoteOrderShipping($this->orders, $carrier))->execute($order->id);
            self::fail('Expected the carrier failure to surface.');
        } catch (ShippingProviderUnavailable) {
            $reloaded = $this->orders->findById($order->id);
            self::assertNotNull($reloaded);
            self::assertNull($reloaded->shippingQuote());
        }
    }

    public function testABrokenCarrierPayloadSurfacesAsSuch(): void
    {
        $order = $this->orderWithOneKeyboard();
        $carrier = StubShippingQuoteProvider::failingWith(
            ShippingProviderInvalidResponse::malformedPayload('STUB', 'missing "amount_cents".'),
        );

        $this->expectException(ShippingProviderInvalidResponse::class);

        (new QuoteOrderShipping($this->orders, $carrier))->execute($order->id);
    }

    public function testAConfirmedOrderIsNotQuotedAgain(): void
    {
        $order = $this->orderWithOneKeyboard();
        $order->applyShippingQuote(StubShippingQuoteProvider::quoting(2500)->quoteFor('01310100', 900, Money::zero('BRL')));
        $order->confirm(new DateTimeImmutable('2026-03-10 10:01:00', new DateTimeZone('UTC')), 900);
        $this->orders->save($order);

        $this->expectException(OrderStateConflict::class);

        (new QuoteOrderShipping($this->orders, StubShippingQuoteProvider::quoting(9900)))->execute($order->id);
    }

    private function orderWithOneKeyboard(): Order
    {
        return (new CreateOrder($this->orders, $this->products, $this->clock, 'BRL'))
            ->execute('01310100', [['productId' => 'p-keyboard', 'quantity' => 1]]);
    }
}
