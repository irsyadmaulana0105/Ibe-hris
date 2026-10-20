<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'wo_number', 'product_id', 'bom_id', 'bom_version',
        'qty_planned', 'qty_produced',
        'source_warehouse_id', 'output_warehouse_id', 'status',
        'planned_start_date', 'planned_complete_date',
        'released_at', 'completed_at',
        'material_cost_planned', 'labor_cost_planned', 'overhead_cost_planned', 'total_cost_planned',
        'notes', 'source_type', 'source_id',
        'created_by', 'released_by', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_start_date'      => 'date',
            'planned_complete_date'   => 'date',
            'released_at'             => 'datetime',
            'completed_at'            => 'datetime',
            'material_cost_planned'   => 'decimal:2',
            'labor_cost_planned'      => 'decimal:2',
            'overhead_cost_planned'   => 'decimal:2',
            'total_cost_planned'      => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'source_warehouse_id');
    }

    public function outputWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'output_warehouse_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(WorkOrderComponent::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(WorkOrderOutput::class);
    }
}
