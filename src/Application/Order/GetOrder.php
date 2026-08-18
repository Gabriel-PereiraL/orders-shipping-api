<?php

declare(strict_types=1);

namespace OrderApi\Application\Order;

use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Port\OrderRepository;
use OrderApi\Domain\Order\Order;

final readonly class GetOrder
{
    public function __construct(private OrderRepository $orders)
    {
    }

    public function execute(string $id): Order
    {
        return $this->orders->findById($id) ?? throw ResourceNotFound::order($id);
    }
}
