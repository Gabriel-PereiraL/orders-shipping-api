<?php

declare(strict_types=1);

namespace OrderApi\Http\Presenter;

use Psr\Http\Message\ResponseInterface;

final class Json
{
    /**
     * @param array<string, mixed> $data
     */
    public static function write(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
