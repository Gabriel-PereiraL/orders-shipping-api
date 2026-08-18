<?php

declare(strict_types=1);

namespace OrderApi\Domain\Order;

use DateTimeImmutable;
use OrderApi\Domain\Exception\InvalidDestination;
use OrderApi\Domain\Exception\OrderStateConflict;
use OrderApi\Domain\Product\Product;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;

/**
 * The aggregate that owns every rule about what an order is allowed to do.
 *
 * Everything here is decided without asking a database, an HTTP request or a
 * carrier: given the items, the quote and the current time, the order alone
 * knows whether it can be confirmed.
 */
final class Order
{
    public readonly string $destinationZipCode;

    /**
     * Keyed by product id so that adding the same product twice increases the
     * existing line instead of producing two lines for the same thing.
     *
     * @var array<string, OrderItem>
     */
    private array $items = [];

    private ?ShippingQuote $shippingQuote = null;

    private OrderStatus $status = OrderStatus::Draft;

    private ?DateTimeImmutable $confirmedAt = null;

    public function __construct(
        public readonly string $id,
        string $destinationZipCode,
        public readonly string $currency,
        public readonly DateTimeImmutable $createdAt,
    ) {
        $this->destinationZipCode = self::normalizeZipCode($destinationZipCode);
    }

    /**
     * Rebuilds an order that was already valid when it was stored.
     *
     * This deliberately skips the rules the other methods enforce. Replaying
     * them on load would mean an order could stop being loadable because a rule
     * changed after it was placed, which is exactly the history-rewriting the
     * price snapshot exists to prevent. Only repositories should call it.
     *
     * @param list<OrderItem> $items
     */
    public static function reconstitute(
        string $id,
        string $destinationZipCode,
        string $currency,
        DateTimeImmutable $createdAt,
        OrderStatus $status,
        array $items,
        ?ShippingQuote $shippingQuote,
        ?DateTimeImmutable $confirmedAt,
    ): self {
        $order = new self($id, $destinationZipCode, $currency, $createdAt);

        foreach ($items as $item) {
            $order->items[$item->productId] = $item;
        }

        $order->shippingQuote = $shippingQuote;
        $order->status = $status;
        $order->confirmedAt = $confirmedAt;

        return $order;
    }

    public function addItem(Product $product, int $quantity): void
    {
        $this->assertNotConfirmed();

        $existing = $this->items[$product->id] ?? null;

        $this->items[$product->id] = $existing === null
            ? OrderItem::fromProduct($product, $quantity)
            : $existing->withExtraQuantity($quantity);

        // Weight and subtotal just changed, so any freight already calculated
        // was priced for a different order and must not survive.
        $this->shippingQuote = null;
    }

    public function applyShippingQuote(ShippingQuote $quote): void
    {
        $this->assertNotConfirmed();

        if ($this->items === []) {
            throw OrderStateConflict::hasNoItems($this->id);
        }

        $this->shippingQuote = $quote;
    }

    public function confirm(DateTimeImmutable $now, int $quoteTtlSeconds): void
    {
        $this->assertNotConfirmed();

        if ($this->items === []) {
            throw OrderStateConflict::hasNoItems($this->id);
        }

        if ($this->shippingQuote === null) {
            throw OrderStateConflict::missingShippingQuote($this->id);
        }

        if ($this->shippingQuote->isExpiredAt($now, $quoteTtlSeconds)) {
            throw OrderStateConflict::expiredShippingQuote($this->id);
        }

        $this->status = OrderStatus::Confirmed;
        $this->confirmedAt = $now;
    }

    public function subtotal(): Money
    {
        $subtotal = Money::zero($this->currency);

        foreach ($this->items as $item) {
            $subtotal = $subtotal->add($item->subtotal());
        }

        return $subtotal;
    }

    /**
     * Undefined without a freight price, so it is an error to ask rather than
     * something to answer with the subtotal and hope the caller notices.
     */
    public function total(): Money
    {
        if ($this->shippingQuote === null) {
            throw OrderStateConflict::missingShippingQuote($this->id);
        }

        return $this->subtotal()->add($this->shippingQuote->amount);
    }

    public function totalWeightGrams(): int
    {
        $weight = 0;

        foreach ($this->items as $item) {
            $weight += $item->totalWeightGrams();
        }

        return $weight;
    }

    /**
     * @return list<OrderItem>
     */
    public function items(): array
    {
        return array_values($this->items);
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function shippingQuote(): ?ShippingQuote
    {
        return $this->shippingQuote;
    }

    public function confirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    private function assertNotConfirmed(): void
    {
        if ($this->status === OrderStatus::Confirmed) {
            throw OrderStateConflict::alreadyConfirmed($this->id);
        }
    }

    /**
     * Brazilian zip codes are commonly written as 01310-100. The separator is
     * presentation, so it is removed once here instead of being handled by every
     * caller and every carrier adapter.
     */
    private static function normalizeZipCode(string $zipCode): string
    {
        $digits = str_replace(['-', ' '], '', trim($zipCode));

        if (preg_match('/^[0-9]{8}$/', $digits) !== 1) {
            throw InvalidDestination::malformedZipCode($zipCode);
        }

        return $digits;
    }
}
