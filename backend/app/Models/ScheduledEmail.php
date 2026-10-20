<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ScheduledEmail extends Model
{
    protected $fillable = [
        'user_id', 'mailbox_username', 'scheduled_at', 'payload_encrypted',
        'status', 'attempts', 'last_error', 'processed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts'     => 'integer',
    ];

    protected $hidden = ['payload_encrypted'];

    public function setPayloadAttribute(array $data): void
    {
        $this->attributes['payload_encrypted'] = Crypt::encryptString(json_encode($data));
    }

    public function getPayloadAttribute(): array
    {
        try {
            return json_decode(Crypt::decryptString($this->payload_encrypted), true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
