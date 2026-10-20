<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RbacDelegation extends Model
{
    use HasUlids;

    protected $fillable = [
        'company_id', 'delegator_id', 'delegate_id', 'role_id', 'capability_key',
        'approval_limit', 'valid_from', 'valid_until', 'status', 'reason', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'approval_limit' => 'decimal:2',
        ];
    }
}
