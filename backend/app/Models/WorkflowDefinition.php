<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowDefinition extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'key', 'name'];

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('sort_order');
    }
}
