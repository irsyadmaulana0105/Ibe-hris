<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RbacUserAssignment extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'role_id', 'company_id', 'status', 'valid_from', 'valid_until',
        'approval_limit', 'granted_by', 'reason', 'source',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'approval_limit' => 'decimal:2',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(RbacRole::class, 'role_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(RbacAssignmentScope::class, 'assignment_id');
    }
}
