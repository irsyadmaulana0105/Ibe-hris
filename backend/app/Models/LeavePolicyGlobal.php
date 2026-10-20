<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicyGlobal extends Model
{
    protected $fillable = [
        'company_id', 'carry_over_enabled', 'max_carry',
        'auto_approve_enabled', 'auto_approve_days',
    ];

    protected function casts(): array
    {
        return [
            'carry_over_enabled' => 'boolean',
            'auto_approve_enabled' => 'boolean',
        ];
    }
}
