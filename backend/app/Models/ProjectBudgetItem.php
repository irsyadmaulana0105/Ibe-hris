<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectBudgetItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_budget_id', 'parent_id', 'code', 'category', 'description', 'unit', 'volume', 'unit_price', 'planned_start', 'planned_end', 'sort_order', 'notes'];
    protected $appends = ['amount'];

    protected function casts(): array
    {
        return ['volume' => 'decimal:4', 'unit_price' => 'decimal:2', 'planned_start' => 'date', 'planned_end' => 'date'];
    }

    public function getAmountAttribute(): string { return number_format((float) $this->volume * (float) $this->unit_price, 2, '.', ''); }
    public function budget(): BelongsTo { return $this->belongsTo(ProjectBudget::class, 'project_budget_id'); }
    public function progressEntries(): HasMany { return $this->hasMany(ProjectProgressEntry::class, 'budget_item_id')->orderBy('entry_date'); }
}
