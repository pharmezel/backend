<?php

namespace App\Support;

use App\Models\Product;

class ProductApiTransform
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Product $product): array
    {
        $base = $product->toArray();
        $rate = ProductCommission::resolveRate($product);
        $effective = ProductCommission::effectivePrice($product);

        return array_merge($base, [
            'product_commission_rate' => $product->commission_rate,
            'commission_rate' => $rate,
            'effective_price' => $effective,
        ]);
    }
}
