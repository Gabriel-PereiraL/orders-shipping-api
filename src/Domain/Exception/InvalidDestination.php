<?php

declare(strict_types=1);

namespace OrderApi\Domain\Exception;

final class InvalidDestination extends InvalidInput
{
    public static function malformedZipCode(string $zipCode): self
    {
        return new self(sprintf('Destination zip code must have 8 digits, got "%s".', $zipCode));
    }
}
