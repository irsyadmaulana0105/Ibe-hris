<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectProgressEntry extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'budget_item_id', 'entry_date', 'planned_percent', 'actual_percent', 'actual_cost', 'notes', 'evidence_url', 'created_by'];

    protected $hidden = ['evidence_url'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'planned_percent' => 'decimal:4', 'actual_percent' => 'decimal:4', 'actual_cost' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProjectBudgetItem::class, 'budget_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
