<?php

declare(strict_types=1);

namespace OrderApi\Http\Presenter;

use DateTimeImmutable;
use DateTimeInterface;
use OrderApi\Domain\Order\Order;
use OrderApi\Domain\Order\OrderItem;
use OrderApi\Domain\Shared\Money;

/**
 * The order as the API describes it.
 *
 * "total" is null until the freight is known, rather than quietly equal to the
 * subtotal: a client that shows a total before shipping has been quoted is
 * showing a number the customer will not be charged.
 */
final class OrderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Order $order): array
    {
        $quote = $order->shippingQuote();

        return [
            'id' => $order->id,
            'status' => $order->status()->value,
            'destination_zip_code' => $order->destinationZipCode,
            'items' => array_map(self::item(...), $order->items()),
            'subtotal' => self::money($order->subtotal()),
            'shipping' => $quote === null ? null : [
                'carrier' => $quote->carrier,
                'service' => $quote->service,
                'amount' => self::money($quote->amount),
                'estimated_days' => $quote->estimatedDays,
                'quoted_at' => self::timestamp($quote->quotedAt),
            ],
            'total' => $quote === null ? null : self::money($order->total()),
            'created_at' => self::timestamp($order->createdAt),
            'confirmed_at' => self::timestamp($order->confirmedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(OrderItem $item): array
    {
        return [
            'product_id' => $item->productId,
            'product_name' => $item->productName,
            'unit_price' => self::money($item->unitPrice),
            'quantity' => $item->quantity,
            'subtotal' => self::money($item->subtotal()),
        ];
    }

    /**
     * @return array{amount_cents: int, currency: string}
     */
    private static function money(Money $money): array
    {
        return ['amount_cents' => $money->cents, 'currency' => $money->currency];
    }

    private static function timestamp(?DateTimeImmutable $moment): ?string
    {
        return $moment?->format(DateTimeInterface::ATOM);
    }
}
