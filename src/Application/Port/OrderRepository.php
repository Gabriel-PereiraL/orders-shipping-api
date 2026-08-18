<?php

declare(strict_types=1);

namespace OrderApi\Application\Port;

use OrderApi\Domain\Order\Order;

interface OrderRepository
{
    /**
     * Creates or updates the order as a whole: an order is only ever loaded and
     * stored complete, so there is no separate method for items or for the quote.
     */
    public function save(Order $order): void;

    public function findById(string $id): ?Order;
}
