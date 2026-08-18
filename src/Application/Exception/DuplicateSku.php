<?php

declare(strict_types=1);

namespace OrderApi\Application\Exception;

use RuntimeException;

/**
 * Two products cannot share a stock keeping unit.
 *
 * The check is the database's unique key rather than a read-then-write in PHP:
 * a read-then-write is a race, and uniqueness across rows is precisely the kind
 * of structural integrity the database is there to guarantee. The repository
 * turns that constraint violation back into a sentence the application can act
 * on.
 */
final class DuplicateSku extends RuntimeException
{
    public static function forSku(string $sku): self
    {
        return new self(sprintf('A product with sku "%s" already exists.', $sku));
    }
}
