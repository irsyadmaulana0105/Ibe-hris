<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id',
        'qty_ordered', 'qty_received', 'unit_of_measure', 'unit_cost', 'subtotal',
        'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'qty_ordered'  => 'decimal:2',
            'qty_received' => 'decimal:2',
            'unit_cost'    => 'decimal:2',
            'subtotal'     => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
