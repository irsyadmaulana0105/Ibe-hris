<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvance extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'date',
        'amount', 'purpose', 'status',
        'notes', 'approved_by', 'approved_at',
        'journal_id',
        'hr_approved_by', 'hr_approved_at', 'hr_notes',
        'settled_by', 'settled_at', 'payment_reference',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function hrApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_approved_by');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
