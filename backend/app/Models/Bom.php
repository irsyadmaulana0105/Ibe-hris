<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bom extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'boms';

    protected $fillable = [
        'company_id', 'product_id', 'version', 'name', 'is_active',
        'total_cost_cached', 'labor_cost', 'overhead_cost', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active'         => 'boolean',
            'total_cost_cached' => 'decimal:2',
            'labor_cost'        => 'decimal:2',
            'overhead_cost'     => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(BomComponent::class)->orderBy('sort_order');
    }
}
