<?php

declare(strict_types=1);

namespace OrderApi\Tests\Integration\Http;

use OrderApi\Tests\Integration\DatabaseTestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Drives the real application: the same container, routes, middleware, error
 * handler and database the server uses, with the HTTP request built in memory
 * instead of arriving over a socket.
 *
 * There is no mocking here on purpose. These tests exist to prove the wiring,
 * and wiring is exactly what a mock would replace.
 */
abstract class ApiTestCase extends DatabaseTestCase
{
    /** @var App<ContainerInterface>|null */
    private ?App $app = null;

    /**
     * @return App<ContainerInterface>
     */
    protected function app(): App
    {
        if ($this->app instanceof App) {
            return $this->app;
        }

        /** @var array{db: array{host: string, port: int, name: string, user: string, password: string}, currency: string, shipping: array{provider: string, quote_ttl_seconds: int}} $settings */
        $settings = require __DIR__ . '/../../../config/settings.php';
        $settings['db']['name'] = getenv('DB_TEST_NAME') ?: 'orders_test';

        /** @var callable(array<string, mixed>): ContainerInterface $buildContainer */
        $buildContainer = require __DIR__ . '/../../../config/container.php';

        /** @var callable(ContainerInterface): App<ContainerInterface> $buildApp */
        $buildApp = require __DIR__ . '/../../../config/app.php';

        return $this->app = $buildApp($buildContainer($settings));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function request(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app()->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createProduct(array $overrides = []): string
    {
        $response = $this->request('POST', '/products', $overrides + [
            'sku' => 'KB-01',
            'name' => 'Mechanical keyboard',
            'price_cents' => 24990,
            'weight_grams' => 900,
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $this->json($response)['id'];
    }

    /**
     * @param list<array{product_id: string, quantity: int}> $items
     */
    protected function createOrder(string $zipCode, array $items): string
    {
        $response = $this->request('POST', '/orders', [
            'destination_zip_code' => $zipCode,
            'items' => $items,
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (string) $this->json($response)['id'];
    }
}
