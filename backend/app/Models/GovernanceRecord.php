<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernanceRecord extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'type', 'reference', 'title', 'description', 'status',
        'severity', 'likelihood', 'impact', 'owner_id', 'due_date', 'details',
        'evidence', 'closed_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'likelihood' => 'integer', 'impact' => 'integer', 'due_date' => 'date',
            'details' => 'array', 'evidence' => 'array', 'closed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
