<?php

declare(strict_types=1);

namespace OrderApi\Application\Order;

use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Port\OrderRepository;
use OrderApi\Application\Port\ShippingQuoteProvider;
use OrderApi\Domain\Order\Order;

final readonly class QuoteOrderShipping
{
    public function __construct(
        private OrderRepository $orders,
        private ShippingQuoteProvider $carrier,
    ) {
    }

    /**
     * Whether the order may be quoted at all is the order's decision, so the
     * quote is handed to it rather than checked here.
     */
    public function execute(string $orderId): Order
    {
        $order = $this->orders->findById($orderId) ?? throw ResourceNotFound::order($orderId);

        $quote = $this->carrier->quoteFor(
            $order->destinationZipCode,
            $order->totalWeightGrams(),
            $order->subtotal(),
        );

        $order->applyShippingQuote($quote);
        $this->orders->save($order);

        return $order;
    }
}
