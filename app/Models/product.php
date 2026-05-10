<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'product_name',
        'description',
        'category_name',
        'unit_price',
        'expiry_date',
        'stock_quantity',
        'date_added',
        'commission_rate',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'date_added' => 'datetime',
        'unit_price' => 'decimal:2',
        'commission_rate' => 'decimal:2'
    ];

    // Product appears in many order details
    public function orderDetails()
    {
        return $this->hasMany(OrderDetail::class, 'product_id', 'product_id');
    }
}