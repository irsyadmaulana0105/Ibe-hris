<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RbacAccessDecision extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'actor_id', 'capability_key', 'resource_type', 'resource_id',
        'mode', 'legacy_allowed', 'target_allowed', 'effective_allowed',
        'reason_code', 'context', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'legacy_allowed' => 'boolean',
            'target_allowed' => 'boolean',
            'effective_allowed' => 'boolean',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
