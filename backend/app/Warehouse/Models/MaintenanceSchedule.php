<?php

namespace App\Warehouse\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceSchedule extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'fixed_asset_id', 'task_code', 'title', 'description',
        'frequency_type', 'frequency_value', 'next_due_date', 'last_done_date',
        'lead_alert_days', 'assigned_dept', 'assigned_user_id', 'estimated_cost',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'next_due_date' => 'date',
            'last_done_date' => 'date',
            'estimated_cost' => 'decimal:2',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class, 'schedule_id')->orderByDesc('performed_date');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'active' && $this->next_due_date && now()->gt($this->next_due_date);
    }

    public function getIsDueSoonAttribute(): bool
    {
        if ($this->status !== 'active' || ! $this->next_due_date) {
            return false;
        }
        $days = now()->diffInDays($this->next_due_date, false);

        return $days >= 0 && $days <= $this->lead_alert_days;
    }
}
