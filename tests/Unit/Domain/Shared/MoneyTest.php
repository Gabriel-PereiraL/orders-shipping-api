<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Domain\Shared;

use OrderApi\Domain\Exception\InvalidMoney;
use OrderApi\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testItSumsAmountsOfTheSameCurrency(): void
    {
        $total = Money::fromCents(1050, 'BRL')->add(Money::fromCents(2575, 'BRL'));

        self::assertSame(3625, $total->cents);
        self::assertSame('BRL', $total->currency);
    }

    public function testItMultipliesByAQuantity(): void
    {
        $line = Money::fromCents(1999, 'BRL')->multipliedBy(3);

        self::assertSame(5997, $line->cents);
    }

    /**
     * The whole reason Money exists: 19.99 * 3 in floating point is
     * 59.97000000000001, which becomes a wrong total once persisted.
     */
    public function testItStaysExactWhereFloatingPointWouldDrift(): void
    {
        $total = Money::zero('BRL');

        for ($i = 0; $i < 10; $i++) {
            $total = $total->add(Money::fromCents(10, 'BRL'));
        }

        self::assertSame(100, $total->cents);
    }

    public function testOperationsReturnNewInstancesAndLeaveTheOriginalUntouched(): void
    {
        $price = Money::fromCents(1000, 'BRL');

        $price->add(Money::fromCents(500, 'BRL'));
        $price->multipliedBy(4);

        self::assertSame(1000, $price->cents);
    }

    public function testItRejectsNegativeAmounts(): void
    {
        $this->expectException(InvalidMoney::class);
        $this->expectExceptionMessage('cannot be negative');

        Money::fromCents(-1, 'BRL');
    }

    public function testItRejectsMixingCurrencies(): void
    {
        $this->expectException(InvalidMoney::class);
        $this->expectExceptionMessage('Cannot operate on BRL and USD amounts.');

        Money::fromCents(100, 'BRL')->add(Money::fromCents(100, 'USD'));
    }

    public function testItRejectsNegativeMultipliers(): void
    {
        $this->expectException(InvalidMoney::class);

        Money::fromCents(100, 'BRL')->multipliedBy(-2);
    }

    #[DataProvider('malformedCurrencies')]
    public function testItRejectsMalformedCurrencyCodes(string $currency): void
    {
        $this->expectException(InvalidMoney::class);

        Money::fromCents(100, $currency);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCurrencies(): iterable
    {
        yield 'lowercase' => ['brl'];
        yield 'too short' => ['BR'];
        yield 'too long' => ['BRLX'];
        yield 'empty' => [''];
        yield 'digits' => ['123'];
    }

    public function testMultiplyingByZeroYieldsZero(): void
    {
        self::assertTrue(
            Money::fromCents(4990, 'BRL')->multipliedBy(0)->equals(Money::zero('BRL')),
        );
    }

    public function testAmountsOfDifferentCurrenciesAreNeverEqual(): void
    {
        self::assertFalse(
            Money::fromCents(100, 'BRL')->equals(Money::fromCents(100, 'USD')),
        );
    }
}
