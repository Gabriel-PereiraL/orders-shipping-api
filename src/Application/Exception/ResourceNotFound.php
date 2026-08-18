<?php

declare(strict_types=1);

namespace OrderApi\Application\Exception;

use RuntimeException;

/**
 * The caller referenced something that does not exist.
 *
 * This lives in the application layer rather than the domain: the domain has no
 * concept of "stored" or "missing", it only reasons about objects it already
 * holds. Looking things up is the use case's job, so is the failure to find them.
 */
final class ResourceNotFound extends RuntimeException
{
    public static function product(string $id): self
    {
        return new self(sprintf('Product %s was not found.', $id));
    }
}
