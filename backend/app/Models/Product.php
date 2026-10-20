<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'code', 'name', 'category', 'model_number', 'description',
        'unit_of_measure', 'list_price', 'image_path', 'default_spec_values', 'is_active',
        // Inventory fields (added by migration 2026_05_22_200002):
        'product_type', 'track_stock', 'unit_cost',
        'reorder_point', 'safety_stock', 'total_stock_cached',
        // Batch tracking fields (migration 2026_05_30_000004):
        'track_batch', 'batch_strategy', 'has_expiry',
    ];

    protected function casts(): array
    {
        return [
            'default_spec_values' => 'array',
            'is_active'           => 'boolean',
            'track_stock'         => 'boolean',
            'track_batch'         => 'boolean',
            'has_expiry'          => 'boolean',
            'list_price'          => 'decimal:2',
            'unit_cost'           => 'decimal:2',
            'total_stock_cached'  => 'decimal:2',
        ];
    }

    public function specFields(): HasMany
    {
        return $this->hasMany(ProductSpecField::class)->orderBy('sort_order');
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(ProductSerialNumber::class);
    }
}
