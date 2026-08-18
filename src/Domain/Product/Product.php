<?php

declare(strict_types=1);

namespace OrderApi\Domain\Product;

use OrderApi\Domain\Exception\InvalidProduct;
use OrderApi\Domain\Shared\Money;

/**
 * Something that can be bought and shipped.
 *
 * Weight is mandatory rather than optional: this catalogue only holds physical
 * goods, and a product with no weight cannot be quoted for shipping, so an
 * unknown weight is a broken product rather than a missing detail.
 *
 * Identifiers are plain strings. See the trade-offs section of the README for
 * why they are not wrapped in value objects.
 */
final readonly class Product
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
        public Money $price,
        public int $weightGrams,
    ) {
        if (trim($id) === '') {
            throw InvalidProduct::blankField('id');
        }

        if (trim($sku) === '') {
            throw InvalidProduct::blankField('sku');
        }

        if (trim($name) === '') {
            throw InvalidProduct::blankField('name');
        }

        if ($weightGrams < 1) {
            throw InvalidProduct::notShippable($weightGrams);
        }
    }
}
