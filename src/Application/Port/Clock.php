<?php

declare(strict_types=1);

namespace OrderApi\Application\Port;

use DateTimeImmutable;

/**
 * Reading the current time is an external dependency like any other.
 *
 * Shipping quotes expire, so "what time is it" changes the outcome of a use
 * case. Injecting it is what makes the expiry rule testable without sleeping.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
