<?php

declare(strict_types=1);

namespace OrderApi\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Gives every request an id, echoed back in the response.
 *
 * This is what makes a 500 actionable: the client is told an identifier, the
 * log line carries the same one, and nobody has to guess which of today's
 * requests the stack trace belongs to. An id supplied by the caller is honoured
 * so a trace can span more than this service.
 */
final class RequestId implements MiddlewareInterface
{
    public const ATTRIBUTE = 'request_id';
    public const HEADER = 'X-Request-Id';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine(self::HEADER);

        if ($id === '') {
            $id = Uuid::uuid7()->toString();
        }

        return $handler
            ->handle($request->withAttribute(self::ATTRIBUTE, $id))
            ->withHeader(self::HEADER, $id);
    }
}
