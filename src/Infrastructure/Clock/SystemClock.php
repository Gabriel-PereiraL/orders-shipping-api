<?php

declare(strict_types=1);

namespace OrderApi\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use OrderApi\Application\Port\Clock;

/**
 * Always UTC. Storing and comparing local times is how quote expiry silently
 * shifts by an hour twice a year.
 */
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
