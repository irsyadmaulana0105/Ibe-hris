<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'stock_count_id', 'product_id',
        'system_qty', 'counted_qty', 'status',
        'counter_notes', 'approver_notes', 'counted_by', 'counted_at',
    ];

    protected function casts(): array
    {
        return [
            'system_qty'   => 'decimal:4',
            'counted_qty'  => 'decimal:4',
            'variance_qty' => 'decimal:4',
            'counted_at'   => 'datetime',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
