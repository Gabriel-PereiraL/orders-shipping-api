<?php

declare(strict_types=1);

namespace OrderApi\Http\Middleware;

use OrderApi\Application\Exception\DuplicateSku;
use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Exception\ShippingProviderInvalidResponse;
use OrderApi\Application\Exception\ShippingProviderUnavailable;
use OrderApi\Domain\Exception\InvalidInput;
use OrderApi\Domain\Exception\OrderStateConflict;
use OrderApi\Http\Exception\InvalidRequestPayload;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * The single place that decides what an exception means over HTTP.
 *
 * Every layer throws in its own vocabulary and none of them mentions status
 * codes; the translation happens once, here, which is why adding a rule to the
 * domain does not mean touching a controller.
 *
 *   invalid shape or value  422  the request will never work as sent
 *   unknown id              404
 *   wrong state / conflict  409  the same request could work at another time
 *   carrier down            503  ours to retry, with Retry-After
 *   carrier talking rubbish 502  their contract is broken, retrying will not help
 *   anything else           500  logged with an id, never explained to the client
 *
 * Responses use application/problem+json (RFC 7807) so that clients can parse a
 * failure the same way whatever went wrong.
 */
final readonly class JsonErrorHandler implements ErrorHandlerInterface
{
    private const RETRY_AFTER_SECONDS = 10;

    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $requestId = (string) ($request->getAttribute(RequestId::ATTRIBUTE) ?? '');

        [$status, $type, $body] = $this->describe($exception, $requestId, $logErrors);

        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/problem+json');

        if ($status === 503) {
            $response = $response->withHeader('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        $response->getBody()->write(json_encode(
            ['type' => $type, 'status' => $status] + $body + ['request_id' => $requestId],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $response;
    }

    /**
     * @return array{int, string, array<string, mixed>}
     */
    private function describe(Throwable $exception, string $requestId, bool $logErrors): array
    {
        return match (true) {
            $exception instanceof InvalidRequestPayload => [
                422,
                'invalid_request',
                ['title' => $exception->getMessage(), 'errors' => $exception->errors],
            ],
            $exception instanceof InvalidInput => [
                422,
                'invalid_request',
                ['title' => $exception->getMessage()],
            ],
            $exception instanceof ResourceNotFound, $exception instanceof HttpNotFoundException => [
                404,
                'not_found',
                ['title' => $this->titleOf($exception, 'The requested resource does not exist.')],
            ],
            $exception instanceof OrderStateConflict => [
                409,
                'order_state_conflict',
                ['title' => $exception->getMessage()],
            ],
            $exception instanceof DuplicateSku => [
                409,
                'duplicate_sku',
                ['title' => $exception->getMessage()],
            ],
            $exception instanceof HttpMethodNotAllowedException => [
                405,
                'method_not_allowed',
                ['title' => 'That method is not allowed on this resource.'],
            ],
            $exception instanceof ShippingProviderUnavailable => [
                503,
                'shipping_provider_unavailable',
                ['title' => 'The shipping carrier is unavailable. Try again shortly.'],
            ],
            $exception instanceof ShippingProviderInvalidResponse => [
                502,
                'shipping_provider_invalid_response',
                ['title' => 'The shipping carrier returned an unusable response.'],
            ],
            default => $this->unexpected($exception, $requestId, $logErrors),
        };
    }

    /**
     * The one case where the client is told nothing useful on purpose: the
     * details go to the log, and the id is what connects the two.
     *
     * @return array{int, string, array<string, mixed>}
     */
    private function unexpected(Throwable $exception, string $requestId, bool $logErrors): array
    {
        if ($logErrors) {
            error_log(sprintf(
                '[%s] %s: %s in %s:%d',
                $requestId,
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));
        }

        return [500, 'internal_error', ['title' => 'Something went wrong on our side.']];
    }

    private function titleOf(Throwable $exception, string $fallback): string
    {
        return $exception instanceof ResourceNotFound ? $exception->getMessage() : $fallback;
    }
}
