<?php

namespace App\Produksi\Models;

use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderProgress extends Model
{
    use BelongsToCompany;

    protected $table = 'work_order_progress';

    protected $fillable = ['company_id', 'work_order_id', 'progress_percent', 'note', 'photo_paths', 'submitted_by', 'submitted_by_user_id', 'submitted_at'];

    protected function casts(): array
    {
        return ['photo_paths' => 'array', 'submitted_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
