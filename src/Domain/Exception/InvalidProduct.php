<?php

declare(strict_types=1);

namespace OrderApi\Domain\Exception;

final class InvalidProduct extends InvalidInput
{
    public static function blankField(string $field): self
    {
        return new self(sprintf('Product %s cannot be blank.', $field));
    }

    public static function notShippable(int $weightGrams): self
    {
        return new self(sprintf('Product weight must be at least 1 gram, got %d.', $weightGrams));
    }
}
