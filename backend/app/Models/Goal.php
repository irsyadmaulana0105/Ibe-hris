<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'status',
        'type',
        'focus_area',
        'parent_goal_id',
        'owner_id',
        'progress_metric',
        'progress_value',
        'start_date',
        'end_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date'     => 'date',
            'end_date'       => 'date',
            'progress_value' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'parent_goal_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Goal::class, 'parent_goal_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
