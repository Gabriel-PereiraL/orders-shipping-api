<?php

declare(strict_types=1);

namespace OrderApi\Domain\Shipping;

use DateTimeImmutable;
use OrderApi\Domain\Shared\Money;

/**
 * A freight price returned by a carrier at a point in time.
 *
 * This value object deliberately validates nothing. It is only ever built by a
 * shipping provider adapter, and those adapters already reject malformed carrier
 * payloads with a provider-level failure. Re-checking here would either duplicate
 * that validation or, worse, report a broken carrier response as if the client
 * had sent bad input.
 */
final readonly class ShippingQuote
{
    public function __construct(
        public string $carrier,
        public string $service,
        public Money $amount,
        public int $estimatedDays,
        public DateTimeImmutable $quotedAt,
    ) {
    }

    /**
     * Carriers price against a moment in time; an old quote is not a price we
     * are still entitled to charge.
     */
    public function isExpiredAt(DateTimeImmutable $now, int $ttlSeconds): bool
    {
        return ($now->getTimestamp() - $this->quotedAt->getTimestamp()) > $ttlSeconds;
    }
}
