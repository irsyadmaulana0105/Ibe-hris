<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryLetter extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'user_id', 'document_name', 'expiry_date', 'file_path'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date->isPast();
    }
}
