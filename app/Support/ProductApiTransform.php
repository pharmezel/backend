<?php

namespace App\Support;

use App\Models\Product;

/**
 * Maps Product models to JSON API payloads.
 *
 * Adds resolved commission rate, effective price, and absolute image URLs for clients.
 */
class ProductApiTransform
{
    public static function imageUrl(?string $path): ?string
    {
        return AssetUrl::fromStoragePath($path);
    }

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
            'image_url' => self::imageUrl($product->image),
        ]);
    }
}
