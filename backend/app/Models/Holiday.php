<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = ['date', 'name', 'type', 'is_recurring', 'company_id', 'broadcast_sent_at'];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
        'broadcast_sent_at' => 'datetime',
    ];
}
