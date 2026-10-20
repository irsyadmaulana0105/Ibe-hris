<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecField extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'product_id', 'field_key', 'label', 'field_type',
        'unit', 'options', 'is_required', 'show_on_nameplate', 'sort_order', 'default_value',
    ];

    protected function casts(): array
    {
        return [
            'options'           => 'array',
            'is_required'       => 'boolean',
            'show_on_nameplate' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
