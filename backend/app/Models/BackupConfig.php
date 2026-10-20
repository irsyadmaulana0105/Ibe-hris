<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupConfig extends Model
{
    protected $fillable = [
        'company_id', 'schedule_enabled', 'schedule_time',
        'retention_daily', 'retention_weekly', 'retention_monthly', 'retention_yearly',
        'destination',
    ];

    protected function casts(): array
    {
        return ['schedule_enabled' => 'boolean'];
    }
}
