<?php

declare(strict_types=1);

namespace OrderApi\Tests\Integration\Http;

/**
 * The flow the API exists for, walked through over HTTP exactly as a client
 * would walk it.
 */
final class OrderFlowTest extends ApiTestCase
{
    public function testAnOrderIsCreatedQuotedConfirmedAndReadBack(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('01310-100', [['product_id' => $productId, 'quantity' => 2]]);

        $quoted = $this->json($this->request('POST', sprintf('/orders/%s/shipping-quote', $orderId)));

        self::assertSame('draft', $quoted['status']);
        self::assertIsArray($quoted['shipping']);
        self::assertSame(49980, $quoted['subtotal']['amount_cents']);
        self::assertSame(
            $quoted['subtotal']['amount_cents'] + $quoted['shipping']['amount']['amount_cents'],
            $quoted['total']['amount_cents'],
        );

        $confirmResponse = $this->request('POST', sprintf('/orders/%s/confirm', $orderId));
        self::assertSame(200, $confirmResponse->getStatusCode());
        self::assertSame('confirmed', $this->json($confirmResponse)['status']);

        $reloaded = $this->json($this->request('GET', sprintf('/orders/%s', $orderId)));
        self::assertSame('confirmed', $reloaded['status']);
        self::assertNotNull($reloaded['confirmed_at']);
        self::assertSame($quoted['total']['amount_cents'], $reloaded['total']['amount_cents']);
    }

    public function testADraftOrderHasNoTotalUntilShippingIsKnown(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('01310100', [['product_id' => $productId, 'quantity' => 1]]);

        $order = $this->json($this->request('GET', sprintf('/orders/%s', $orderId)));

        self::assertNull($order['shipping']);
        self::assertNull($order['total']);
        self::assertSame(24990, $order['subtotal']['amount_cents']);
    }

    public function testOrderingTheSameProductTwiceProducesOneLine(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('01310100', [
            ['product_id' => $productId, 'quantity' => 1],
            ['product_id' => $productId, 'quantity' => 2],
        ]);

        $order = $this->json($this->request('GET', sprintf('/orders/%s', $orderId)));

        self::assertCount(1, $order['items']);
        self::assertSame(3, $order['items'][0]['quantity']);
    }

    public function testTheOrderKeepsThePriceItWasPlacedAt(): void
    {
        $productId = $this->createProduct(['price_cents' => 10000]);
        $orderId = $this->createOrder('01310100', [['product_id' => $productId, 'quantity' => 1]]);

        // The catalogue moves on; the placed order must not.
        $this->createProduct(['sku' => 'KB-02', 'price_cents' => 99999]);

        $order = $this->json($this->request('GET', sprintf('/orders/%s', $orderId)));

        self::assertSame(10000, $order['subtotal']['amount_cents']);
    }

    public function testProductsAreCreatedAndReadBack(): void
    {
        $productId = $this->createProduct(['sku' => 'MS-01', 'name' => 'Wireless mouse', 'price_cents' => 10000, 'weight_grams' => 180]);

        $response = $this->request('GET', sprintf('/products/%s', $productId));
        $product = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('MS-01', $product['sku']);
        self::assertSame(['amount_cents' => 10000, 'currency' => 'BRL'], $product['price']);
    }

    public function testHealthIsReported(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], $this->json($response));
    }
}
