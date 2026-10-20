<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectBudgetAudit extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'project_budget_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'reason', 'actor_id'];
    protected function casts(): array { return ['before' => 'array', 'after' => 'array']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
