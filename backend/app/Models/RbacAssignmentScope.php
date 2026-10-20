<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RbacAssignmentScope extends Model
{
    protected $fillable = ['assignment_id', 'scope_type', 'scope_id'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(RbacUserAssignment::class, 'assignment_id');
    }
}
