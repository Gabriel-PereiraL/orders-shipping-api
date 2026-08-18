<?php

declare(strict_types=1);

namespace OrderApi\Application\Exception;

/**
 * The carrier is reachable in principle but did not answer usefully: it timed
 * out, refused the connection or returned a server error.
 *
 * All of these are worth retrying later, which is what makes them one class.
 */
final class ShippingProviderUnavailable extends ShippingProviderFailure
{
    public static function timedOut(string $carrier, float $timeoutSeconds): self
    {
        return new self(sprintf('Carrier %s did not respond within %.1fs.', $carrier, $timeoutSeconds));
    }

    public static function unreachable(string $carrier, string $reason): self
    {
        return new self(sprintf('Carrier %s is unreachable: %s', $carrier, $reason));
    }

    public static function serverError(string $carrier, int $statusCode): self
    {
        return new self(sprintf('Carrier %s answered with HTTP %d.', $carrier, $statusCode));
    }
}
