<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

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
        'referral_code',
        'referrer_code_used',
        'role',
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
        'password' => 'hashed'
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
}