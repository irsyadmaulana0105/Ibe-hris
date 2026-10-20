<?php

namespace App\Surat\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LetterCategory extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'code', 'name', 'description',
        'numbering_format', 'last_seq_year', 'last_seq_value',
        'active', 'display_order',
    ];

    protected function casts(): array
    {
        return [
            'active'         => 'boolean',
            'last_seq_year'  => 'integer',
            'last_seq_value' => 'integer',
            'display_order'  => 'integer',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(LetterTemplate::class, 'category_id');
    }

    public function letters(): HasMany
    {
        return $this->hasMany(Letter::class, 'category_id');
    }
}
