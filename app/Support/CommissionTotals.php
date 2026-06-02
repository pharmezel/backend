<?php

namespace App\Support;

use App\Models\Commission;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Commission aggregation, filtering, and points-balance helpers.
 *
 * Centralizes rules for earned vs pending commissions, direct-referral-only scopes,
 * superadmin withdrawal receipts, and withdrawable points calculations used by
 * dashboards, commission lists, and withdrawal flows.
 */
class CommissionTotals
{
    /** Order statuses that count commission as earned (complete). */
    public const COMPLETE_ORDER_STATUSES = ['delivered', 'fulfilled'];

    public static function commissionsBase(): Builder
    {
        return Commission::query()->where('commissions.status', '!=', 'cancelled');
    }

    /** Direct-referral order commissions only (excludes superadmin withdrawal receipts). */
    public static function orderReferralBase(): Builder
    {
        return self::commissionsBase()
            ->where(function ($q) {
                $q->whereNull('source')
                    ->orWhere('source', Commission::SOURCE_ORDER_REFERRAL);
            })
            ->whereHas('referral');
    }

    /** Cash received by superadmin when paying out a withdrawal (not redeemable points). */
    public static function withdrawalReceiptBase(): Builder
    {
        return self::commissionsBase()
            ->where('source', Commission::SOURCE_WITHDRAWAL_RECEIPT)
            ->where('status', 'released');
    }

    /**
     * Commissions earned as direct referrer only (referral_links.referrer_id = user).
     * Each commission row is already level-1 by creation rules in DirectReferralCommission.
     */
    public static function forReferrer(Builder $query, int $referrerId): Builder
    {
        return $query->whereHas('referral', function ($q) use ($referrerId) {
            $q->where('referrer_id', $referrerId);
        });
    }

    /**
     * Superadmin commission list: direct-referral order commissions + withdrawal payout receipts.
     */
    public static function forSuperadmin(Builder $query, int $superadminUserId): Builder
    {
        return $query->where(function ($q) use ($superadminUserId) {
            $q->where(function ($referralQ) use ($superadminUserId) {
                self::forReferrer($referralQ, $superadminUserId);
                $referralQ->where(function ($sourceQ) {
                    $sourceQ->whereNull('source')
                        ->orWhere('source', Commission::SOURCE_ORDER_REFERRAL);
                });
            })->orWhere('source', Commission::SOURCE_WITHDRAWAL_RECEIPT);
        });
    }

    /**
     * Pending = linked order exists and is not complete/cancelled.
     * Rows without order_id (e.g. withdrawal_receipt) are excluded via whereHas('order').
     */
    public static function pendingByOrder(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereHas('order', function ($oq) {
                $oq->whereNotIn('order_status', array_merge(self::COMPLETE_ORDER_STATUSES, ['cancelled']));
            });
        });
    }

    public static function earnedByOrder(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where(function ($orderQ) {
                $orderQ->whereHas('order', function ($oq) {
                    $oq->whereIn('order_status', self::COMPLETE_ORDER_STATUSES);
                });
            })->orWhere(function ($receiptQ) {
                $receiptQ->where('source', Commission::SOURCE_WITHDRAWAL_RECEIPT)
                    ->where('status', 'released');
            });
        });
    }

    public static function sumPending(Builder $query): float
    {
        return (float) self::pendingByOrder(clone $query)->sum('commission_earned');
    }

    public static function sumEarned(Builder $query): float
    {
        return (float) self::earnedByOrder(clone $query)->sum('commission_earned');
    }

    /** Sum of completed withdrawals only — balance is reduced when payout is completed, not on approve. */
    public static function sumWithdrawnForUser(int $userId): float
    {
        return (float) Withdrawal::query()
            ->where('requester_id', $userId)
            ->where('status', 'completed')
            ->sum('points_requested');
    }

    public static function sumWithdrawnPlatform(): float
    {
        return (float) Withdrawal::query()
            ->where('status', 'completed')
            ->sum('points_requested');
    }

    /** Pending + approved requests that reserve balance until completed. */
    public static function sumReservedWithdrawalsForUser(int $userId): float
    {
        return (float) Withdrawal::query()
            ->where('requester_id', $userId)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('points_requested');
    }

    /**
     * Earned commission total minus completed withdrawals only (computed; not users.points).
     */
    public static function pointsBalanceForUser(int $referrerId): float
    {
        $base = self::forReferrer(self::orderReferralBase(), $referrerId);
        $earned = self::sumEarned($base);
        $withdrawn = self::sumWithdrawnForUser($referrerId);

        return max(0, round($earned - $withdrawn, 2));
    }

    /**
     * Balance available for a new withdrawal request (excludes pending/approved holds).
     */
    public static function availableWithdrawalForUser(int $referrerId): float
    {
        $reserved = self::sumReservedWithdrawalsForUser($referrerId);

        return max(0, round(self::pointsBalanceForUser($referrerId) - $reserved, 2));
    }

    /**
     * @return array{total_pending: float, total_earned: float, total_withdrawn: float, points_balance: float}
     */
    public static function personalSummary(int $referrerId): array
    {
        $base = self::forReferrer(self::orderReferralBase(), $referrerId);
        $pending = self::sumPending($base);
        $earned = self::sumEarned($base);
        $withdrawn = self::sumWithdrawnForUser($referrerId);

        return [
            'total_pending' => round($pending, 2),
            'total_earned' => round($earned, 2),
            'total_withdrawn' => round($withdrawn, 2),
            'points_balance' => self::pointsBalanceForUser($referrerId),
        ];
    }

    /**
     * Platform-wide totals (all referrers, all withdrawal payouts).
     *
     * @return array{total_pending: float, total_earned: float, total_withdrawn: float}
     */
    public static function platformSummary(): array
    {
        $orderBase = self::orderReferralBase();
        $pending = self::sumPending($orderBase);
        $orderEarned = self::sumEarned($orderBase);
        $receiptEarned = (float) self::withdrawalReceiptBase()->sum('commission_earned');
        $withdrawn = self::sumWithdrawnPlatform();

        return [
            'total_pending' => round($pending, 2),
            'total_earned' => round($orderEarned + $receiptEarned, 2),
            'total_withdrawn' => round($withdrawn, 2),
        ];
    }

    /**
     * Superadmin commission summary: referral income vs cash withdrawal receipts.
     *
     * @return array{
     *   total_pending: float,
     *   referral_earned: float,
     *   withdrawal_receipts: float,
     *   total_earned: float,
     *   withdrawn: float,
     *   points_balance: float
     * }
     */
    public static function superadminSummary(int $superadminUserId): array
    {
        $referralBase = self::forReferrer(self::orderReferralBase(), $superadminUserId);
        $referralPending = self::sumPending($referralBase);
        $referralEarned = self::sumEarned($referralBase);
        $withdrawalReceipts = (float) self::withdrawalReceiptBase()->sum('commission_earned');
        $withdrawn = self::sumWithdrawnForUser($superadminUserId);

        return [
            'total_pending' => round($referralPending, 2),
            'referral_earned' => round($referralEarned, 2),
            'withdrawal_receipts' => round($withdrawalReceipts, 2),
            'total_earned' => round($referralEarned + $withdrawalReceipts, 2),
            'withdrawn' => round($withdrawn, 2),
            'points_balance' => max(0, round($referralEarned - $withdrawn, 2)),
        ];
    }

    public static function earningStatusFromOrder(?string $orderStatus, string $commissionStatus, ?string $source = null): string
    {
        if ($commissionStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($source === Commission::SOURCE_WITHDRAWAL_RECEIPT && $commissionStatus === 'released') {
            return 'earned';
        }

        if ($orderStatus && in_array($orderStatus, self::COMPLETE_ORDER_STATUSES, true)) {
            return 'earned';
        }

        return 'pending';
    }

    public static function applyListStatusFilter(Builder $query, string $status): Builder
    {
        if ($status === 'pending') {
            return self::pendingByOrder($query);
        }

        if ($status === 'earned') {
            return self::earnedByOrder($query);
        }

        if ($status === 'cancelled') {
            return $query->where('commissions.status', 'cancelled');
        }

        return $query;
    }
}
