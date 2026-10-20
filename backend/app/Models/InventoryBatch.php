<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBatch extends Model
{
    use BelongsToCompany;

    protected $table = 'inventory_batches';

    protected $fillable = [
        'company_id', 'product_id', 'batch_code',
        'received_at', 'expires_at', 'manufactured_at',
        'supplier_lot', 'source_po_id', 'unit_cost', 'notes', 'is_quarantined',
    ];

    protected function casts(): array
    {
        return [
            'received_at'    => 'datetime',
            'expires_at'     => 'date',
            'manufactured_at' => 'date',
            'unit_cost'      => 'decimal:4',
            'is_quarantined' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryBatchStock::class, 'batch_id');
    }

    /** Total qty across all warehouses */
    public function totalOnHand(): float
    {
        return (float) $this->stocks()->sum('qty_on_hand');
    }
}
