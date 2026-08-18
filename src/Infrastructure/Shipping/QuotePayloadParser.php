<?php

declare(strict_types=1);

namespace OrderApi\Infrastructure\Shipping;

use DateTimeImmutable;
use OrderApi\Application\Exception\ShippingProviderInvalidResponse;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;

/**
 * Turns what a carrier said into something this application is willing to
 * charge a customer.
 *
 * It exists as its own thing because "how we reached the carrier" and "whether
 * we can trust what it sent back" fail for different reasons and deserve
 * different answers: a network problem is worth retrying, a broken payload is
 * not.
 *
 * Nothing here trusts the payload: every field is checked before it reaches the
 * domain, which is why ShippingQuote itself can stay free of validation.
 */
final class QuotePayloadParser
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function parse(
        array $payload,
        string $currency,
        DateTimeImmutable $quotedAt,
        string $carrier,
    ): ShippingQuote {
        $service = $payload['service'] ?? null;
        $amountInCents = $payload['amount_cents'] ?? null;
        $estimatedDays = $payload['estimated_days'] ?? null;

        if (!is_string($service) || trim($service) === '') {
            throw ShippingProviderInvalidResponse::malformedPayload($carrier, 'missing or empty "service".');
        }

        if (!is_int($amountInCents) || $amountInCents < 0) {
            throw ShippingProviderInvalidResponse::malformedPayload(
                $carrier,
                '"amount_cents" must be a non-negative integer.',
            );
        }

        if (!is_int($estimatedDays) || $estimatedDays < 1) {
            throw ShippingProviderInvalidResponse::malformedPayload(
                $carrier,
                '"estimated_days" must be a positive integer.',
            );
        }

        return new ShippingQuote(
            $carrier,
            $service,
            Money::fromCents($amountInCents, $currency),
            $estimatedDays,
            $quotedAt,
        );
    }
}
