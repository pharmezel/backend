<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Temporary OTP and registration payload for email verification.
 *
 * Holds hashed form data between sendOtp and verifyOtp during signup, or a reset code
 * for password recovery.
 */
class RegistrationOtp extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'code',
        'form_data',
        'expires_at'
    ];

    protected $casts = [
        'form_data' => 'array',
        'expires_at' => 'datetime'
    ];
}