<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use BelongsToCompany;

    public $timestamps = false; // only created_at

    protected $fillable = [
        'company_id', 'product_id', 'batch_id', 'warehouse_id',
        'qty_delta', 'qty_before', 'qty_after', 'unit_cost',
        'reason', 'reference_type', 'reference_id', 'notes', 'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'qty_delta'  => 'decimal:2',
            'qty_before' => 'decimal:2',
            'qty_after'  => 'decimal:2',
            'unit_cost'  => 'decimal:2',
            'created_at' => 'datetime',
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

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
