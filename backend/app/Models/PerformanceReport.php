<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReport extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'user_id', 'date',
        'performance_type_id', 'score', 'running_score',
        'reference_type', 'reference_id',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function performanceType(): BelongsTo
    {
        return $this->belongsTo(PerformanceType::class);
    }
}
