<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'leave_type',
        'start_date',
        'end_date',
        'days_count',
        'reason',
        'attachment',
        'status',
        'notes',
        'approved_by',
        'approved_at',
    ];

    protected $hidden = ['attachment'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public static array $leaveTypes = [
        'annual' => 'Cuti Tahunan',
        'sick' => 'Sakit',
        'personal' => 'Keperluan Pribadi',
        'medical_checkup' => 'Cek Kesehatan',
        'other' => 'Lainnya',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
