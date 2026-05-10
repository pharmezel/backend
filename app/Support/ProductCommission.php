<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Product;

class ProductCommission
{
    public const GLOBAL_COMMISSION_KEY = 'global_commission_rate';

    public static function globalRate(): float
    {
        $raw = AppSetting::getValue(self::GLOBAL_COMMISSION_KEY, '0');

        return round((float) $raw, 2);
    }

    /**
     * Rate used for shop pricing: product value if set (including 0), else brand override, else global.
     */
    public static function resolvedRate(Product $product): float
    {
        if ($product->commission_rate !== null) {
            return round((float) $product->commission_rate, 2);
        }

        $brand = $product->relationLoaded('brand') ? $product->brand : $product->brand;
        if ($brand && $brand->commission_rate !== null) {
            return round((float) $brand->commission_rate, 2);
        }

        return self::globalRate();
    }

    public static function effectivePrice(Product $product): string
    {
        $rate = self::resolvedRate($product);
        $unit = (float) $product->unit_price;
        $effective = $unit * (1 + $rate / 100);

        return number_format($effective, 2, '.', '');
    }
}
