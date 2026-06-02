<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-submitted feedback or support ticket.
 *
 * Created by buyers or admins; superadmin may reply and change status (e.g. open/closed).
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    protected $fillable = [
        'user_id',
        'subject',
        'message',
        'category',
        'admin_reply',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
