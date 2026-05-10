<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'total_amount' => 'decimal:2',
        'points_used' => 'integer',
        'cod_amount' => 'decimal:2',
    ];

    // order belongs to a user 
    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }

    // Order has many items
    public function details()
    {
        return $this->hasMany(OrderDetail::class, 'order_id', 'order_id');
    }

    // Order may generate commissions
    public function commissions()
    {
        return $this->hasMany(Commission::class, 'order_id', 'order_id');
    }
}