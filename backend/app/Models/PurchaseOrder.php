<?php

namespace App\Models;

use App\Keuangan\Models\Supplier;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id', 'po_number', 'supplier_id', 'warehouse_id',
        'date', 'expected_delivery_date', 'status',
        'subtotal', 'tax_rate', 'tax_amount', 'total', 'currency',
        'terms', 'notes', 'fin_bill_id', 'created_by',
        'approved_by', 'approved_at', 'sent_at', 'received_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'date'                    => 'date',
            'expected_delivery_date'  => 'date',
            'approved_at'             => 'datetime',
            'sent_at'                 => 'datetime',
            'received_at'             => 'datetime',
            'closed_at'               => 'datetime',
            'subtotal'                => 'decimal:2',
            'tax_rate'                => 'decimal:2',
            'tax_amount'              => 'decimal:2',
            'total'                   => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }
}
