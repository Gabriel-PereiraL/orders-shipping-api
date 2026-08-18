<?php

declare(strict_types=1);

namespace OrderApi\Domain\Exception;

/**
 * The request is well formed, but the order is not in a state that allows it.
 *
 * Unlike InvalidInput, the very same request could succeed at another moment
 * (or could have succeeded earlier), which is exactly what HTTP 409 means.
 */
final class OrderStateConflict extends DomainException
{
    public static function alreadyConfirmed(string $orderId): self
    {
        return new self(sprintf('Order %s is already confirmed and can no longer be changed.', $orderId));
    }

    public static function hasNoItems(string $orderId): self
    {
        return new self(sprintf('Order %s has no items.', $orderId));
    }

    public static function missingShippingQuote(string $orderId): self
    {
        return new self(sprintf('Order %s has no shipping quote yet.', $orderId));
    }

    public static function expiredShippingQuote(string $orderId): self
    {
        return new self(sprintf('The shipping quote for order %s has expired and must be requested again.', $orderId));
    }
}
