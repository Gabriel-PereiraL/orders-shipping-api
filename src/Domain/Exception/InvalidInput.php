<?php

declare(strict_types=1);

namespace OrderApi\Domain\Exception;

/**
 * The caller asked for something the business considers malformed: a negative
 * quantity, a nameless product, a mixed-currency sum.
 *
 * Distinct from a state conflict: retrying with the same payload will never
 * succeed, so the HTTP layer answers 422 for this whole branch.
 */
abstract class InvalidInput extends DomainException
{
}
