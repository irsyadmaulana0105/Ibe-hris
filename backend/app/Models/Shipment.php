<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'do_number', 'customer_id', 'customer_name', 'shipping_address',
        'source_warehouse_id', 'ship_date', 'expected_delivery_date', 'actual_delivery_date',
        'carrier', 'tracking_number', 'shipping_cost', 'status',
        'packed_by', 'shipped_by', 'received_by_name',
        'packed_at', 'shipped_at', 'delivered_at',
        'invoice_id', 'notes', 'created_by',
        'sales_order_id', 'fleet_id', 'delivery_route', 'pod_file_path', 'pod_signature_path',
        'pod_notes', 'pod_latitude', 'pod_longitude', 'pod_receiver_phone',
    ];

    protected $hidden = ['pod_file_path', 'pod_signature_path'];

    protected function casts(): array
    {
        return [
            'ship_date' => 'date',
            'expected_delivery_date' => 'date',
            'actual_delivery_date' => 'date',
            'packed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'shipping_cost' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'source_warehouse_id');
    }
}
