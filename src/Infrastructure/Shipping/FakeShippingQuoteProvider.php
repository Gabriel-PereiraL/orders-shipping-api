<?php

declare(strict_types=1);

namespace OrderApi\Infrastructure\Shipping;

use OrderApi\Application\Exception\ShippingProviderInvalidResponse;
use OrderApi\Application\Exception\ShippingProviderUnavailable;
use OrderApi\Application\Port\Clock;
use OrderApi\Application\Port\ShippingQuoteProvider;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;

/**
 * A carrier that runs locally, so the project can be cloned and used without an
 * account at a real logistics company.
 *
 * It is not a stub that always says "25.00". It prices by billable weight and
 * declared value, and it reproduces the three ways a real integration fails, on
 * demand, by destination zip code:
 *
 *   999xxxxx  the carrier never answers        -> ShippingProviderUnavailable
 *   998xxxxx  the carrier cannot be reached    -> ShippingProviderUnavailable
 *   997xxxxx  the carrier answers with garbage -> ShippingProviderInvalidResponse
 *
 * Anything else is quoted normally. Reserving zip prefixes rather than adding a
 * "simulate failure" flag keeps the failures reachable from a plain HTTP call,
 * which is the only way they get exercised by hand as well as by tests.
 */
final readonly class FakeShippingQuoteProvider implements ShippingQuoteProvider
{
    public const CARRIER = 'ACME Express (simulated)';

    private const SERVICE = 'standard';
    private const TIMEOUT_SECONDS = 2.0;

    private const ZIP_PREFIX_TIMEOUT = '999';
    private const ZIP_PREFIX_UNREACHABLE = '998';
    private const ZIP_PREFIX_INVALID_PAYLOAD = '997';

    private const BASE_CENTS = 1500;
    private const CENTS_PER_BILLABLE_KILO = 800;
    private const INSURANCE_PERCENT = 1;

    public function __construct(private Clock $clock)
    {
    }

    public function quoteFor(
        string $destinationZipCode,
        int $totalWeightGrams,
        Money $declaredValue,
    ): ShippingQuote {
        $payload = $this->call($destinationZipCode, $totalWeightGrams, $declaredValue);

        return QuotePayloadParser::parse($payload, $declaredValue->currency, $this->clock->now(), self::CARRIER);
    }

    /**
     * Stands in for the network round trip: either it fails the way a carrier
     * fails, or it produces the raw payload a carrier would have sent.
     *
     * @return array<string, mixed>
     */
    private function call(string $destinationZipCode, int $totalWeightGrams, Money $declaredValue): array
    {
        $prefix = substr($destinationZipCode, 0, 3);

        if ($prefix === self::ZIP_PREFIX_TIMEOUT) {
            throw ShippingProviderUnavailable::timedOut(self::CARRIER, self::TIMEOUT_SECONDS);
        }

        if ($prefix === self::ZIP_PREFIX_UNREACHABLE) {
            throw ShippingProviderUnavailable::unreachable(self::CARRIER, 'connection refused');
        }

        if ($prefix === self::ZIP_PREFIX_INVALID_PAYLOAD) {
            // A carrier that changed its contract without telling anyone: the
            // price is there, but as a formatted string in an unknown field.
            return ['carrier' => self::CARRIER, 'price' => 'R$ 25,00', 'delivery' => null];
        }

        return [
            'service' => self::SERVICE,
            'amount_cents' => $this->priceFor($totalWeightGrams, $declaredValue),
            'estimated_days' => $this->estimatedDaysFor($destinationZipCode),
        ];
    }

    private function priceFor(int $totalWeightGrams, Money $declaredValue): int
    {
        $billableKilos = max(1, (int) ceil($totalWeightGrams / 1000));
        $insurance = intdiv($declaredValue->cents * self::INSURANCE_PERCENT, 100);

        return self::BASE_CENTS + ($billableKilos * self::CENTS_PER_BILLABLE_KILO) + $insurance;
    }

    /**
     * The first digit of a Brazilian zip code is its region, so distance from
     * the (imaginary) warehouse in region 0 is a good enough proxy for transit
     * time.
     */
    private function estimatedDaysFor(string $destinationZipCode): int
    {
        return 2 + (int) $destinationZipCode[0];
    }
}
