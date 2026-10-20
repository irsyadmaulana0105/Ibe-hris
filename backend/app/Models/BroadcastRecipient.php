<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastRecipient extends Model
{
    use HasUlids;

    protected $fillable = [
        'broadcast_id',
        'user_id',
        'email_sent_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'email_sent_at' => 'datetime',
            'read_at'       => 'datetime',
        ];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
