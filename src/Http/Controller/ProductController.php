<?php

declare(strict_types=1);

namespace OrderApi\Http\Controller;

use OrderApi\Application\Product\CreateProduct;
use OrderApi\Application\Product\GetProduct;
use OrderApi\Http\Input\Payload;
use OrderApi\Http\Presenter\Json;
use OrderApi\Http\Presenter\ProductPresenter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the request, calls one use case, renders the result. Anything longer
 * than that in a controller is a rule that escaped the domain.
 */
final readonly class ProductController
{
    public function __construct(
        private CreateProduct $createProduct,
        private GetProduct $getProduct,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = Payload::of($request);

        $sku = $payload->string('sku');
        $name = $payload->string('name');
        $priceInCents = $payload->integer('price_cents');
        $weightGrams = $payload->integer('weight_grams');

        $payload->assertValid();

        $product = $this->createProduct->execute($sku, $name, $priceInCents, $weightGrams);

        return Json::write($response, ProductPresenter::toArray($product), 201);
    }

    /**
     * @param array<string, string> $arguments
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments,
    ): ResponseInterface {
        $product = $this->getProduct->execute($arguments['id']);

        return Json::write($response, ProductPresenter::toArray($product));
    }
}
