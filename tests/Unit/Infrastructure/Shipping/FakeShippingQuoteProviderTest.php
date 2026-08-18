<?php

declare(strict_types=1);

namespace OrderApi\Tests\Unit\Infrastructure\Shipping;

use OrderApi\Application\Exception\ShippingProviderFailure;
use OrderApi\Application\Exception\ShippingProviderInvalidResponse;
use OrderApi\Application\Exception\ShippingProviderUnavailable;
use OrderApi\Domain\Shared\Money;
use OrderApi\Infrastructure\Shipping\FakeShippingQuoteProvider;
use OrderApi\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FakeShippingQuoteProviderTest extends TestCase
{
    private FixedClock $clock;
    private FakeShippingQuoteProvider $carrier;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-03-10 10:00:00');
        $this->carrier = new FakeShippingQuoteProvider($this->clock);
    }

    public function testItPricesByBillableWeightAndDeclaredValue(): void
    {
        // 1500 base + 2 billable kilos * 800 + 1% of 50000 = 3600
        $quote = $this->carrier->quoteFor('01310100', 1200, Money::fromCents(50000, 'BRL'));

        self::assertSame(3600, $quote->amount->cents);
        self::assertSame('BRL', $quote->amount->currency);
        self::assertSame('standard', $quote->service);
        self::assertSame(FakeShippingQuoteProvider::CARRIER, $quote->carrier);
    }

    public function testWeightIsBilledInWholeKilos(): void
    {
        $justOverOneKilo = $this->carrier->quoteFor('01310100', 1001, Money::zero('BRL'));
        $twoKilos = $this->carrier->quoteFor('01310100', 2000, Money::zero('BRL'));

        self::assertSame($twoKilos->amount->cents, $justOverOneKilo->amount->cents);
    }

    public function testAVeryLightOrderStillPaysForOneKilo(): void
    {
        $quote = $this->carrier->quoteFor('01310100', 50, Money::zero('BRL'));

        self::assertSame(2300, $quote->amount->cents);
    }

    public function testDeliveryTimeGrowsWithDistance(): void
    {
        $nearby = $this->carrier->quoteFor('01310100', 500, Money::zero('BRL'));
        $faraway = $this->carrier->quoteFor('69900100', 500, Money::zero('BRL'));

        self::assertGreaterThan($nearby->estimatedDays, $faraway->estimatedDays);
    }

    public function testTheQuoteIsStampedWithTheTimeItWasGiven(): void
    {
        $quote = $this->carrier->quoteFor('01310100', 500, Money::zero('BRL'));

        self::assertEquals($this->clock->now(), $quote->quotedAt);
    }

    public function testACarrierThatNeverAnswersIsReportedAsUnavailable(): void
    {
        $this->expectException(ShippingProviderUnavailable::class);
        $this->expectExceptionMessage('did not respond within');

        $this->carrier->quoteFor('99900000', 500, Money::zero('BRL'));
    }

    public function testACarrierThatCannotBeReachedIsReportedAsUnavailable(): void
    {
        $this->expectException(ShippingProviderUnavailable::class);
        $this->expectExceptionMessage('unreachable');

        $this->carrier->quoteFor('99800000', 500, Money::zero('BRL'));
    }

    /**
     * A carrier answering with a price it forgot to make machine readable is a
     * different problem from a carrier being down, and must not be mistaken for
     * one: retrying would only produce the same broken payload.
     */
    public function testACarrierAnsweringWithAnUnusablePayloadIsReportedSeparately(): void
    {
        $this->expectException(ShippingProviderInvalidResponse::class);
        $this->expectExceptionMessage('unusable payload');

        $this->carrier->quoteFor('99700000', 500, Money::zero('BRL'));
    }

    #[DataProvider('failingDestinations')]
    public function testEveryFailureModeStaysInsideThePortContract(string $zipCode): void
    {
        $this->expectException(ShippingProviderFailure::class);

        $this->carrier->quoteFor($zipCode, 500, Money::zero('BRL'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failingDestinations(): iterable
    {
        yield 'timeout' => ['99900000'];
        yield 'unreachable' => ['99800000'];
        yield 'invalid payload' => ['99700000'];
    }
}
