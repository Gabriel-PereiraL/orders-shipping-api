<?php

declare(strict_types=1);

namespace OrderApi\Tests\Support;

use OrderApi\Application\Port\OrderRepository;
use OrderApi\Domain\Order\Order;

/**
 * Orders are cloned in and out so that holding a reference to a saved order
 * cannot silently pass for having persisted a change, the way it would with a
 * real database.
 *
 * A shallow clone is enough: everything an order contains (items, money, quote)
 * is immutable.
 */
final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<string, Order> */
    private array $orders = [];

    public function save(Order $order): void
    {
        $this->orders[$order->id] = clone $order;
    }

    public function findById(string $id): ?Order
    {
        $order = $this->orders[$id] ?? null;

        return $order === null ? null : clone $order;
    }

    public function count(): int
    {
        return count($this->orders);
    }
}
