<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'user_id', 'cycle', 'stage',
        'self_score', 'manager_score', 'final_score',
        'self_comments', 'manager_comments', 'reviewer_id',
    ];

    protected function casts(): array
    {
        return [
            'self_score' => 'decimal:2',
            'manager_score' => 'decimal:2',
            'final_score' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
