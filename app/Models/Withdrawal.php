<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Points-to-cash withdrawal request.
 *
 * Created by admins or buyers; superadmin processes through pending → approved → completed.
 * Completing a withdrawal deducts referrer points and records a superadmin receipt commission.
 */
class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id',
        'points_requested',
        'status',
        'processed_by',
    ];

    protected $casts = [
        'points_requested' => 'integer',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id', 'user_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by', 'user_id');
    }
}
