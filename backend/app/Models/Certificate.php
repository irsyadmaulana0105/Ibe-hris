<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'user_id', 'course_id', 'name',
        'issuer', 'issued_at', 'expires_at', 'file_url',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'expires_at' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
