<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class QualityInspection extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'inspection_number', 'work_order_id', 'product_serial', 'product_id', 'batch_id',
        'inspection_type', 'inspection_date', 'inspector_user_id', 'verified_by_user_id',
        'status', 'checkpoints', 'total_checks', 'passed_checks', 'failed_checks',
        'defects_found', 'corrective_action', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date',
            'checkpoints'     => 'array',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function getPassRateAttribute(): float
    {
        if ($this->total_checks <= 0) return 0;
        return round(($this->passed_checks / $this->total_checks) * 100, 1);
    }
}
