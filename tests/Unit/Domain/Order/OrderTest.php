<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Domain\Order;

use DateTimeImmutable;
use DateTimeZone;
use OrderApi\Domain\Exception\InvalidDestination;
use OrderApi\Domain\Exception\InvalidQuantity;
use OrderApi\Domain\Exception\OrderStateConflict;
use OrderApi\Domain\Order\Order;
use OrderApi\Domain\Order\OrderStatus;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private const QUOTE_TTL_SECONDS = 900;

    public function testAnOrderWithItemsAndAValidQuoteCanBeConfirmed(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 2);
        $order->addItem($this->mouse(), 1);
        $order->applyShippingQuote($this->quoteOf(2500, $this->at('10:00:00')));

        $order->confirm($this->at('10:05:00'), self::QUOTE_TTL_SECONDS);

        self::assertSame(OrderStatus::Confirmed, $order->status());
        self::assertSame(59980, $order->subtotal()->cents);
        self::assertSame(62480, $order->total()->cents);
        self::assertEquals($this->at('10:05:00'), $order->confirmedAt());
    }

    public function testANewOrderStartsAsAnEmptyDraft(): void
    {
        $order = $this->draftOrder();

        self::assertSame(OrderStatus::Draft, $order->status());
        self::assertSame([], $order->items());
        self::assertSame(0, $order->subtotal()->cents);
        self::assertNull($order->shippingQuote());
        self::assertNull($order->confirmedAt());
    }

    public function testTheSameProductAddedTwiceBecomesOneLine(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);
        $order->addItem($this->keyboard(), 2);

        self::assertCount(1, $order->items());
        self::assertSame(3, $order->items()[0]->quantity);
        self::assertSame(74970, $order->subtotal()->cents);
    }

    public function testItSumsTheWeightOfEveryUnit(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 2);
        $order->addItem($this->mouse(), 3);

        self::assertSame(2340, $order->totalWeightGrams());
    }

    #[DataProvider('invalidQuantities')]
    public function testItRejectsNonPositiveQuantities(int $quantity): void
    {
        $this->expectException(InvalidQuantity::class);

        $this->draftOrder()->addItem($this->keyboard(), $quantity);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-2];
    }

    public function testAnEmptyOrderCannotBeQuoted(): void
    {
        $this->expectException(OrderStateConflict::class);
        $this->expectExceptionMessage('has no items');

        $this->draftOrder()->applyShippingQuote($this->quoteOf(2500, $this->at('10:00:00')));
    }

    public function testAnEmptyOrderCannotBeConfirmed(): void
    {
        $this->expectException(OrderStateConflict::class);
        $this->expectExceptionMessage('has no items');

        $this->draftOrder()->confirm($this->at('10:00:00'), self::QUOTE_TTL_SECONDS);
    }

    public function testAnOrderWithoutAQuoteCannotBeConfirmed(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);

        $this->expectException(OrderStateConflict::class);
        $this->expectExceptionMessage('no shipping quote');

        $order->confirm($this->at('10:00:00'), self::QUOTE_TTL_SECONDS);
    }

    public function testTheTotalIsUndefinedWithoutAQuote(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);

        $this->expectException(OrderStateConflict::class);

        $order->total();
    }

    public function testAStaleQuoteCannotBeConfirmed(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);
        $order->applyShippingQuote($this->quoteOf(2500, $this->at('10:00:00')));

        $this->expectException(OrderStateConflict::class);
        $this->expectExceptionMessage('expired');

        $order->confirm($this->at('10:15:01'), self::QUOTE_TTL_SECONDS);
    }

    /**
     * The boundary belongs to the customer: a quote is valid for its full TTL.
     */
    public function testAQuoteIsStillValidOnItsLastSecond(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);
        $order->applyShippingQuote($this->quoteOf(2500, $this->at('10:00:00')));

        $order->confirm($this->at('10:15:00'), self::QUOTE_TTL_SECONDS);

        self::assertSame(OrderStatus::Confirmed, $order->status());
    }

    public function testAnOrderCannotBeConfirmedTwice(): void
    {
        $order = $this->confirmedOrder();

        $this->expectException(OrderStateConflict::class);
        $this->expectExceptionMessage('already confirmed');

        $order->confirm($this->at('10:06:00'), self::QUOTE_TTL_SECONDS);
    }

    public function testAConfirmedOrderCannotReceiveItems(): void
    {
        $order = $this->confirmedOrder();

        $this->expectException(OrderStateConflict::class);

        $order->addItem($this->mouse(), 1);
    }

    public function testAConfirmedOrderCannotBeRequoted(): void
    {
        $order = $this->confirmedOrder();

        $this->expectException(OrderStateConflict::class);

        $order->applyShippingQuote($this->quoteOf(100, $this->at('10:06:00')));
    }

    /**
     * Freight was priced for a specific basket; changing the basket invalidates
     * it, otherwise a heavier order could be confirmed at a lighter order's price.
     */
    public function testChangingTheItemsDiscardsAQuoteAlreadyCalculated(): void
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);
        $order->applyShippingQuote($this->quoteOf(2500, $this->at('10:00:00')));

        $order->addItem($this->mouse(), 1);

        self::assertNull($order->shippingQuote());
    }

    public function testItAcceptsAZipCodeWrittenWithASeparator(): void
    {
        $order = new Order('o-1', '01310-100', 'BRL', $this->at('09:00:00'));

        self::assertSame('01310100', $order->destinationZipCode);
    }

    #[DataProvider('malformedZipCodes')]
    public function testItRejectsMalformedZipCodes(string $zipCode): void
    {
        $this->expectException(InvalidDestination::class);

        new Order('o-1', $zipCode, 'BRL', $this->at('09:00:00'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedZipCodes(): iterable
    {
        yield 'too short' => ['0131010'];
        yield 'too long' => ['013101000'];
        yield 'letters' => ['0131010A'];
        yield 'empty' => [''];
    }

    private function draftOrder(): Order
    {
        return new Order('o-1', '01310100', 'BRL', $this->at('09:00:00'));
    }

    private function confirmedOrder(): Order
    {
        $order = $this->draftOrder();
        $order->addItem($this->keyboard(), 1);
        $order->applyShippingQuote($this->quoteOf(2500, $this->at('10:00:00')));
        $order->confirm($this->at('10:05:00'), self::QUOTE_TTL_SECONDS);

        return $order;
    }

    private function keyboard(): Product
    {
        return new Product('p-keyboard', 'KB-01', 'Mechanical keyboard', Money::fromCents(24990, 'BRL'), 900);
    }

    private function mouse(): Product
    {
        return new Product('p-mouse', 'MS-01', 'Wireless mouse', Money::fromCents(10000, 'BRL'), 180);
    }

    private function quoteOf(int $cents, DateTimeImmutable $quotedAt): ShippingQuote
    {
        return new ShippingQuote('ACME', 'standard', Money::fromCents($cents, 'BRL'), 5, $quotedAt);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-10 ' . $time, new DateTimeZone('UTC'));
    }
}
