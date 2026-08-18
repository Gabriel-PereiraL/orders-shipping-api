<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Infrastructure\Shipping;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OrderApi\Application\Exception\ShippingProviderInvalidResponse;
use OrderApi\Application\Exception\ShippingProviderUnavailable;
use OrderApi\Domain\Shared\Money;
use OrderApi\Infrastructure\Shipping\HttpShippingQuoteProvider;
use OrderApi\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The carrier is replaced at the transport layer, not at the port: the adapter
 * under test builds a real request and reads a real response, so what is proven
 * here is the translation between HTTP and this application's vocabulary.
 */
final class HttpShippingQuoteProviderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    public function testItTurnsACarrierPriceIntoAQuote(): void
    {
        $provider = $this->providerAnswering(new Response(200, [], (string) json_encode([
            'service' => 'express',
            'amount_cents' => 3450,
            'estimated_days' => 3,
        ])));

        $quote = $provider->quoteFor('01310100', 1200, Money::fromCents(50000, 'BRL'));

        self::assertSame('express', $quote->service);
        self::assertSame(3450, $quote->amount->cents);
        self::assertSame('BRL', $quote->amount->currency);
        self::assertSame(3, $quote->estimatedDays);
        self::assertSame('Example Carrier', $quote->carrier);
        self::assertEquals((new FixedClock('2026-03-10 10:00:00'))->now(), $quote->quotedAt);
    }

    public function testItSendsWhatTheCarrierNeedsToPrice(): void
    {
        $provider = $this->providerAnswering(new Response(200, [], (string) json_encode([
            'service' => 'standard',
            'amount_cents' => 1000,
            'estimated_days' => 5,
        ])));

        $provider->quoteFor('01310100', 1200, Money::fromCents(50000, 'BRL'));

        self::assertCount(1, $this->sent);
        $request = $this->sent[0]['request'];
        self::assertInstanceOf(Request::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/quotes', $request->getUri()->getPath());
        self::assertSame([
            'destination_zip_code' => '01310100',
            'weight_grams' => 1200,
            'declared_value_cents' => 50000,
            'currency' => 'BRL',
        ], json_decode((string) $request->getBody(), true));
    }

    /**
     * An integration without a deadline is a way to take your own service down
     * when someone else's is slow.
     */
    public function testEveryCallCarriesADeadline(): void
    {
        $provider = $this->providerAnswering(new Response(200, [], (string) json_encode([
            'service' => 'standard',
            'amount_cents' => 1000,
            'estimated_days' => 5,
        ])));

        $provider->quoteFor('01310100', 500, Money::zero('BRL'));

        /** @var array<string, mixed> $options */
        $options = $this->sent[0]['options'];
        self::assertSame(2.0, $options['timeout']);
        self::assertSame(1.0, $options['connect_timeout']);
    }

    public function testATimeoutIsReportedAsAnOutage(): void
    {
        $provider = $this->providerAnswering(new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('POST', 'quotes'),
            null,
            ['errno' => 28],
        ));

        $this->expectException(ShippingProviderUnavailable::class);
        $this->expectExceptionMessage('did not respond within 2.0s');

        $provider->quoteFor('01310100', 500, Money::zero('BRL'));
    }

    public function testARefusedConnectionIsReportedAsAnOutage(): void
    {
        $provider = $this->providerAnswering(new ConnectException(
            'cURL error 7: Connection refused',
            new Request('POST', 'quotes'),
            null,
            ['errno' => 7],
        ));

        $this->expectException(ShippingProviderUnavailable::class);
        $this->expectExceptionMessage('unreachable');

        $provider->quoteFor('01310100', 500, Money::zero('BRL'));
    }

    #[DataProvider('serverErrors')]
    public function testACarrierServerErrorIsWorthRetrying(int $status): void
    {
        $provider = $this->providerAnswering(new Response($status, [], 'upstream exploded'));

        $this->expectException(ShippingProviderUnavailable::class);
        $this->expectExceptionMessage(sprintf('answered with HTTP %d', $status));

        $provider->quoteFor('01310100', 500, Money::zero('BRL'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function serverErrors(): iterable
    {
        yield 'internal error' => [500];
        yield 'bad gateway' => [502];
        yield 'unavailable' => [503];
        yield 'gateway timeout' => [504];
    }

    /**
     * A 4xx means the carrier rejected our request. Retrying the identical
     * request cannot fix that, so it must not be reported as an outage.
     */
    #[DataProvider('clientErrors')]
    public function testARejectedRequestIsNotAnOutage(int $status): void
    {
        $provider = $this->providerAnswering(new Response($status, [], '{"error":"nope"}'));

        $this->expectException(ShippingProviderInvalidResponse::class);

        $provider->quoteFor('01310100', 500, Money::zero('BRL'));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function clientErrors(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'unprocessable' => [422];
    }

    #[DataProvider('unusableBodies')]
    public function testAnUnusableBodyIsReportedAsABrokenContract(string $body): void
    {
        $provider = $this->providerAnswering(new Response(200, [], $body));

        $this->expectException(ShippingProviderInvalidResponse::class);

        $provider->quoteFor('01310100', 500, Money::zero('BRL'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'not json' => ['<html>maintenance</html>'];
        yield 'empty' => [''];
        yield 'a list instead of an object' => ['[{"amount_cents":1000}]'];
        yield 'missing the price' => ['{"service":"standard","estimated_days":3}'];
        yield 'price as a formatted string' => ['{"service":"standard","amount_cents":"R$ 25,00","estimated_days":3}'];
        yield 'price as a float' => ['{"service":"standard","amount_cents":25.5,"estimated_days":3}'];
        yield 'negative price' => ['{"service":"standard","amount_cents":-100,"estimated_days":3}'];
        yield 'nonsense delivery time' => ['{"service":"standard","amount_cents":1000,"estimated_days":0}'];
        yield 'missing the service' => ['{"amount_cents":1000,"estimated_days":3}'];
    }

    private function providerAnswering(Response|Throwable $answer): HttpShippingQuoteProvider
    {
        $stack = HandlerStack::create(new MockHandler([$answer]));
        $stack->push(Middleware::history($this->sent));

        return new HttpShippingQuoteProvider(
            new Client(['handler' => $stack, 'base_uri' => 'https://carrier.example.com/v1/']),
            new FixedClock('2026-03-10 10:00:00'),
            'Example Carrier',
        );
    }
}
