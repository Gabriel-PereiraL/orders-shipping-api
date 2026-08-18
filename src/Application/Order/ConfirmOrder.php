<?php

declare(strict_types=1);

namespace OrderApi\Application\Order;

use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Port\Clock;
use OrderApi\Application\Port\OrderRepository;
use OrderApi\Domain\Order\Order;

/**
 * How long a quote stays valid is a business policy that changes without the
 * rules changing, so the number is configuration injected here, while the rule
 * that an expired quote cannot be charged stays in the order.
 */
final readonly class ConfirmOrder
{
    public function __construct(
        private OrderRepository $orders,
        private Clock $clock,
        private int $quoteTtlSeconds,
    ) {
    }

    public function execute(string $orderId): Order
    {
        $order = $this->orders->findById($orderId) ?? throw ResourceNotFound::order($orderId);

        $order->confirm($this->clock->now(), $this->quoteTtlSeconds);
        $this->orders->save($order);

        return $order;
    }
}
