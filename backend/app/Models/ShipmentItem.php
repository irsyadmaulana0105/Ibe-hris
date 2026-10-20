<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentItem extends Model
{
    protected $fillable = [
        'shipment_id', 'product_id', 'product_serial', 'batch_id',
        'product_code', 'product_name', 'qty', 'unit_of_measure', 'notes',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
