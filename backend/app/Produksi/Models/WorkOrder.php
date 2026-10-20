<?php

namespace App\Produksi\Models;

use App\Models\Project;
use App\Models\User;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use BelongsToCompany;

    protected $table = 'vendor_work_orders';

    protected $fillable = ['company_id', 'vendor_id', 'project_id', 'ref_no', 'title', 'description', 'budget', 'scheduled_start', 'scheduled_end', 'actual_start', 'actual_end', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['budget' => 'decimal:2', 'scheduled_start' => 'date', 'scheduled_end' => 'date', 'actual_start' => 'date', 'actual_end' => 'date'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(WorkOrderProgress::class)->orderBy('submitted_at');
    }
}
