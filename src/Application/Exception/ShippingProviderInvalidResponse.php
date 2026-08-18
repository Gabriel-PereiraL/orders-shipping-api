<?php

declare(strict_types=1);

namespace OrderApi\Application\Exception;

/**
 * The carrier answered, but with something we cannot trust as a price.
 *
 * Retrying will not help while the carrier keeps sending the same payload, so
 * this is kept apart from an outage: it is a broken contract, not an outage.
 */
final class ShippingProviderInvalidResponse extends ShippingProviderFailure
{
    public static function malformedPayload(string $carrier, string $detail): self
    {
        return new self(sprintf('Carrier %s returned an unusable payload: %s', $carrier, $detail));
    }
}
