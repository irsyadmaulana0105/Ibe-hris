<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuotationTemplate extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'category', 'description',
        'items_template', 'default_terms', 'is_active',
        'usage_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'items_template' => 'array',
            'default_terms'  => 'array',
            'is_active'      => 'boolean',
        ];
    }
}
