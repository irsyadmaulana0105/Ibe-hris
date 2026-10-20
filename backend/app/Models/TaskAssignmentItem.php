<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignmentItem extends Model
{
    use HasFactory;

    protected $fillable = ['task_assignment_id', 'user_id', 'role'];

    public function taskAssignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
