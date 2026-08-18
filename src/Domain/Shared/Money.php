<?php

declare(strict_types=1);

namespace OrderApi\Domain\Shared;

use OrderApi\Domain\Exception\InvalidMoney;

/**
 * A monetary amount held as an integer number of minor units (cents).
 *
 * Floats are never used for money: 0.1 + 0.2 !== 0.3, and that error compounds
 * once you multiply by quantities and sum order lines. Storing cents keeps every
 * operation exact.
 *
 * There is no subtract(): this domain never produces a negative amount (no
 * refunds, no discounts), so allowing one would only widen the set of states we
 * would have to defend against.
 */
final readonly class Money
{
    private function __construct(
        public int $cents,
        public string $currency,
    ) {
    }

    public static function fromCents(int $cents, string $currency): self
    {
        if ($cents < 0) {
            throw InvalidMoney::negativeAmount($cents);
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw InvalidMoney::malformedCurrency($currency);
        }

        return new self($cents, $currency);
    }

    public static function zero(string $currency): self
    {
        return self::fromCents(0, $currency);
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw InvalidMoney::currencyMismatch($this->currency, $other->currency);
        }

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        if ($factor < 0) {
            throw InvalidMoney::negativeFactor($factor);
        }

        return new self($this->cents * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }
}
