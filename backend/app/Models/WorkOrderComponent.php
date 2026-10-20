<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderComponent extends Model
{
    protected $fillable = [
        'work_order_id', 'component_product_id',
        'qty_per_unit', 'qty_total_planned', 'qty_consumed', 'unit_cost_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'qty_per_unit'       => 'decimal:4',
            'qty_total_planned'  => 'decimal:4',
            'qty_consumed'       => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:2',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
