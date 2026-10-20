<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id', 'section_label', 'sort_order',
        'product_id', 'description', 'qty', 'unit',
        'unit_price', 'subtotal', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty'        => 'decimal:4',
            'unit_price' => 'decimal:2',
            'subtotal'   => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
