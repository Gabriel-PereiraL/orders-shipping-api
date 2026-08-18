<?php

declare(strict_types=1);

namespace OrderApi\Http\Controller;

use OrderApi\Http\Presenter\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HealthController
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::write($response, ['status' => 'ok']);
    }
}
