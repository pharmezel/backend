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

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $base = self::publicBaseUrl();
        if ($base === '') {
            return Storage::disk('public')->url($relative);
        }

        return $base.'/storage/'.$relative;
    }

    private static function publicBaseUrl(): string
    {
        $configured = rtrim((string) config('app.url'), '/');
        if ($configured !== '' && ! self::isLocalHost($configured)) {
            return self::ensureHttps($configured);
        }

        try {
            return self::ensureHttps(rtrim(request()->getSchemeAndHttpHost(), '/'));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isLocalHost(string $url): bool
    {
        return (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?(/|$)#i', $url);
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
