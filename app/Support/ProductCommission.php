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
     * Commission % for pricing: product override, else brand, else global app setting.
     */
    public static function resolveRate(Product $product): float
    {
        $product->loadMissing('brand');

        if ($product->commission_rate !== null) {
            return round((float) $product->commission_rate, 2);
        }

        $brand = $product->brand;
        if ($brand && $brand->commission_rate !== null) {
            return round((float) $brand->commission_rate, 2);
        }

        return self::globalRate();
    }

    /** @deprecated Use resolveRate() */
    public static function resolvedRate(Product $product): float
    {
        return self::resolveRate($product);
    }

    /**
     * Shop price including commission markup: unit_price × (1 + rate/100).
     */
    public static function effectivePrice(Product $product): float
    {
        $rate = self::resolveRate($product);
        $unit = (float) $product->unit_price;

        return round($unit * (1 + $rate / 100), 2);
    }
}
