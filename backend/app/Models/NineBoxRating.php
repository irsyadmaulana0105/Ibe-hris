<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NineBoxRating extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'performance', 'potential', 'notes', 'rated_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_by');
    }
}
