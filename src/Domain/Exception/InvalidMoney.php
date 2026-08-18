<?php

declare(strict_types=1);

namespace OrderApi\Domain\Exception;

/**
 * Every way a monetary amount can be rejected.
 *
 * One class with named constructors instead of four exception classes: nothing
 * in the application catches these cases individually, so splitting them would
 * only add files.
 */
final class InvalidMoney extends DomainException
{
    public static function negativeAmount(int $cents): self
    {
        return new self(sprintf('Monetary amount cannot be negative, got %d cents.', $cents));
    }

    public static function malformedCurrency(string $currency): self
    {
        return new self(sprintf('Currency must be a 3-letter uppercase ISO 4217 code, got "%s".', $currency));
    }

    public static function currencyMismatch(string $left, string $right): self
    {
        return new self(sprintf('Cannot operate on %s and %s amounts.', $left, $right));
    }

    public static function negativeFactor(int $factor): self
    {
        return new self(sprintf('Cannot multiply an amount by a negative factor, got %d.', $factor));
    }
}
