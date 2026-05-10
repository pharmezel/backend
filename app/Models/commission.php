<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Commission extends Model
{
    use HasFactory;

    protected $primaryKey = 'commission_id';

    protected $fillable = [
        'referral_id',
        'order_id',
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
}