<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Authenticated user account (buyer, admin, or superadmin).
 *
 * Primary entity for orders, referrals, commissions, withdrawals, and points balance.
 * Supports Sanctum API tokens; referral codes are unique 8-character alphanumeric strings.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Primary key for PostgreSQL
     */
    protected $primaryKey = 'user_id';

    /**
     * Fields that can be mass assigned
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'date_of_birth',
        'email',
        'password',
        'contact_number',
        'profile_image',
        'shipping_address',
        'referral_code',
        'referrer_code_used',
        'role',
        'points',
        'date_registered'
    ];

    /**
     * Hidden fields when returning API/JSON response
     */
    protected $hidden = [
        'password',
        'remember_token'
    ];

    /**
     * Auto-casting for data types
     */
    protected $casts = [
        'date_of_birth' => 'date',
        'date_registered' => 'datetime',
        'password' => 'hashed',
        'points' => 'integer',
    ];

        // A user can place many orders
    public function orders()
    {
        return $this->hasMany(Order::class, 'buyer_id', 'user_id');
    }

    //  A user can refer many people
    public function referralsMade()
    {
        return $this->hasMany(ReferralLink::class, 'referrer_id', 'user_id');
    }

    // A user can be referred once
    public function referralUsed()
    {
        return $this->hasOne(ReferralLink::class, 'referred_id', 'user_id');
    }

    /** The user who referred this account (via referral_links). */
    public function referrer()
    {
        return $this->hasOneThrough(
            User::class,
            ReferralLink::class,
            'referred_id',
            'user_id',
            'user_id',
            'referrer_id'
        );
    }

    /** Direct downlines (users who signed up with this user's referral code). */
    public function referrals()
    {
        return $this->hasManyThrough(
            User::class,
            ReferralLink::class,
            'referrer_id',
            'user_id',
            'user_id',
            'referred_id'
        );
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class, 'requester_id', 'user_id');
    }

    /**
     * Resolve a referrer by their referral code (case-insensitive, trimmed).
     */
    public static function findByReferralCode(string $code): ?self
    {
        $normalized = strtoupper(trim($code));

        return static::query()
            ->whereNotNull('referral_code')
            ->whereRaw('UPPER(TRIM(referral_code)) = ?', [$normalized])
            ->first();
    }

    /**
     * Unique 8-character uppercase alphanumeric referral code.
     */
    public static function generateUniqueReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }
}