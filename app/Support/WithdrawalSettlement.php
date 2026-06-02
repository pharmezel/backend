<?php

namespace App\Support;

use App\Models\Commission;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Side effects when a withdrawal is completed.
 *
 * Records an idempotent superadmin cash-receipt commission and resolves the platform
 * superadmin account used for settlement logging.
 */
class WithdrawalSettlement
{
    public const SOURCE_WITHDRAWAL_RECEIPT = 'withdrawal_receipt';

    public static function findSuperadmin(): ?User
    {
        return User::query()
            ->where('role', 'superadmin')
            ->orderBy('user_id')
            ->first();
    }

    /**
     * Idempotent: one withdrawal_receipt commission per withdrawal (cash received by superadmin).
     */
    public static function recordSuperadminReceipt(Withdrawal $withdrawal): Commission
    {
        $existing = Commission::query()
            ->where('withdrawal_id', $withdrawal->id)
            ->where('source', Commission::SOURCE_WITHDRAWAL_RECEIPT)
            ->first();

        if ($existing) {
            Log::info('withdrawal.settlement.receipt_exists', [
                'withdrawal_id' => $withdrawal->id,
                'commission_id' => $existing->commission_id,
                'recipient_user_id' => $existing->recipient_user_id,
            ]);

            return $existing;
        }

        $superadmin = self::findSuperadmin();
        if (! $superadmin) {
            Log::error('withdrawal.settlement.no_superadmin', [
                'withdrawal_id' => $withdrawal->id,
            ]);
            throw new RuntimeException('No superadmin user found.');
        }

        $amount = (float) $withdrawal->points_requested;

        $commission = Commission::create([
            'source' => Commission::SOURCE_WITHDRAWAL_RECEIPT,
            'referral_id' => null,
            'order_id' => null,
            'recipient_user_id' => $superadmin->user_id,
            'withdrawal_id' => $withdrawal->id,
            'commission_earned' => number_format($amount, 2, '.', ''),
            'date_earned' => now()->toDateString(),
            'status' => 'released',
        ]);

        Log::info('withdrawal.settlement.receipt_created', [
            'withdrawal_id' => $withdrawal->id,
            'commission_id' => $commission->commission_id,
            'recipient_user_id' => $commission->recipient_user_id,
            'commission_earned' => $commission->commission_earned,
            'source' => $commission->source,
            'status' => $commission->status,
        ]);

        return $commission;
    }
}
