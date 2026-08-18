<?php

declare(strict_types=1);

namespace OrderApi\Domain\Exception;

final class InvalidQuantity extends InvalidInput
{
    public static function notPositive(int $quantity): self
    {
        return new self(sprintf('Quantity must be at least 1, got %d.', $quantity));
    }
}
