<?php

declare(strict_types=1);

namespace OrderApi\Application\Exception;

use RuntimeException;

/**
 * The carrier could not give us a price.
 *
 * This is not a business rule being enforced, it is a dependency letting us
 * down, so it never becomes a 4xx: the client did nothing wrong.
 */
abstract class ShippingProviderFailure extends RuntimeException
{
}
