<?php

declare(strict_types=1);

namespace OrderApi\Http\Input;

use OrderApi\Http\Exception\InvalidRequestPayload;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a JSON body into the types a use case expects, collecting every problem
 * instead of stopping at the first one.
 *
 * It is a reader, not a validator of business rules: it will happily hand over
 * a quantity of -3, because deciding that -3 is not a quantity is the domain's
 * job, not HTTP's.
 */
final class Payload
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    public static function of(ServerRequestInterface $request): self
    {
        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body) || array_is_list($body)) {
            throw InvalidRequestPayload::notAnObject();
        }

        /** @var array<string, mixed> $body */
        return new self($body);
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            $this->errors[$key] = 'Expected a non-empty string.';

            return '';
        }

        return trim($value);
    }

    public function integer(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (!is_int($value)) {
            $this->errors[$key] = 'Expected an integer.';

            return 0;
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public function listOf(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $this->errors[$key] = 'Expected a non-empty array.';

            return [];
        }

        return $value;
    }

    public function fail(string $key, string $message): void
    {
        $this->errors[$key] = $message;
    }

    /**
     * Call once, after reading every field: this is what turns the collected
     * problems into a single answer.
     */
    public function assertValid(): void
    {
        if ($this->errors !== []) {
            throw new InvalidRequestPayload($this->errors);
        }
    }
}
