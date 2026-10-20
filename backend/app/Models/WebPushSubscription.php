<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebPushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint_hash', 'endpoint', 'p256dh', 'auth',
        'user_agent', 'last_active_at', 'last_pushed_at', 'push_failures',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
        'last_pushed_at' => 'datetime',
        'push_failures'  => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
