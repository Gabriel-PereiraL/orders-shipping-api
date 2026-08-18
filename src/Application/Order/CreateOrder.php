<?php

declare(strict_types=1);

namespace OrderApi\Application\Order;

use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Port\Clock;
use OrderApi\Application\Port\OrderRepository;
use OrderApi\Application\Port\ProductRepository;
use OrderApi\Domain\Order\Order;
use Ramsey\Uuid\Uuid;

/**
 * Coordinates, decides nothing: it resolves product ids into products, hands
 * them to the order and stores the result. Every rule about what a valid line
 * is belongs to the order itself.
 */
final readonly class CreateOrder
{
    public function __construct(
        private OrderRepository $orders,
        private ProductRepository $products,
        private Clock $clock,
        private string $currency,
    ) {
    }

    /**
     * Products are fetched one by one. Orders here hold a handful of lines, so a
     * batch lookup would buy nothing and cost a less obvious repository.
     *
     * @param list<array{productId: string, quantity: int}> $requestedItems
     */
    public function execute(string $destinationZipCode, array $requestedItems): Order
    {
        $order = new Order(
            Uuid::uuid7()->toString(),
            $destinationZipCode,
            $this->currency,
            $this->clock->now(),
        );

        foreach ($requestedItems as $requestedItem) {
            $product = $this->products->findById($requestedItem['productId'])
                ?? throw ResourceNotFound::product($requestedItem['productId']);

            $order->addItem($product, $requestedItem['quantity']);
        }

        $this->orders->save($order);

        return $order;
    }
}
