<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_event_id', 'webhook_endpoint_id', 'status', 'attempts', 'response_status', 'response_body', 'last_error', 'next_attempt_at', 'delivered_at'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
