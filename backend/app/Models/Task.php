<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'sprint_id',
        'goal_id',
        'parent_task_id',
        'title',
        'description',
        'type',
        'status',
        'priority',
        'story_points',
        'time_estimate',
        'time_spent',
        'assigned_to',
        'start_date',
        'due_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'story_points' => 'integer',
            'time_estimate' => 'integer',
            'time_spent' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'task_label');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->orderByDesc('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class)->orderByDesc('created_at');
    }

    /** Tasks this one depends on (must be completed first). */
    public function dependsOn(): BelongsToMany
    {
        return $this->belongsToMany(
            Task::class,
            'task_dependencies',
            'task_id',
            'depends_on_task_id',
        );
    }

    /** Tasks blocked by this one (the inverse). */
    public function blocking(): BelongsToMany
    {
        return $this->belongsToMany(
            Task::class,
            'task_dependencies',
            'depends_on_task_id',
            'task_id',
        );
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
