<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AdminInventory extends Model
{
    use HasFactory;

    protected $table = 'admin_inventory';

    protected $fillable = [
        'admin_id',
        'product_id',
        'stock_quantity',
        'is_active',
    ];

    protected $casts = [
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id', 'user_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'product_id');
    }
}
