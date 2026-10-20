<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    protected $fillable = ['company_id', 'name', 'url', 'secret', 'events', 'headers', 'active', 'last_delivered_at', 'last_failed_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'headers' => 'array',
            'active' => 'boolean',
            'last_delivered_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
