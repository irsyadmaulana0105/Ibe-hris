<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'course_id', 'user_id', 'progress',
        'status', 'enrolled_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['enrolled_at' => 'date', 'completed_at' => 'date', 'progress' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
