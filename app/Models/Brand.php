<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Product brand with optional default commission rate.
 *
 * Used as fallback when a product has no per-SKU commission override.
 */
class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'contact_number',
        'email',
        'address',
        'commission_rate',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'brand_id', 'id');
    }
}
