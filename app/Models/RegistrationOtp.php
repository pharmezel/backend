<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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