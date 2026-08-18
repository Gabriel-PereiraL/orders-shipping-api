<?php

declare(strict_types=1);

namespace OrderApi\Application\Port;

use OrderApi\Application\Exception\ShippingProviderInvalidResponse;
use OrderApi\Application\Exception\ShippingProviderUnavailable;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;

/**
 * The one place where this application talks to a carrier.
 *
 * The port is defined in terms of what an order knows (a destination, a weight,
 * a declared value) rather than in terms of any carrier's API, so swapping the
 * carrier cannot reach further than its own adapter.
 *
 * The failure modes are part of the contract, not an implementation detail:
 * every adapter must reduce whatever went wrong to one of these two.
 */
interface ShippingQuoteProvider
{
    /**
     * @throws ShippingProviderUnavailable when the carrier cannot be reached or errored
     * @throws ShippingProviderInvalidResponse when the carrier answered something unusable
     */
    public function quoteFor(
        string $destinationZipCode,
        int $totalWeightGrams,
        Money $declaredValue,
    ): ShippingQuote;
}
