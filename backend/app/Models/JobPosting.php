<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'title', 'division', 'location', 'type',
        'description', 'salary_range', 'status', 'posted_at', 'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'date', 'closed_at' => 'date'];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }
}
