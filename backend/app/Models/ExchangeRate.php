<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'from_currency', 'to_currency', 'rate',
        'valid_from', 'source', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate'       => 'decimal:8',
            'valid_from' => 'date',
        ];
    }
}
