<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buyer-to-admin role upgrade request.
 *
 * Buyers submit requests; an admin or superadmin approver accepts or rejects, promoting
 * the requester to admin on approval.
 */
class AdminUpgradeRequest extends Model
{
    protected $fillable = [
        'requester_id',
        'approver_id',
        'status',
        'requester_note',
        'approver_note',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id', 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id', 'user_id');
    }
}
