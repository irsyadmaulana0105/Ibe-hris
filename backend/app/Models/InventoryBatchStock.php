<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatchStock extends Model
{
    use BelongsToCompany;

    protected $table = 'inventory_batch_stock';

    protected $fillable = [
        'company_id', 'batch_id', 'warehouse_id',
        'qty_on_hand', 'qty_reserved', 'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand'      => 'decimal:4',
            'qty_reserved'     => 'decimal:4',
            'last_movement_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }
}
