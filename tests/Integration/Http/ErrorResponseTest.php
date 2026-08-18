<?php

declare(strict_types=1);

namespace OrderApi\Tests\Integration\Http;

use OrderApi\Http\Middleware\RequestId;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * What the API answers when things go wrong.
 *
 * These assertions are the contract: a client is entitled to tell "your request
 * is wrong" (4xx) from "our carrier is down" (5xx), and to know which of those
 * is worth retrying.
 */
final class ErrorResponseTest extends ApiTestCase
{
    public function testAMalformedPayloadListsEveryOffendingField(): void
    {
        $response = $this->request('POST', '/products', ['sku' => '', 'price_cents' => '24990']);
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('invalid_request', $body['type']);
        self::assertArrayHasKey('sku', $body['errors']);
        self::assertArrayHasKey('name', $body['errors']);
        self::assertArrayHasKey('price_cents', $body['errors']);
        self::assertArrayHasKey('weight_grams', $body['errors']);
    }

    public function testABodyThatIsNotAnObjectIsRejected(): void
    {
        $response = $this->request('POST', '/products', []);

        self::assertSame(422, $response->getStatusCode());
    }

    #[DataProvider('invalidQuantities')]
    public function testAnImpossibleQuantityIsRejected(int $quantity): void
    {
        $productId = $this->createProduct();

        $response = $this->request('POST', '/orders', [
            'destination_zip_code' => '01310100',
            'items' => [['product_id' => $productId, 'quantity' => $quantity]],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $this->json($response)['type']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    public function testAnOrderWithNoItemsIsRejected(): void
    {
        $response = $this->request('POST', '/orders', [
            'destination_zip_code' => '01310100',
            'items' => [],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('items', $this->json($response)['errors']);
    }

    public function testAMalformedZipCodeIsRejected(): void
    {
        $productId = $this->createProduct();

        $response = $this->request('POST', '/orders', [
            'destination_zip_code' => '123',
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testOrderingAProductThatDoesNotExistIsNotFound(): void
    {
        $response = $this->request('POST', '/orders', [
            'destination_zip_code' => '01310100',
            'items' => [['product_id' => 'no-such-product', 'quantity' => 1]],
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['type']);
    }

    public function testAnUnknownOrderIsNotFound(): void
    {
        self::assertSame(404, $this->request('GET', '/orders/no-such-order')->getStatusCode());
    }

    public function testAnUnknownRouteAnswersInTheSameShape(): void
    {
        $response = $this->request('GET', '/there-is-nothing-here');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['type']);
    }

    public function testAMethodThatIsNotAllowedIsReported(): void
    {
        $response = $this->request('DELETE', '/health');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('method_not_allowed', $this->json($response)['type']);
    }

    public function testAnOrderCannotBeConfirmedBeforeItIsQuoted(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('01310100', [['product_id' => $productId, 'quantity' => 1]]);

        $response = $this->request('POST', sprintf('/orders/%s/confirm', $orderId));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('order_state_conflict', $this->json($response)['type']);
    }

    public function testConfirmingTwiceIsAConflict(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('01310100', [['product_id' => $productId, 'quantity' => 1]]);
        $this->request('POST', sprintf('/orders/%s/shipping-quote', $orderId));
        $this->request('POST', sprintf('/orders/%s/confirm', $orderId));

        $response = $this->request('POST', sprintf('/orders/%s/confirm', $orderId));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('already confirmed', (string) $this->json($response)['title']);
    }

    public function testAConfirmedOrderCannotBeQuotedAgain(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('01310100', [['product_id' => $productId, 'quantity' => 1]]);
        $this->request('POST', sprintf('/orders/%s/shipping-quote', $orderId));
        $this->request('POST', sprintf('/orders/%s/confirm', $orderId));

        self::assertSame(409, $this->request('POST', sprintf('/orders/%s/shipping-quote', $orderId))->getStatusCode());
    }

    public function testTwoProductsCannotShareASku(): void
    {
        $this->createProduct(['sku' => 'SAME-SKU']);

        $response = $this->request('POST', '/products', [
            'sku' => 'SAME-SKU',
            'name' => 'Another product',
            'price_cents' => 1000,
            'weight_grams' => 100,
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('duplicate_sku', $this->json($response)['type']);
    }

    /**
     * A carrier outage is ours to retry, so it is a 503 and it says when.
     */
    public function testACarrierOutageIsAnswerableAndRetryable(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('99900000', [['product_id' => $productId, 'quantity' => 1]]);

        $response = $this->request('POST', sprintf('/orders/%s/shipping-quote', $orderId));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('shipping_provider_unavailable', $this->json($response)['type']);
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
    }

    /**
     * A carrier that answers with something unusable is a broken contract on
     * their side, which is a different problem and a different status.
     */
    public function testACarrierAnsweringRubbishIsABadGateway(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('99700000', [['product_id' => $productId, 'quantity' => 1]]);

        $response = $this->request('POST', sprintf('/orders/%s/shipping-quote', $orderId));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('shipping_provider_invalid_response', $this->json($response)['type']);
        self::assertSame('', $response->getHeaderLine('Retry-After'));
    }

    public function testAFailedQuoteLeavesTheOrderUsable(): void
    {
        $productId = $this->createProduct();
        $orderId = $this->createOrder('99900000', [['product_id' => $productId, 'quantity' => 1]]);

        $this->request('POST', sprintf('/orders/%s/shipping-quote', $orderId));

        $order = $this->json($this->request('GET', sprintf('/orders/%s', $orderId)));
        self::assertSame('draft', $order['status']);
        self::assertNull($order['shipping']);
    }

    public function testEveryResponseCarriesARequestId(): void
    {
        $response = $this->request('GET', '/orders/no-such-order');

        self::assertNotSame('', $response->getHeaderLine(RequestId::HEADER));
        self::assertSame(
            $response->getHeaderLine(RequestId::HEADER),
            $this->json($response)['request_id'],
        );
    }

    public function testACallerSuppliedRequestIdIsKept(): void
    {
        $response = $this->app()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/health')
                ->withHeader(RequestId::HEADER, 'trace-from-the-caller'),
        );

        self::assertSame('trace-from-the-caller', $response->getHeaderLine(RequestId::HEADER));
    }
}
