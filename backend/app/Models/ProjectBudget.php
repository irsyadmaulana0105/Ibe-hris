<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectBudget extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_id', 'currency', 'tax_percent', 'contingency_percent', 'status', 'current_version', 'notes', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['tax_percent' => 'decimal:4', 'contingency_percent' => 'decimal:4', 'approved_at' => 'datetime'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function items(): HasMany { return $this->hasMany(ProjectBudgetItem::class)->orderBy('sort_order')->orderBy('id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function versions(): HasMany { return $this->hasMany(ProjectBudgetVersion::class)->orderByDesc('version'); }
    public function audits(): HasMany { return $this->hasMany(ProjectBudgetAudit::class)->orderByDesc('created_at'); }
}
