<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'event_key', 'event_label',
        'email_enabled', 'whatsapp_enabled', 'slack_enabled', 'body',
    ];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'slack_enabled' => 'boolean',
        ];
    }
}
