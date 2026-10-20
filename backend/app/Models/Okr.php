<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Okr extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'objective', 'metric', 'target', 'progress', 'due', 'status'];

    protected function casts(): array
    {
        return ['due' => 'date', 'target' => 'decimal:2', 'progress' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
