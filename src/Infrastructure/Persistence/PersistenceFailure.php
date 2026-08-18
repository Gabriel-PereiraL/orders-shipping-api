<?php

declare(strict_types=1);

namespace OrderApi\Infrastructure\Persistence;

use RuntimeException;
use Throwable;

/**
 * The database could not do what it was asked.
 *
 * Driver exceptions are wrapped rather than left to travel up the stack so that
 * the layers above never have to know that PDO exists, and so that a SQL string
 * cannot end up in an HTTP response by accident.
 */
final class PersistenceFailure extends RuntimeException
{
    public static function while(string $action, Throwable $previous): self
    {
        return new self(sprintf('Database failure while %s.', $action), 0, $previous);
    }
}
