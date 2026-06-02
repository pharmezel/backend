<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * Maps Product models to JSON API payloads.
 *
 * Adds resolved commission rate, effective price, and absolute image URLs for clients.
 */
class ProductApiTransform
{
    public static function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return self::ensureHttps($path);
        }

        $relative = '/storage/'.ltrim(str_replace('\\', '/', $path), '/');
        $base = rtrim((string) config('app.url'), '/');

        if ($base === '') {
            try {
                $base = rtrim(request()->getSchemeAndHttpHost(), '/');
            } catch (\Throwable) {
                return Storage::disk('public')->url($path);
            }
        }

        return self::ensureHttps($base.$relative);
    }

    private static function ensureHttps(string $url): string
    {
        if (str_starts_with($url, 'http://') && ! app()->environment('local')) {
            return 'https://'.substr($url, 7);
        }

        return $url;
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
