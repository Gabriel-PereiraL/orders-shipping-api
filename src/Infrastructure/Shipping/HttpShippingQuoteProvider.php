<?php

declare(strict_types=1);

namespace OrderApi\Infrastructure\Shipping;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use OrderApi\Application\Exception\ShippingProviderInvalidResponse;
use OrderApi\Application\Exception\ShippingProviderUnavailable;
use OrderApi\Application\Port\Clock;
use OrderApi\Application\Port\ShippingQuoteProvider;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;

/**
 * The same port, over the network.
 *
 * Two things make this an integration rather than a wrapper around a client:
 *
 * 1. It always has a deadline. A carrier that answers in forty seconds is worse
 *    than one that fails fast, because our own request is held open the whole
 *    time. connect_timeout and timeout are set on every call and are small.
 *
 * 2. It never lets a transport concept escape. Guzzle exceptions, status codes
 *    and JSON errors are all turned into the two failures the port declares, so
 *    swapping this adapter cannot change how the rest of the application behaves.
 *
 * There is no retry here. Retrying belongs to whoever knows whether the request
 * is safe to repeat and how long the caller is willing to wait; hiding it inside
 * the adapter would double a customer's wait during exactly the outage the
 * timeout exists to bound.
 */
final readonly class HttpShippingQuoteProvider implements ShippingQuoteProvider
{
    /**
     * cURL's timeout code, spelled out so this file does not depend on
     * ext-curl being loaded just to name a number.
     */
    private const CURL_TIMEOUT_ERRNO = 28;

    public function __construct(
        private ClientInterface $client,
        private Clock $clock,
        private string $carrier,
        private string $quotePath = 'quotes',
        private float $timeoutSeconds = 2.0,
        private float $connectTimeoutSeconds = 1.0,
    ) {
    }

    public function quoteFor(
        string $destinationZipCode,
        int $totalWeightGrams,
        Money $declaredValue,
    ): ShippingQuote {
        $response = $this->call([
            'destination_zip_code' => $destinationZipCode,
            'weight_grams' => $totalWeightGrams,
            'declared_value_cents' => $declaredValue->cents,
            'currency' => $declaredValue->currency,
        ]);

        return QuotePayloadParser::parse(
            $response,
            $declaredValue->currency,
            $this->clock->now(),
            $this->carrier,
        );
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function call(array $request): array
    {
        try {
            $response = $this->client->request('POST', $this->quotePath, [
                'json' => $request,
                'timeout' => $this->timeoutSeconds,
                'connect_timeout' => $this->connectTimeoutSeconds,
                // Statuses are read below instead of being thrown: which status
                // means "down" and which means "broken" is this adapter's
                // decision, not the client library's.
                'http_errors' => false,
            ]);
        } catch (ConnectException $exception) {
            throw $this->fromConnectionFailure($exception);
        } catch (GuzzleException $exception) {
            throw ShippingProviderUnavailable::unreachable($this->carrier, $exception->getMessage());
        }

        $status = $response->getStatusCode();

        // 5xx is the carrier having a bad day and is worth retrying later; 4xx
        // means it rejected the request itself, which the same request will
        // never fix.
        if ($status >= 500) {
            throw ShippingProviderUnavailable::serverError($this->carrier, $status);
        }

        if ($status >= 400) {
            throw ShippingProviderInvalidResponse::malformedPayload(
                $this->carrier,
                sprintf('the request was rejected with HTTP %d.', $status),
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ShippingProviderInvalidResponse::malformedPayload(
                $this->carrier,
                'the body is not valid JSON.',
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw ShippingProviderInvalidResponse::malformedPayload(
                $this->carrier,
                'expected a JSON object.',
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function fromConnectionFailure(ConnectException $exception): ShippingProviderUnavailable
    {
        $context = $exception->getHandlerContext();
        $isTimeout = ($context['errno'] ?? null) === self::CURL_TIMEOUT_ERRNO;

        return $isTimeout
            ? ShippingProviderUnavailable::timedOut($this->carrier, $this->timeoutSeconds)
            : ShippingProviderUnavailable::unreachable($this->carrier, $exception->getMessage());
    }
}
