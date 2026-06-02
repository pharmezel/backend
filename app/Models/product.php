<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Sellable drug/product in the master catalog.
 *
 * Superadmin maintains global stock and pricing; admins mirror products into admin_inventory.
 * Commission rate may override brand and global defaults.
 */
class Product extends Model
{
    use HasFactory;

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'product_name',
        'description',
        'image',
        'category_name',
        'brand_id',
        'category_id',
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

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'id');
    }

    public function adminInventories()
    {
        return $this->hasMany(AdminInventory::class, 'product_id', 'product_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }
}