<?php

declare(strict_types=1);

namespace OrderApi\Http\Controller;

use OrderApi\Application\Order\ConfirmOrder;
use OrderApi\Application\Order\CreateOrder;
use OrderApi\Application\Order\GetOrder;
use OrderApi\Application\Order\QuoteOrderShipping;
use OrderApi\Http\Input\Payload;
use OrderApi\Http\Presenter\Json;
use OrderApi\Http\Presenter\OrderPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class OrderController
{
    public function __construct(
        private CreateOrder $createOrder,
        private GetOrder $getOrder,
        private QuoteOrderShipping $quoteOrderShipping,
        private ConfirmOrder $confirmOrder,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = Payload::of($request);

        $destinationZipCode = $payload->string('destination_zip_code');
        $items = $this->readItems($payload);

        $payload->assertValid();

        $order = $this->createOrder->execute($destinationZipCode, $items);

        return Json::write($response, OrderPresenter::toArray($order), 201);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        return Json::write($response, OrderPresenter::toArray($this->getOrder->execute($arguments['id'])));
    }

    /**
     * Quoting is a POST because it is not free: it calls a carrier, and it
     * changes the order by attaching the price it got back.
     *
     * @param array<string, string> $arguments
     */
    public function quoteShipping(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        return Json::write($response, OrderPresenter::toArray($this->quoteOrderShipping->execute($arguments['id'])));
    }

    /**
     * @param array<string, string> $arguments
     */
    public function confirm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        return Json::write($response, OrderPresenter::toArray($this->confirmOrder->execute($arguments['id'])));
    }

    /**
     * Only shape is checked here. A quantity of -1 is passed through untouched,
     * because whether -1 is a quantity is not HTTP's call.
     *
     * @return list<array{productId: string, quantity: int}>
     */
    private function readItems(Payload $payload): array
    {
        $items = [];

        foreach ($payload->listOf('items') as $index => $item) {
            if (!is_array($item)) {
                $payload->fail(sprintf('items.%d', $index), 'Expected an object.');

                continue;
            }

            $productId = $item['product_id'] ?? null;
            $quantity = $item['quantity'] ?? null;

            if (!is_string($productId) || trim($productId) === '') {
                $payload->fail(sprintf('items.%d.product_id', $index), 'Expected a non-empty string.');
            }

            if (!is_int($quantity)) {
                $payload->fail(sprintf('items.%d.quantity', $index), 'Expected an integer.');
            }

            if (is_string($productId) && $productId !== '' && is_int($quantity)) {
                $items[] = ['productId' => $productId, 'quantity' => $quantity];
            }
        }

        return $items;
    }
}
