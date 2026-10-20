<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectBudgetVersion extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_budget_id', 'version', 'subtotal', 'grand_total', 'snapshot', 'change_reason', 'approved_by', 'approved_at'];
    protected function casts(): array { return ['subtotal' => 'decimal:2', 'grand_total' => 'decimal:2', 'snapshot' => 'array', 'approved_at' => 'datetime']; }
    public function budget(): BelongsTo { return $this->belongsTo(ProjectBudget::class, 'project_budget_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
