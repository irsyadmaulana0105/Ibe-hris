<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectNotification extends Model
{
    use HasFactory;

    protected $table = 'project_notifications';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'actor_id',
        'type',
        'title',
        'body',
        'task_id',
        'project_id',
        'read_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
