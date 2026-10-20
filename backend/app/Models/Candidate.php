<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candidate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'job_posting_id', 'name', 'email', 'phone',
        'cv_url', 'source', 'stage', 'notes', 'applied_at',
    ];

    protected $hidden = ['cv_url'];

    protected function casts(): array
    {
        return ['applied_at' => 'date'];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
