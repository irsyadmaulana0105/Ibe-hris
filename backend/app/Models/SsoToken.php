<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsoToken extends Model
{
    protected $fillable = [
        'user_id',
        'target_app',
        'token_hash',
        'expires_at',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at?->isFuture();
    }
}
