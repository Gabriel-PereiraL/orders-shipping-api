<?php

declare(strict_types=1);

namespace OrderApi\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use OrderApi\Application\Port\Clock;

/**
 * A clock the test drives, so expiry can be reached without sleeping.
 */
final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-03-10 10:00:00')
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }
}
