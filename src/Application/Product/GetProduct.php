<?php

declare(strict_types=1);

namespace OrderApi\Application\Product;

use OrderApi\Application\Exception\ResourceNotFound;
use OrderApi\Application\Port\ProductRepository;
use OrderApi\Domain\Product\Product;

final readonly class GetProduct
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function execute(string $id): Product
    {
        return $this->products->findById($id) ?? throw ResourceNotFound::product($id);
    }
}
