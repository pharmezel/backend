<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Referral commission ledger entry.
 *
 * Tracks earnings from direct-referral orders (`order_referral`) or superadmin cash receipts
 * when paying out withdrawals (`withdrawal_receipt`). Linked to referral link, order, recipient,
 * and optional withdrawal.
 */
class Commission extends Model
{
    use HasFactory;

    protected $primaryKey = 'commission_id';

    public const SOURCE_ORDER_REFERRAL = 'order_referral';

    public const SOURCE_WITHDRAWAL_RECEIPT = 'withdrawal_receipt';

    protected $fillable = [
        'source',
        'referral_id',
        'order_id',
        'recipient_user_id',
        'withdrawal_id',
        'commission_earned',
        'date_earned',
        'status',
    ];

    protected $casts = [
        'commission_earned' => 'decimal:2',
        'date_earned' => 'datetime',
    ];

    // Commission comes from a referral
    public function referral()
    {
        return $this->belongsTo(ReferralLink::class, 'referral_id', 'id');
    }

    // Commission is tied to an order
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'order_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id', 'user_id');
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class, 'withdrawal_id', 'id');
    }
}