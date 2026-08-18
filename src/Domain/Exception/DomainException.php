<?php

declare(strict_types=1);

namespace OrderApi\Domain\Exception;

use RuntimeException;

/**
 * Base for every business rule violation raised by the domain.
 *
 * Infrastructure failures (database down, provider timeout) are NOT domain
 * exceptions: they are not the business saying "no", they are the environment
 * failing. Keeping them apart is what lets the HTTP layer answer 4xx here and
 * 5xx there without inspecting messages.
 */
abstract class DomainException extends RuntimeException
{
}
