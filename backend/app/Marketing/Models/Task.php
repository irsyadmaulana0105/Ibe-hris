<?php

namespace App\Marketing\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    use BelongsToCompany;

    protected $table = 'crm_tasks';

    protected $fillable = [
        'company_id', 'title', 'description', 'type',
        'subjectable_type', 'subjectable_id', 'assignee_id',
        'due_date', 'due_time', 'priority', 'status', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function subjectable(): MorphTo
    {
        return $this->morphTo();
    }
}
