<?php

declare(strict_types=1);

namespace OrderApi\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use OrderApi\Application\Port\ShippingQuoteProvider;
use OrderApi\Domain\Shared\Money;
use OrderApi\Domain\Shipping\ShippingQuote;
use Throwable;

/**
 * A carrier whose answer the test chooses, so use cases can be exercised
 * against both a price and every failure the port is allowed to raise.
 */
final class StubShippingQuoteProvider implements ShippingQuoteProvider
{
    public int $calls = 0;

    private function __construct(
        private readonly ?ShippingQuote $quote,
        private readonly ?Throwable $failure,
    ) {
    }

    public static function returning(ShippingQuote $quote): self
    {
        return new self($quote, null);
    }

    public static function quoting(int $cents, string $quotedAt = '2026-03-10 10:00:00'): self
    {
        return self::returning(new ShippingQuote(
            'STUB',
            'standard',
            Money::fromCents($cents, 'BRL'),
            5,
            new DateTimeImmutable($quotedAt, new DateTimeZone('UTC')),
        ));
    }

    public static function failingWith(Throwable $failure): self
    {
        return new self(null, $failure);
    }

    public function quoteFor(
        string $destinationZipCode,
        int $totalWeightGrams,
        Money $declaredValue,
    ): ShippingQuote {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        assert($this->quote !== null);

        return $this->quote;
    }
}
