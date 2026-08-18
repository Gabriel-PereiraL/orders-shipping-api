<?php

declare(strict_types=1);

namespace OrderApi\Http\Exception;

use RuntimeException;

/**
 * The request body is not shaped the way the endpoint requires.
 *
 * This is about shape, not about business rules: "quantity is missing" is
 * caught here, "quantity must be at least 1" belongs to the domain. Keeping the
 * two apart is what stops HTTP concerns leaking inward and business rules
 * leaking outward.
 *
 * Every offending field is collected before answering, so a client fixes one
 * round trip's worth of mistakes at a time.
 */
final class InvalidRequestPayload extends RuntimeException
{
    /**
     * @param array<string, string> $errors field name => what is wrong with it
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The request payload is invalid.');
    }

    public static function notAnObject(): self
    {
        return new self(['body' => 'Expected a JSON object.']);
    }
}
