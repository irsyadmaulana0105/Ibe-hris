<?php

namespace App\Warehouse\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceLog extends Model
{
    protected $fillable = [
        'schedule_id', 'company_id', 'fixed_asset_id',
        'performed_date', 'actual_cost', 'parts_used', 'notes', 'issues_found',
        'result', 'performed_by', 'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'performed_date' => 'date',
            'actual_cost' => 'decimal:2',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'schedule_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
