<?php

declare(strict_types=1);

namespace OrderApi\Http\Presenter;

use OrderApi\Domain\Product\Product;

/**
 * Money crosses the wire as an integer plus a currency, never as 249.9.
 *
 * A JSON number is a float in most clients, which is the same rounding problem
 * the domain avoids internally; leaving the formatting to the consumer keeps the
 * value exact.
 */
final class ProductPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'price' => [
                'amount_cents' => $product->price->cents,
                'currency' => $product->price->currency,
            ],
            'weight_grams' => $product->weightGrams,
        ];
    }
}
