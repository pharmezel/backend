<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Customer purchase order.
 */
class Order extends Model
{
    use HasFactory;

    protected $primaryKey = 'order_id';

    protected $fillable = [
        'buyer_id',
        'order_date',
        'total_amount',
        'payment_action',
        'order_status',
        'shipping_address',
        'points_used',
        'cod_amount',
        'issue_description',
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'total_amount' => 'decimal:2',
        'points_used' => 'integer',
        'cod_amount' => 'decimal:2',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }

    public function details()
    {
        return $this->hasMany(OrderDetail::class, 'order_id', 'order_id');
    }

    public function commissions()
    {
        return $this->hasMany(Commission::class, 'order_id', 'order_id');
    }
}
