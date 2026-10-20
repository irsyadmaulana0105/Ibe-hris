<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStock extends Model
{
    use BelongsToCompany;

    protected $table = 'inventory_stock';

    protected $fillable = [
        'company_id', 'product_id', 'warehouse_id',
        'qty_on_hand', 'qty_reserved', 'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand'      => 'decimal:2',
            'qty_reserved'     => 'decimal:2',
            'qty_available'    => 'decimal:2',
            'last_movement_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }
}
