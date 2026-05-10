<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReferralLink extends Model
{
    use HasFactory;

    /**
     * Table name (optional but clear for beginners)
     */
    protected $table = 'referral_links';

    /**
     * Fields that can be mass assigned
     */
    protected $fillable = [
        'referrer_id',   // user who owns the referral code
        'referred_id',   // user who used the referral code
        'status'
    ];

    /**
     * Cast data types properly
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /*
    |---------------------------------------
    | RELATIONSHIPS
    |---------------------------------------
    */

    // Owner of the referral code
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id', 'user_id');
    }

    // User who used the referral
    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id', 'user_id');
    }

    // Referral can generate commissions
    public function commissions()
    {
        return $this->hasMany(Commission::class, 'referral_id', 'id');
    }

    // Many user can use one referral code
    public function usages()
    {
        return $this->hasMany(ReferralUsage::class, 'referral_link_id', 'id');
    }
}