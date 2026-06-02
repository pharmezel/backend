<?php

namespace App\Support;

use App\Models\Commission;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralLink;

/**
 * Creates level-1 (direct) referral commissions for fulfilled orders.
 *
 * No multi-level tree walks: only the buyer's single referrer in referral_links earns.
 * Superadmin earns commission only when they are that direct referrer.
 */
class DirectReferralCommission
{
    /**
     * The single direct referrer for a buyer (referral_links.referred_id = buyer).
     */
    public static function linkForBuyer(int $buyerId): ?ReferralLink
    {
        return ReferralLink::with('referrer')
            ->where('referred_id', $buyerId)
            ->first();
    }

    /**
     * Create one commission for the buyer's direct referrer only.
     * Returns null when there is no referrer or commission amount is zero.
     * Superadmin earns only when they are that direct referrer (same as any role).
     */
    public static function createForOrder(Order $order, int $buyerId, array $lineItems, array $productsById): ?Commission
    {
        $referral = self::linkForBuyer($buyerId);

        if (! $referral || ! $referral->referrer) {
            return null;
        }

        $commissionTotal = 0.0;

        foreach ($lineItems as $line) {
            $product = $productsById[$line['product_id']] ?? null;
            if (! $product instanceof Product) {
                continue;
            }
            $rate = ProductCommission::resolveRate($product);
            $commissionTotal += ((float) $line['subtotal']) * $rate / 100.0;
        }

        $commissionTotal = round($commissionTotal, 2);

        if ($commissionTotal <= 0) {
            return null;
        }

        return Commission::create([
            'source' => Commission::SOURCE_ORDER_REFERRAL,
            'referral_id' => $referral->id,
            'order_id' => $order->order_id,
            'commission_earned' => number_format($commissionTotal, 2, '.', ''),
            'date_earned' => now()->toDateString(),
            'status' => 'pending',
        ]);
    }
}
