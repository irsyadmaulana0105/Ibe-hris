<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomComponent extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bom_id', 'component_product_id',
        'qty_required', 'unit_of_measure', 'unit_cost_snapshot',
        'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'qty_required'        => 'decimal:4',
            'unit_cost_snapshot'  => 'decimal:2',
        ];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
